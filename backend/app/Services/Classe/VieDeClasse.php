<?php

namespace App\Services\Classe;

use App\Models\Devoir;
use App\Models\RhUser;
use App\Services\Notifications\Notifieur;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PiecesJointes;
use App\Support\PorteeEnseignant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Devoirs et annonces d'une classe, depuis l'espace Enseignant, lus par les parents.
 *
 *   - Un DEVOIR (table devoirs, déjà lue par les deux espaces) peut porter des pièces
 *     jointes : la photo du tableau ou d'une fiche, prise au téléphone (devoir_pieces).
 *   - Une ANNONCE s'adresse aux parents d'UNE classe, avec une photo facultative.
 *
 * Chaque publication prévient les parents rattachés aux élèves de la classe. Un
 * enseignant ne modifie ou ne retire que ce qu'il a lui-même publié.
 */
class VieDeClasse
{
    public const DOSSIER_DEVOIRS = 'devoirs';

    public const DOSSIER_ANNONCES = 'annonces';

    public const PIECES_MAX = 4;

    public function __construct(private Notifieur $notifieur) {}

    // --- Devoirs ---------------------------------------------------------------------

    /** @return array<int, array> devoirs d'une ou plusieurs classes, avec leurs pièces */
    public function devoirs(array $classes, ?RhUser $enseignant = null): array
    {
        if ($classes === []) {
            return [];
        }

        $devoirs = Devoir::query()
            ->where('annee', ContexteScolaire::annee())
            ->whereIn('classe_code', $classes)
            ->orderByDesc('date_remise')->orderByDesc('id')
            ->get();

        $pieces = DB::connection('ecoprim')->table('devoir_pieces')
            ->whereIn('devoir_id', $devoirs->pluck('id')->all() ?: [0])
            ->orderBy('id')->get()->groupBy('devoir_id');
        $prof = $enseignant ? PorteeEnseignant::professeur($enseignant) : null;
        $matieres = $this->libelles('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $devoirs->pluck('matiere_code')->unique()->all());
        $classesLib = $this->libelles('T_CLASSE', 'CodeClasse', 'LibelleClasse', $devoirs->pluck('classe_code')->unique()->all());

        return $devoirs->map(fn (Devoir $d) => [
            'id' => $d->id,
            'titre' => $d->titre,
            'consigne' => $d->consigne,
            'classe' => $d->classe_code,
            'classe_libelle' => $classesLib[$d->classe_code] ?? $d->classe_code,
            'matiere' => $d->matiere_code,
            'matiere_libelle' => $matieres[$d->matiere_code] ?? $d->matiere_code,
            'date_remise' => optional($d->date_remise)->format('Y-m-d'),
            'mien' => $prof !== null && (int) $d->enseignant_code === (int) $prof->Code,
            'pieces' => ($pieces[$d->id] ?? collect())->map(fn ($p) => [
                'id' => (int) $p->id, 'nom' => $p->fichier_nom, 'type' => $p->fichier_type,
            ])->values(),
        ])->values()->all();
    }

    /** @param UploadedFile[] $fichiers */
    public function creerDevoir(RhUser $enseignant, array $d, array $fichiers): Devoir
    {
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, 'Un nouveau devoir');
        $prof = PorteeEnseignant::professeur($enseignant);

        $devoir = Devoir::create([
            'titre' => mb_substr(trim($d['titre']), 0, 150),
            'consigne' => isset($d['consigne']) ? trim($d['consigne']) : null,
            'classe_code' => $d['classe'],
            'matiere_code' => $d['matiere'],
            'enseignant_code' => $prof?->Code,
            'date_remise' => $d['date_remise'],
            'annee' => $annee,
        ]);

        foreach (array_slice($fichiers, 0, self::PIECES_MAX) as $fichier) {
            DB::connection('ecoprim')->table('devoir_pieces')->insert(
                PiecesJointes::ranger($fichier, self::DOSSIER_DEVOIRS)
                + ['devoir_id' => $devoir->id, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $matiere = $this->libelles('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', [$devoir->matiere_code])[$devoir->matiere_code] ?? $devoir->matiere_code;
        $pour = Carbon::parse($d['date_remise'])->locale('fr')->isoFormat('dddd D MMMM');
        $this->notifieur->envoyerAuxParentsDeLaClasse(
            $devoir->classe_code,
            'devoir',
            "Nouveau devoir en {$matiere}",
            "{$devoir->titre} — pour le {$pour}.".(count($fichiers) ? ' Photo jointe.' : ''),
            'devoirs',
            ['devoir' => $devoir->id],
        );

        return $devoir;
    }

    public function modifierDevoir(Devoir $devoir, array $d): Devoir
    {
        AnneeScolaireGuard::assertModifiable($devoir->annee, 'La modification de ce devoir');
        $devoir->update(array_filter([
            'titre' => isset($d['titre']) ? mb_substr(trim($d['titre']), 0, 150) : null,
            'consigne' => array_key_exists('consigne', $d) ? trim((string) $d['consigne']) : null,
            'date_remise' => $d['date_remise'] ?? null,
        ], fn ($v) => $v !== null));

        return $devoir;
    }

    public function supprimerDevoir(Devoir $devoir): void
    {
        AnneeScolaireGuard::assertModifiable($devoir->annee, 'Le retrait de ce devoir');
        foreach (DB::connection('ecoprim')->table('devoir_pieces')->where('devoir_id', $devoir->id)->get() as $p) {
            PiecesJointes::supprimer(self::DOSSIER_DEVOIRS, $p->fichier);
        }
        DB::connection('ecoprim')->table('devoir_pieces')->where('devoir_id', $devoir->id)->delete();
        $devoir->delete();
    }

    /** L'enseignant connecté a-t-il publié ce devoir ? */
    public function estAuteurDevoir(RhUser $compte, Devoir $devoir): bool
    {
        $prof = PorteeEnseignant::professeur($compte);

        return $prof !== null && (int) $devoir->enseignant_code === (int) $prof->Code;
    }

    public function piece(int $devoirId, int $pieceId): object
    {
        $p = DB::connection('ecoprim')->table('devoir_pieces')->where('devoir_id', $devoirId)->where('id', $pieceId)->first();
        abort_unless($p, 404, 'Pièce jointe introuvable.');

        return $p;
    }

    // --- Annonces --------------------------------------------------------------------

    public function annonces(array $classes, ?RhUser $auteur = null): Collection
    {
        if ($classes === []) {
            return collect();
        }

        return ContexteScolaire::appliquer(
            DB::connection('ecoprim')->table('annonces')->whereIn('classe_code', $classes),
            'annee'
        )->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($a) => $this->formeAnnonce($a, $auteur));
    }

    public function creerAnnonce(RhUser $auteur, array $d, ?UploadedFile $photo): array
    {
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, 'Une annonce');

        $nom = trim(($auteur->Prenom ?? '').' '.($auteur->Nom ?? '')) ?: (string) $auteur->Login;
        $id = DB::connection('ecoprim')->table('annonces')->insertGetId(
            array_merge([
                'classe_code' => $d['classe'],
                'titre' => mb_substr(trim($d['titre']), 0, 150),
                'message' => isset($d['message']) ? trim($d['message']) : null,
                'rh_user_id' => $auteur->Id,
                'auteur' => mb_substr($nom, 0, 150),
                'annee' => $annee,
                'created_at' => now(),
                'updated_at' => now(),
            ], $photo ? array_diff_key(PiecesJointes::ranger($photo, self::DOSSIER_ANNONCES), ['taille' => 0]) : [])
        );

        $classe = $this->libelles('T_CLASSE', 'CodeClasse', 'LibelleClasse', [$d['classe']])[$d['classe']] ?? $d['classe'];
        $prevenus = $this->notifieur->envoyerAuxParentsDeLaClasse(
            $d['classe'],
            'annonce',
            "{$classe} : ".mb_substr(trim($d['titre']), 0, 120),
            isset($d['message']) ? mb_substr(trim($d['message']), 0, 400) : null,
            'devoirs',
            ['annonce' => $id],
        );
        DB::connection('ecoprim')->table('annonces')->where('id', $id)->update(['parents_prevenus' => $prevenus]);

        return $this->formeAnnonce(DB::connection('ecoprim')->table('annonces')->find($id), $auteur);
    }

    public function annonce(int $id): object
    {
        $a = DB::connection('ecoprim')->table('annonces')->find($id);
        abort_unless($a, 404, 'Annonce introuvable.');

        return $a;
    }

    public function supprimerAnnonce(object $annonce): void
    {
        PiecesJointes::supprimer(self::DOSSIER_ANNONCES, $annonce->fichier);
        DB::connection('ecoprim')->table('annonces')->where('id', $annonce->id)->delete();
    }

    private function formeAnnonce(object $a, ?RhUser $lecteur): array
    {
        return [
            'id' => (int) $a->id,
            'classe' => $a->classe_code,
            'titre' => $a->titre,
            'message' => $a->message,
            'auteur' => $a->auteur,
            'a_photo' => (bool) $a->fichier,
            'photo_type' => $a->fichier_type,
            'date' => $a->created_at,
            'parents_prevenus' => (int) $a->parents_prevenus,
            'mienne' => $lecteur !== null && (int) $a->rh_user_id === (int) $lecteur->Id,
        ];
    }

    private function libelles(string $table, string $cle, string $colonne, array $codes): array
    {
        $codes = array_values(array_filter($codes));
        if ($codes === []) {
            return [];
        }

        try {
            return DB::connection('economat')->table($table)->whereIn($cle, $codes)
                ->pluck($colonne, $cle)->map(fn ($l) => trim((string) $l))->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
