<?php

namespace App\Services\Notifications;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le point unique par lequel NEXORA prévient un parent ou un enseignant.
 *
 * Deux temps :
 *   1. la notification est ENREGISTRÉE (table portail_notifications) — c'est elle qui fait
 *      foi : la cloche de l'application la montre, même sans push ;
 *   2. les appareils abonnés sont RÉVEILLÉS par Web Push, après l'envoi de la réponse HTTP,
 *      pour que l'action de l'utilisateur (valider un appel, publier des notes) n'attende
 *      pas les serveurs de Google ou d'Apple.
 *
 * Un échec du push n'empêche jamais l'action d'aboutir ni la notification d'exister.
 */
class Notifieur
{
    public function __construct(private WebPush $push) {}

    /**
     * @param  int[]  $rhUserIds  destinataires (comptes RH_USER)
     * @return int nombre de notifications créées
     */
    public function envoyer(array $rhUserIds, string $type, string $titre, ?string $corps = null,
        ?string $lien = null, array $donnees = []): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $rhUserIds))));
        if ($ids === []) {
            return 0;
        }

        $maintenant = now();
        $lignes = array_map(fn ($id) => [
            'rh_user_id' => $id,
            'type' => mb_substr($type, 0, 30),
            'titre' => mb_substr($titre, 0, 150),
            'corps' => $corps !== null ? mb_substr($corps, 0, 500) : null,
            'lien' => $lien !== null ? mb_substr($lien, 0, 255) : null,
            'donnees' => $donnees !== [] ? json_encode($donnees, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ], $ids);

        foreach (array_chunk($lignes, 200) as $lot) {
            DB::connection('ecoprim')->table('portail_notifications')->insert($lot);
        }

        app()->terminating(fn () => $this->reveiller($ids));

        return count($ids);
    }

    /**
     * Les comptes parents rattachés à ces élèves (console_affectation_eleves), affectation
     * active — le chemin inverse de RhUser::enfantsMatricules().
     *
     * @param  string[]  $matricules
     * @return int[]
     */
    public function parentsDesEleves(array $matricules): array
    {
        $matricules = array_values(array_filter(array_map(fn ($m) => trim((string) $m), $matricules)));
        if ($matricules === []) {
            return [];
        }

        return DB::connection('ecoprim')
            ->table('console_affectations as a')
            ->join('console_roles as r', 'r.id', '=', 'a.role_id')
            ->join('console_affectation_eleves as e', 'e.affectation_id', '=', 'a.id')
            ->whereIn('e.eleve_matricule', $matricules)
            ->where('a.actif', true)
            ->where(fn ($q) => $q->whereNull('a.date_fin')
                ->orWhere('a.date_fin', '>=', now()->toDateString()))
            ->where('r.code', RhUser::ROLE_PARENT)
            ->pluck('a.rh_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Les comptes parents des élèves d'une classe, pour l'année de travail.
     *
     * @return int[]
     */
    public function parentsDeLaClasse(string $classe): array
    {
        return array_keys($this->parentsParEleve($this->matriculesDeLaClasse($classe)));
    }

    /**
     * Prévient les parents d'une classe, chacun avec un lien vers SON enfant dans cette
     * classe (« /mon-espace/enfants/{matricule}?onglet=… »).
     *
     * @return int nombre de comptes parents prévenus
     */
    public function envoyerAuxParentsDeLaClasse(string $classe, string $type, string $titre, ?string $corps,
        string $onglet, array $donnees = []): int
    {
        $parEnfant = [];
        foreach ($this->parentsParEleve($this->matriculesDeLaClasse($classe)) as $parent => $matricule) {
            $parEnfant[$matricule][] = $parent;
        }

        foreach ($parEnfant as $matricule => $parents) {
            $this->envoyer($parents, $type, $titre, $corps,
                '/mon-espace/enfants/'.rawurlencode($matricule).'?onglet='.$onglet,
                $donnees + ['matricule' => $matricule]);
        }

        return array_sum(array_map('count', $parEnfant));
    }

    /** @return string[] */
    private function matriculesDeLaClasse(string $classe): array
    {
        return \App\Support\ContexteScolaire::appliquer(
            DB::connection('economat')->table('T_ETUDIANT')->where('CodeClasse', $classe),
            'AnneeAcad'
        )->pluck('Matricule')->map(fn ($m) => trim((string) $m))->filter()->unique()->values()->all();
    }

    /**
     * Compte parent => le premier de ses enfants parmi ces élèves.
     *
     * @param  string[]  $matricules
     * @return array<int, string>
     */
    public function parentsParEleve(array $matricules): array
    {
        $resultat = [];
        foreach (array_chunk(array_values($matricules), 500) as $lot) {
            DB::connection('ecoprim')
                ->table('console_affectations as a')
                ->join('console_roles as r', 'r.id', '=', 'a.role_id')
                ->join('console_affectation_eleves as e', 'e.affectation_id', '=', 'a.id')
                ->whereIn('e.eleve_matricule', $lot)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')
                    ->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->where('r.code', RhUser::ROLE_PARENT)
                ->orderBy('e.eleve_matricule')
                ->get(['a.rh_user_id', 'e.eleve_matricule'])
                ->each(function ($l) use (&$resultat) {
                    $resultat[(int) $l->rh_user_id] ??= trim((string) $l->eleve_matricule);
                });
        }

        return $resultat;
    }

    /** Réveille chaque appareil abonné de ces comptes ; supprime les abonnements expirés. */
    public function reveiller(array $rhUserIds): void
    {
        if ($rhUserIds === [] || ! $this->push->disponible()) {
            return;
        }

        try {
            $abonnements = DB::connection('ecoprim')->table('portail_abonnements_push')
                ->whereIn('rh_user_id', $rhUserIds)
                ->get(['id', 'endpoint']);

            foreach ($abonnements as $abonnement) {
                $resultat = $this->push->reveiller($abonnement->endpoint);
                $table = DB::connection('ecoprim')->table('portail_abonnements_push')->where('id', $abonnement->id);
                if ($resultat === 'expire') {
                    $table->delete();
                } elseif ($resultat === 'ok') {
                    $table->update(['dernier_envoi_le' => now()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('[NEXORA] Réveil des appareils impossible : '.$e->getMessage());
        }
    }
}
