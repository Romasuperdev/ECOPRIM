<?php

namespace App\Services\Appel;

use App\Models\RhUser;
use App\Services\AbsenceEcrivain;
use App\Services\Notifications\Notifieur;
use App\Support\AnneeScolaireGuard;
use App\Support\PorteeEnseignant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Justificatifs d'absence : le parent envoie un mot et/ou une photo, l'enseignant de la
 * classe ou l'administration tranche, la décision est reportée dans ECONOMAT
 * (T_ABSENCEELEVE.Justifier).
 *
 * Les fichiers vivent hors du dossier public (storage/app/justificatifs) : un certificat
 * médical ne se télécharge que par l'API, après contrôle du droit de le voir.
 */
class JustificatifService
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    public const TAILLE_MAX_KO = 8192;

    public function __construct(
        private AbsenceEcrivain $absences,
        private Notifieur $notifieur,
    ) {}

    public function dossier(): string
    {
        return storage_path('app/justificatifs');
    }

    /** Un parent justifie l'absence de son enfant. L'appelant a vérifié qu'il en est le parent. */
    public function deposer(RhUser $parent, string $matricule, int $absenceCode, ?string $message, ?UploadedFile $fichier): object
    {
        $absence = $this->absences->trouver($absenceCode);
        if (! $absence || trim((string) $absence->Matricule) !== $matricule) {
            abort(404, 'Absence introuvable.');
        }
        AnneeScolaireGuard::assertModifiable($absence->AnneeCour ?? null, 'La justification de cette absence');

        if ((bool) $absence->Justifier) {
            throw ValidationException::withMessages(['absence' => ['Cette absence est déjà justifiée.']]);
        }
        if (DB::connection('ecoprim')->table('absence_justificatifs')
            ->where('absence_code', $absenceCode)->where('statut', 'en_attente')->exists()) {
            throw ValidationException::withMessages(['absence' => ['Un justificatif est déjà en attente pour cette absence.']]);
        }
        if (trim((string) $message) === '' && ! $fichier) {
            throw ValidationException::withMessages(['message' => ['Écrivez un mot ou joignez une photo.']]);
        }

        $chemin = null;
        if ($fichier) {
            $extension = strtolower($fichier->getClientOriginalExtension() ?: $fichier->extension() ?: 'jpg');
            $chemin = now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
            File::ensureDirectoryExists(dirname($this->dossier().'/'.$chemin));
            $fichier->move(dirname($this->dossier().'/'.$chemin), basename($chemin));
        }

        $maintenant = now();
        $id = DB::connection('ecoprim')->table('absence_justificatifs')->insertGetId([
            'absence_code' => $absenceCode,
            'matricule' => $matricule,
            'classe_code' => trim((string) $absence->CodeClasse) ?: null,
            'date_absence' => $absence->Date ? Carbon::parse($absence->Date)->toDateString() : null,
            'rh_user_id' => $parent->Id,
            'message' => $message !== null ? mb_substr(trim($message), 0, 1000) : null,
            'fichier' => $chemin,
            'fichier_nom' => $fichier ? mb_substr($fichier->getClientOriginalName(), 0, 255) : null,
            'fichier_type' => $fichier ? mb_substr((string) $fichier->getClientMimeType(), 0, 100) : null,
            'statut' => 'en_attente',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);

        $justificatif = $this->trouver($id);

        if ($justificatif->classe_code) {
            $eleve = $this->nomEleve($matricule);
            $this->notifieur->envoyer(
                PorteeEnseignant::comptesDeLaClasse($justificatif->classe_code),
                'justificatif',
                "Justificatif reçu : {$eleve}",
                'Absence du '.$this->dateLisible($justificatif->date_absence).'. À examiner.',
                '/mon-espace/justificatifs',
                ['justificatif' => $id, 'matricule' => $matricule],
            );
        }

        return $justificatif;
    }

    /** Décision de l'enseignant ou de l'administration ; reportée dans ECONOMAT, parent prévenu. */
    public function decider(RhUser $auteur, int $id, bool $justifiee, ?string $commentaire): object
    {
        $justificatif = $this->trouver($id);
        abort_unless($justificatif, 404, 'Justificatif introuvable.');
        if ($justificatif->statut !== 'en_attente') {
            throw ValidationException::withMessages(['decision' => ['Ce justificatif a déjà été traité.']]);
        }

        $absence = $this->absences->trouver((int) $justificatif->absence_code);
        abort_unless($absence, 404, "L'absence correspondante n'existe plus.");
        AnneeScolaireGuard::assertModifiable($absence->AnneeCour ?? null, 'La décision sur cette absence');

        $this->absences->modifier((int) $justificatif->absence_code, ['justifiee' => $justifiee]);

        DB::connection('ecoprim')->table('absence_justificatifs')->where('id', $id)->update([
            'statut' => $justifiee ? 'justifiee' : 'non_justifiee',
            'decision_par' => $auteur->Id,
            'decision_par_nom' => mb_substr(trim(($auteur->Prenom ?? '').' '.($auteur->Nom ?? '')) ?: (string) $auteur->Login, 0, 150),
            'decision_le' => now(),
            'commentaire' => $commentaire !== null ? mb_substr(trim($commentaire), 0, 500) : null,
            'updated_at' => now(),
        ]);

        $eleve = $this->nomEleve($justificatif->matricule);
        $date = $this->dateLisible($justificatif->date_absence);
        $this->notifieur->envoyer(
            [(int) $justificatif->rh_user_id],
            'absence',
            $justifiee ? "Absence justifiée : {$eleve}" : "Justificatif non accepté : {$eleve}",
            ($justifiee ? "L'absence du {$date} est enregistrée comme justifiée." : "L'absence du {$date} reste non justifiée.")
                .(trim((string) $commentaire) !== '' ? ' '.trim($commentaire) : ''),
            '/mon-espace/enfants/'.rawurlencode($justificatif->matricule).'?onglet=absences',
            ['justificatif' => $id],
        );

        return $this->trouver($id);
    }

    public function trouver(int $id): ?object
    {
        return DB::connection('ecoprim')->table('absence_justificatifs')->find($id);
    }

    /** @param string[]|null $classes  null = toutes (l'appelant a déjà borné au périmètre) */
    public function lister(?array $classes, ?string $statut = 'en_attente'): Collection
    {
        if ($classes === []) {
            return collect();
        }

        $lignes = DB::connection('ecoprim')->table('absence_justificatifs')
            ->when($classes !== null, fn ($q) => $q->whereIn('classe_code', $classes))
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $noms = $this->nomsEleves($lignes->pluck('matricule')->unique()->all());

        return $lignes->map(fn ($j) => $this->forme($j) + ['eleve' => $noms[$j->matricule] ?? $j->matricule]);
    }

    public function forme(object $j): array
    {
        return [
            'id' => (int) $j->id,
            'absence_code' => (int) $j->absence_code,
            'matricule' => $j->matricule,
            'classe' => $j->classe_code,
            'date_absence' => $j->date_absence ? Carbon::parse($j->date_absence)->toDateString() : null,
            'message' => $j->message,
            'a_fichier' => (bool) $j->fichier,
            'fichier_nom' => $j->fichier_nom,
            'fichier_type' => $j->fichier_type,
            'statut' => $j->statut,
            'decision_par' => $j->decision_par_nom,
            'decision_le' => $j->decision_le,
            'commentaire' => $j->commentaire,
            'envoye_le' => $j->created_at,
        ];
    }

    /** Chemin du fichier joint, ou 404. L'appelant a vérifié le droit de le voir. */
    public function cheminFichier(object $justificatif): string
    {
        $chemin = $justificatif->fichier ? $this->dossier().'/'.$justificatif->fichier : null;
        abort_unless($chemin && File::exists($chemin), 404, 'Aucun fichier joint.');

        return $chemin;
    }

    private function nomEleve(string $matricule): string
    {
        return $this->nomsEleves([$matricule])[$matricule] ?? $matricule;
    }

    private function nomsEleves(array $matricules): array
    {
        if ($matricules === []) {
            return [];
        }

        return DB::connection('economat')->table('T_ETUDIANT')
            ->whereIn('Matricule', $matricules)
            ->get(['Matricule', 'Nom', 'Prenom'])
            ->mapWithKeys(fn ($e) => [$e->Matricule => trim("{$e->Prenom} {$e->Nom}") ?: $e->Matricule])
            ->all();
    }

    private function dateLisible(?string $date): string
    {
        return $date ? Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM') : '—';
    }
}
