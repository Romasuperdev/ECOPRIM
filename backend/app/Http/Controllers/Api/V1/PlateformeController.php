<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Console plateforme : ce qui dépasse ECOPRIM et vaut pour toute la suite NEXORA.
 *
 * CES DONNÉES SONT PARTAGÉES. Applications, affectations société-utilisateur,
 * abonnements et paramètres système vivent dans `dbmasterbacou` — la même base que
 * RH_USER, celle qu'ECONOMAT et les autres applications de la suite utilisent aussi.
 * ECOPRIM n'en est pas propriétaire.
 *
 * D'OÙ LA LECTURE SEULE dans ce contrôleur. Les seules écritures permises sur la plateforme
 * passent par des services dédiés (*Ecrivain), chacun avec ses garde-fous : rattachements
 * compte ↔ société, sociétés, applications, Société - Applications — voir
 * PlateformeTest::test_seules_les_ecritures_prevues_touchent_la_plateforme. La règle qui protège ECONOMAT vaut ici pour la
 * même raison : une écriture depuis ce produit toucherait les comptes et les abonnements
 * des autres. Ce qu'ECOPRIM possède en propre — ses sociétés, ses établissements, ses
 * rôles — se gère ailleurs, dans la base `ecoprim`, et s'écrit normalement.
 *
 * UNE TABLE VIDE EST UNE RÉPONSE. Abonnements et formules existent mais ne sont pas
 * encore alimentés. L'écran le dit plutôt que de laisser croire à une panne — et
 * `alimentee` distingue « rien à montrer » de « table absente de cette installation ».
 */
class PlateformeController extends Controller
{
    /** Lecture tolérante : une table absente n'est pas une erreur, c'est une information. */
    private function lire(string $table, callable $requete): array
    {
        try {
            if (! Schema::connection('master')->hasTable($table)) {
                return ['disponible' => false, 'lignes' => []];
            }

            return ['disponible' => true, 'lignes' => $requete(DB::connection('master')->table($table))];
        } catch (Throwable $e) {
            return ['disponible' => false, 'lignes' => []];
        }
    }

    /**
     * Les applications de la suite, et combien de comptes travaillent sur chacune.
     * Le rattachement passe par RH_USER.CodeApp : il n'existe pas de table de liaison.
     */
    public function applications()
    {
        $comptes = [];
        try {
            $comptes = DB::connection('master')->table('RH_USER')
                ->where('Supprimer', false)
                ->select('CodeApp', DB::raw('COUNT(*) as n'))
                ->groupBy('CodeApp')->pluck('n', 'CodeApp')->all();
        } catch (Throwable $e) {
            $comptes = [];
        }

        $lu = $this->lire('US_APPLICATIONS', fn ($q) => $q->orderBy('CodeApp')->get()
            ->map(fn ($a) => [
                'code' => trim((string) $a->CodeApp),
                'libelle' => trim((string) $a->Intitule) ?: trim((string) $a->CodeApp),
                'comptes' => (int) ($comptes[$a->CodeApp] ?? 0),
            ])->all());

        return [
            'applications' => $lu['lignes'],
            'disponible' => $lu['disponible'],
            // Des comptes peuvent porter un code d'application absent du catalogue : le
            // signaler vaut mieux que de les faire disparaître du total.
            'codes_hors_catalogue' => array_values(array_diff(
                array_map(fn ($c) => trim((string) $c), array_keys(array_filter($comptes, fn ($n, $c) => trim((string) $c) !== '', ARRAY_FILTER_USE_BOTH))),
                array_column($lu['lignes'], 'code'),
            )),
        ];
    }

    /**
     * Qui travaille pour quelle société, au niveau de la plateforme.
     * `EstAdminSociete` distingue l'administrateur du simple utilisateur rattaché.
     */
    public function affectations()
    {
        $societes = [];
        $utilisateurs = [];

        try {
            $societes = DB::connection('master')->table('US_SOCIETE')
                ->pluck('NOMSOCIETE', 'CODESOCIETE')->all();
            $utilisateurs = DB::connection('master')->table('RH_USER')
                ->select('Id', 'Login', 'Nom', 'Prenom', 'Email')->get()->keyBy('Id')->all();
        } catch (Throwable $e) {
            // On continue : l'affectation reste lisible par ses codes.
        }

        $lu = $this->lire('societe_utilisateur', fn ($q) => $q->orderBy('societe_id')->orderBy('user_id')->get()
            ->map(function ($a) use ($societes, $utilisateurs) {
                $u = $utilisateurs[$a->user_id] ?? null;

                return [
                    'id' => (int) $a->id,
                    'societe_code' => trim((string) $a->societe_id),
                    'societe_nom' => $societes[$a->societe_id] ?? null,
                    'utilisateur_id' => (int) $a->user_id,
                    'utilisateur' => $u ? trim(($u->Prenom ?? '').' '.($u->Nom ?? '')) ?: $u->Login : null,
                    'login' => $u->Login ?? null,
                    'email' => $u->Email ?? null,
                    // `??` et non `(bool) $a->…` : la colonne peut manquer d'une
                    // installation à l'autre, et l'opérateur évite l'avertissement.
                    'admin_societe' => (bool) ($a->EstAdminSociete ?? false),
                ];
            })->all());

        return ['affectations' => $lu['lignes'], 'disponible' => $lu['disponible']];
    }

    /**
     * Abonnements et formules de la plateforme.
     *
     * Les deux tables existent et sont VIDES aujourd'hui : la facturation n'est pas encore
     * alimentée. L'écran l'annonce au lieu d'afficher une liste vide sans explication, qui
     * se lit comme une panne.
     */
    public function abonnements()
    {
        $formules = $this->lire('subscription_plans', fn ($q) => $q->orderBy('price')->get()
            ->map(fn ($f) => [
                'id' => (int) $f->id,
                'nom' => $f->name,
                'prix' => $f->price !== null ? (float) $f->price : null,
                'periode' => $f->billing_period ?? null,
                'max_eleves' => $f->max_students ?? null,
                'max_utilisateurs' => $f->max_users ?? null,
            ])->all());

        $abonnements = $this->lire('subscriptions', fn ($q) => $q->orderByDesc('starts_at')->get()
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'etablissement_id' => $a->school_id,
                'formule_id' => $a->subscription_plan_id,
                'debut' => $a->starts_at,
                'fin' => $a->ends_at,
                'statut' => $a->status,
                'montant' => $a->amount !== null ? (float) $a->amount : null,
                'renouvellement_auto' => (bool) ($a->auto_renew ?? false),
            ])->all());

        return [
            'formules' => $formules['lignes'],
            'abonnements' => $abonnements['lignes'],
            'disponible' => $formules['disponible'] && $abonnements['disponible'],
            // Vrai dès qu'il y a quelque chose à montrer : distingue « pas encore rempli »
            // de « table absente de cette installation ».
            'alimentee' => $formules['lignes'] !== [] || $abonnements['lignes'] !== [],
        ];
    }

    /** Paramètres globaux de la suite — clé, valeur, et ce à quoi chacun sert. */
    public function parametres()
    {
        $lu = $this->lire('parametres_systeme', fn ($q) => $q->orderBy('cle')->get()
            ->map(fn ($p) => [
                'cle' => $p->cle,
                // La valeur peut être un secret (clé d'API) : on dit qu'elle est posée,
                // on ne la rend pas. Une console n'a pas à diffuser ce qu'elle protège.
                'renseigne' => trim((string) ($p->valeur ?? '')) !== '',
                'description' => $p->description,
                'modifie_le' => $p->updated_at,
            ])->all());

        return ['parametres' => $lu['lignes'], 'disponible' => $lu['disponible']];
    }
}
