<?php

namespace App\Services\Appel;

use App\Models\RhUser;
use App\Services\AbsenceEcrivain;
use App\Services\Notifications\Notifieur;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'appel d'une classe : lire la liste, l'enregistrer, la corriger.
 *
 * RÈGLES
 *   - Un appel = une classe, un jour, un créneau (matin ou après-midi).
 *   - Chaque ABSENT devient une absence ECONOMAT (T_ABSENCEELEVE, Heure = « Matin » ou
 *     « Après-midi »), écrite par AbsenceEcrivain comme depuis l'écran du secrétariat.
 *     Si le secrétariat l'avait déjà saisie, elle est reprise, pas dupliquée.
 *   - Un RETARD reste dans NEXORA (appel_lignes) : ECONOMAT n'a pas de table pour cela, et
 *     le compter comme absence fausserait ses relevés.
 *   - Corriger un appel retire les absences que CET appel avait créées pour les élèves
 *     redevenus présents — jamais une absence saisie ailleurs.
 *   - Renvoyer le même envoi (même identifiant, cas d'un appel fait hors connexion et
 *     réexpédié) ne refait rien.
 *   - Les parents sont prévenus des NOUVEAUX absents et retards seulement : corriger un
 *     appel ne renvoie pas une notification à ceux déjà prévenus.
 */
class AppelService
{
    public const CRENEAUX = ['matin' => 'Matin', 'apres-midi' => 'Après-midi'];

    public function __construct(
        private AbsenceEcrivain $absences,
        private Notifieur $notifieur,
    ) {}

    /** La feuille d'appel : élèves de la classe et statut de chacun pour ce créneau. */
    public function feuille(string $classe, string $date, string $creneau): array
    {
        $eleves = $this->eleves($classe);
        $appel = $this->appel($classe, $date, $creneau);
        $lignes = $appel
            ? DB::connection('ecoprim')->table('appel_lignes')->where('appel_id', $appel->id)->get()->keyBy('matricule')
            : collect();

        // Sans appel encore, une absence déjà saisie par le secrétariat pour ce créneau
        // apparaît cochée : l'enseignant la voit et ne la ressaisit pas.
        $dejaSaisies = $appel ? collect() : $this->absencesDuCreneau($classe, $date, $creneau)->keyBy('Matricule');

        return [
            'classe' => $classe,
            'classe_libelle' => $this->libelleClasse($classe),
            'date' => $date,
            'creneau' => $creneau,
            'appel' => $appel ? [
                'valide_le' => $appel->valide_le,
                'auteur' => $appel->auteur,
                'absents' => (int) $appel->absents,
                'retards' => (int) $appel->retards,
            ] : null,
            'eleves' => $eleves->map(fn ($e) => [
                'matricule' => $e->Matricule,
                'nom' => $e->Nom,
                'prenom' => $e->Prenom,
                'sexe' => $e->Sexe,
                'statut' => $lignes[$e->Matricule]->statut
                    ?? (isset($dejaSaisies[$e->Matricule]) ? 'absent' : 'present'),
            ])->values(),
        ];
    }

    /**
     * @param  string[]  $absents
     * @param  string[]  $retards
     */
    public function enregistrer(RhUser $auteur, string $classe, string $date, string $creneau,
        array $absents, array $retards, ?string $envoi = null): array
    {
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "L'appel");

        $absents = array_values(array_unique(array_map('trim', $absents)));
        $retards = array_values(array_diff(array_unique(array_map('trim', $retards)), $absents));

        $eleves = $this->eleves($classe)->keyBy('Matricule');
        $inconnus = array_diff([...$absents, ...$retards], $eleves->keys()->all());
        if ($inconnus !== []) {
            throw ValidationException::withMessages([
                'absents' => ['Ces élèves ne sont pas dans la classe : '.implode(', ', $inconnus).'.'],
            ]);
        }

        $appel = $this->appel($classe, $date, $creneau);
        if ($appel && $envoi && $appel->dernier_envoi === $envoi) {
            return $this->bilan($appel, 0, true); // déjà reçu : renvoi d'un appel hors connexion
        }

        $ecoprim = DB::connection('ecoprim');
        $maintenant = now();
        if (! $appel) {
            $id = $ecoprim->table('appels')->insertGetId([
                'classe_code' => $classe, 'date' => $date, 'creneau' => $creneau,
                'annee' => $annee, 'etablissement_code' => PerimetreEtablissement::code(),
                'created_at' => $maintenant, 'updated_at' => $maintenant,
            ]);
            $appel = $ecoprim->table('appels')->find($id);
        }

        $existantes = $ecoprim->table('appel_lignes')->where('appel_id', $appel->id)->get()->keyBy('matricule');
        $aPrevenir = [];

        // 1. Les élèves qui ne sont plus ni absents ni retardataires.
        foreach ($existantes as $matricule => $ligne) {
            if (! in_array($matricule, $absents, true) && ! in_array($matricule, $retards, true)) {
                $this->retirerAbsence($ligne);
                $ecoprim->table('appel_lignes')->where('id', $ligne->id)->delete();
            }
        }

        // 2. Les absents.
        foreach ($absents as $matricule) {
            $ligne = $existantes[$matricule] ?? null;
            if ($ligne && $ligne->statut === 'absent') {
                continue;
            }
            [$code, $creee] = $this->absenceEcrite($eleves[$matricule], $classe, $date, $creneau, $annee);
            $valeurs = ['statut' => 'absent', 'absence_code' => $code, 'absence_creee' => $creee, 'updated_at' => $maintenant];
            if ($ligne) {
                $ecoprim->table('appel_lignes')->where('id', $ligne->id)->update($valeurs);
            } else {
                $ecoprim->table('appel_lignes')->insert($valeurs + [
                    'appel_id' => $appel->id, 'matricule' => $matricule, 'created_at' => $maintenant,
                ]);
            }
            $aPrevenir[$matricule] = ['absent', $code];
        }

        // 3. Les retardataires (un absent devenu retardataire perd l'absence créée par l'appel).
        foreach ($retards as $matricule) {
            $ligne = $existantes[$matricule] ?? null;
            if ($ligne && $ligne->statut === 'retard') {
                continue;
            }
            if ($ligne) {
                $this->retirerAbsence($ligne);
                $ecoprim->table('appel_lignes')->where('id', $ligne->id)->update([
                    'statut' => 'retard', 'absence_code' => null, 'absence_creee' => false, 'updated_at' => $maintenant,
                ]);
            } else {
                $ecoprim->table('appel_lignes')->insert([
                    'appel_id' => $appel->id, 'matricule' => $matricule, 'statut' => 'retard',
                    'created_at' => $maintenant, 'updated_at' => $maintenant,
                ]);
            }
            $aPrevenir[$matricule] = ['retard', null];
        }

        $ecoprim->table('appels')->where('id', $appel->id)->update([
            'rh_user_id' => $auteur->Id,
            'auteur' => mb_substr(trim(($auteur->Prenom ?? '').' '.($auteur->Nom ?? '')) ?: (string) $auteur->Login, 0, 150),
            'effectif' => $eleves->count(),
            'absents' => count($absents),
            'retards' => count($retards),
            'dernier_envoi' => $envoi,
            'valide_le' => $maintenant,
            'updated_at' => $maintenant,
        ]);

        $prevenus = $this->prevenirParents($appel->id, $aPrevenir, $eleves, $classe, $date, $creneau);

        return $this->bilan($ecoprim->table('appels')->find($appel->id), $prevenus, false);
    }

    private function bilan(object $appel, int $parentsPrevenus, bool $dejaRecu): array
    {
        return [
            'classe' => $appel->classe_code,
            'date' => Carbon::parse($appel->date)->toDateString(),
            'creneau' => $appel->creneau,
            'effectif' => (int) $appel->effectif,
            'absents' => (int) $appel->absents,
            'retards' => (int) $appel->retards,
            'valide_le' => $appel->valide_le,
            'parents_prevenus' => $parentsPrevenus,
            'deja_recu' => $dejaRecu,
        ];
    }

    /** L'absence ECONOMAT de cet élève pour ce créneau : reprise si elle existe, sinon créée. */
    private function absenceEcrite(object $eleve, string $classe, string $date, string $creneau, string $annee): array
    {
        $heure = self::CRENEAUX[$creneau];
        $existante = $this->absences->requete()
            ->where('Matricule', $eleve->Matricule)
            ->whereDate('Date', $date)
            ->where('Heure', $heure)
            ->value('Code');

        if ($existante) {
            return [(int) $existante, false];
        }

        $code = $this->absences->creer([
            'matricule' => $eleve->Matricule,
            'eleve_code' => $eleve->Code,
            'classe' => $classe,
            'date' => $date,
            'heure' => $heure,
            'justifiee' => false,
            'annee' => $annee,
        ]);

        return [$code, true];
    }

    /** Retire l'absence ECONOMAT d'une ligne d'appel — seulement si c'est l'appel qui l'a créée. */
    private function retirerAbsence(object $ligne): void
    {
        if ($ligne->statut !== 'absent' || ! $ligne->absence_creee || ! $ligne->absence_code) {
            return;
        }

        $this->absences->supprimer((int) $ligne->absence_code);
        // Un justificatif en attente pour une absence qui n'existe plus n'a plus d'objet.
        DB::connection('ecoprim')->table('absence_justificatifs')
            ->where('absence_code', $ligne->absence_code)
            ->where('statut', 'en_attente')
            ->update(['statut' => 'annule', 'updated_at' => now()]);
    }

    /** @return int nombre de comptes parents prévenus */
    private function prevenirParents(int $appelId, array $aPrevenir, Collection $eleves, string $classe,
        string $date, string $creneau): int
    {
        $prevenus = [];
        $moment = $this->moment($date, $creneau);

        foreach ($aPrevenir as $matricule => [$statut, $absenceCode]) {
            $parents = $this->notifieur->parentsDesEleves([$matricule]);
            if ($parents === []) {
                continue;
            }

            $eleve = $eleves[$matricule];
            $prenom = trim((string) $eleve->Prenom) ?: trim((string) $eleve->Nom);
            $nomComplet = trim("{$eleve->Prenom} {$eleve->Nom}");

            if ($statut === 'absent') {
                $titre = "Absence de {$prenom}";
                $corps = "{$nomComplet} n'est pas en classe {$moment}. Vous pouvez envoyer un justificatif depuis l'application.";
            } else {
                $titre = "Retard de {$prenom}";
                $corps = "{$nomComplet} est arrivé(e) en retard en classe {$moment}.";
            }

            $this->notifieur->envoyer($parents, $statut === 'absent' ? 'absence' : 'retard', $titre, $corps,
                '/mon-espace/enfants/'.rawurlencode($matricule).'?onglet=absences',
                array_filter(['matricule' => $matricule, 'absence_code' => $absenceCode, 'classe' => $classe]));

            DB::connection('ecoprim')->table('appel_lignes')
                ->where('appel_id', $appelId)->where('matricule', $matricule)
                ->update(['notifie_le' => now()]);

            array_push($prevenus, ...$parents);
        }

        return count(array_unique($prevenus));
    }

    /** « ce matin », « cet après-midi », ou « le mardi 24 novembre (matin) ». */
    private function moment(string $date, string $creneau): string
    {
        $jour = Carbon::parse($date);
        if ($jour->isToday()) {
            return $creneau === 'matin' ? 'ce matin' : 'cet après-midi';
        }

        return 'le '.$jour->locale('fr')->isoFormat('dddd D MMMM').' ('.mb_strtolower(self::CRENEAUX[$creneau]).')';
    }

    private function eleves(string $classe): Collection
    {
        return ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $classe),
            'AnneeAcad'
        )->orderBy('Nom')->orderBy('Prenom')
            ->get(['Code', 'Matricule', 'Nom', 'Prenom', 'Sexe'])
            ->unique('Matricule')
            ->values();
    }

    private function appel(string $classe, string $date, string $creneau): ?object
    {
        return DB::connection('ecoprim')->table('appels')
            ->where('classe_code', $classe)->whereDate('date', $date)->where('creneau', $creneau)
            ->first();
    }

    private function absencesDuCreneau(string $classe, string $date, string $creneau): Collection
    {
        return $this->absences->requete()
            ->where('CodeClasse', $classe)
            ->whereDate('Date', $date)
            ->where('Heure', self::CRENEAUX[$creneau])
            ->get(['Matricule']);
    }

    private function libelleClasse(string $classe): string
    {
        $libelle = DB::connection('economat')->table('T_CLASSE')->where('CodeClasse', $classe)->value('LibelleClasse');

        return $libelle ? trim((string) $libelle) : $classe;
    }
}
