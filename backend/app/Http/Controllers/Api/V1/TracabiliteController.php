<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Models\RhUser;
use App\Support\PerimetreConsole;
use App\Support\Tracabilite;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Traçabilité des utilisateurs — ECONOMAT.dbo.T_TRACABILITE, en lecture seule.
 *
 * Le cloisonnement est le point délicat : un Admin Société ne doit voir que l'activité de
 * SA société. T_TRACABILITE n'a pas forcément de colonne société, alors on descend une
 * échelle de stratégies, de la plus fiable à la plus indirecte :
 *
 *   1. une colonne société  -> filtre direct ;
 *   2. une colonne établissement -> restreinte aux établissements de la société ;
 *   3. une colonne utilisateur  -> restreinte aux comptes affectés dans la société.
 *
 * Si aucune n'existe, l'Admin Société ne voit RIEN et la réponse le dit — jamais « on ne
 * sait pas cloisonner, donc on montre tout ». Le Super Admin, lui, voit toute la table en
 * vue générale, et la société choisie sinon.
 */
class TracabiliteController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'utilisateur' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'du' => ['nullable', 'date'],
            'au' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $colonnes = Tracabilite::correspondance();

        if ($colonnes === []) {
            return $this->vide($request, "La table {$this->nomTable()} est introuvable ou vide de colonnes lisibles.");
        }

        $portee = $this->portee();
        if ($portee['aucune']) {
            // On transmet la stratégie : « rien à tracer » et « impossible à cloisonner »
            // ne se disent pas de la même façon à l'écran.
            return $this->vide($request, $portee['message'], $portee['strategie'], $portee['libelle']);
        }

        try {
            $requete = DB::connection('economat')->table(Tracabilite::TABLE);
            $this->appliquerPortee($requete, $colonnes, $portee);
            $this->appliquerFiltres($requete, $colonnes, $data);

            if ($tri = $colonnes['date'] ?? $colonnes['id'] ?? null) {
                $requete->orderByDesc($tri);
            }

            $parPage = min(max($request->integer('per_page', 30), 1), 200);
            $page = $requete->paginate($parPage, ['*'], 'page', max($request->integer('page', 1), 1));
        } catch (Throwable $e) {
            return $this->vide($request, "La traçabilité n'a pas pu être lue : ".$e->getMessage());
        }

        return [
            'colonnes' => $colonnes,
            'colonnes_reelles' => Tracabilite::colonnesReelles(),
            'portee' => $portee['libelle'],
            'strategie' => $portee['strategie'],
            'message' => null,
            'data' => collect($page->items())->map(fn ($l) => $this->ligne($l, $colonnes))->values(),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /** Traçabilité d'un compte précis, atteinte depuis sa fiche. */
    public function utilisateur(Request $request, string $user)
    {
        $rh = RhUser::query()->where('Id', $user)->firstOrFail();

        // Le compte doit être dans le périmètre : sinon on renseignerait sur l'activité
        // d'un utilisateur d'une autre société.
        $this->assertCompteVisible($rh);

        $identifiants = array_values(array_filter(array_map(
            fn ($v) => trim((string) $v),
            [$rh->getRawOriginal('Login'), $rh->getRawOriginal('Matricule'), $rh->getRawOriginal('Email')]
        )));

        $request->merge(['utilisateur' => $identifiants[0] ?? '—']);
        $reponse = $this->index($request);

        return array_merge(is_array($reponse) ? $reponse : $reponse->getData(true), [
            'utilisateur' => [
                'id' => $rh->Id,
                'nom' => trim("{$rh->Prenom} {$rh->Nom}") ?: $rh->Login,
                'login' => $rh->Login,
                'identifiants_cherches' => $identifiants,
            ],
        ]);
    }

    /**
     * Détermine ce que l'utilisateur connecté a le droit de voir.
     *
     * @return array{aucune:bool, strategie:string, libelle:string, message:?string, codes:array}
     */
    private function portee(): array
    {
        $user = auth()->user();
        $colonnes = Tracabilite::correspondance();
        $courant = PerimetreConsole::codeCourant($user);

        if ($user->isSuperAdmin() && $courant === null) {
            return ['aucune' => false, 'strategie' => 'aucune', 'libelle' => 'Toutes les sociétés',
                'message' => null, 'codes' => []];
        }

        if ($courant === null) {
            return ['aucune' => true, 'strategie' => 'aucune',
                'libelle' => 'Aucune société', 'message' => "Aucune société ne vous est affectée.", 'codes' => []];
        }

        $nom = collect(PerimetreConsole::disponibles($user))->firstWhere('code', $courant)['nom'] ?? $courant;

        if (isset($colonnes['societe'])) {
            return ['aucune' => false, 'strategie' => 'societe', 'libelle' => $nom,
                'message' => null, 'codes' => [$courant]];
        }

        if (isset($colonnes['etablissement'])) {
            $codes = $this->etablissementsDe($courant);

            return $codes === []
                ? ['aucune' => true, 'strategie' => 'etablissement', 'libelle' => $nom,
                    'message' => "Aucun établissement n'est rattaché à {$nom} : il n'y a rien à tracer.", 'codes' => []]
                : ['aucune' => false, 'strategie' => 'etablissement', 'libelle' => $nom,
                    'message' => null, 'codes' => $codes];
        }

        if (isset($colonnes['utilisateur'])) {
            $codes = $this->identifiantsDe($courant);

            return $codes === []
                ? ['aucune' => true, 'strategie' => 'utilisateur', 'libelle' => $nom,
                    'message' => "Aucun compte n'est affecté dans {$nom} : il n'y a rien à tracer.", 'codes' => []]
                : ['aucune' => false, 'strategie' => 'utilisateur', 'libelle' => $nom,
                    'message' => null, 'codes' => $codes];
        }

        // Fail closed : on ne sait pas cloisonner, donc on ne montre rien.
        return ['aucune' => true, 'strategie' => 'impossible', 'libelle' => $nom,
            'message' => "La traçabilité ne peut pas être limitée à {$nom} : la table "
                .$this->nomTable()." ne porte ni société, ni établissement, ni utilisateur "
                .'identifiable. Rien ne s\'affiche plutôt que l\'activité de toutes les sociétés.',
            'codes' => []];
    }

    private function appliquerPortee($requete, array $colonnes, array $portee): void
    {
        match ($portee['strategie']) {
            'societe' => $requete->whereIn($colonnes['societe'], $portee['codes']),
            'etablissement' => $requete->whereIn($colonnes['etablissement'], $portee['codes']),
            'utilisateur' => $requete->whereIn($colonnes['utilisateur'], $portee['codes']),
            default => null,
        };
    }

    private function appliquerFiltres($requete, array $colonnes, array $data): void
    {
        if (! empty($data['utilisateur']) && isset($colonnes['utilisateur'])) {
            $requete->where($colonnes['utilisateur'], $data['utilisateur']);
        }
        if (! empty($data['action']) && isset($colonnes['action'])) {
            $requete->where($colonnes['action'], 'like', '%'.$data['action'].'%');
        }
        if (isset($colonnes['date'])) {
            if (! empty($data['du'])) {
                $requete->whereDate($colonnes['date'], '>=', $data['du']);
            }
            if (! empty($data['au'])) {
                $requete->whereDate($colonnes['date'], '<=', $data['au']);
            }
        }
        if (! empty($data['q'])) {
            $cherchables = array_values(array_filter([
                $colonnes['objet'] ?? null, $colonnes['detail'] ?? null, $colonnes['action'] ?? null,
            ]));
            if ($cherchables !== []) {
                $requete->where(function ($w) use ($cherchables, $data) {
                    foreach ($cherchables as $c) {
                        $w->orWhere($c, 'like', '%'.$data['q'].'%');
                    }
                });
            }
        }
    }

    /** Projette la ligne brute sur les rôles métier, en gardant le reste sous « autres ». */
    private function ligne(object $l, array $colonnes): array
    {
        $ligne = ['autres' => []];
        $tableau = (array) $l;

        foreach ($colonnes as $role => $colonne) {
            $ligne[$role] = $tableau[$colonne] ?? null;
        }

        foreach ($tableau as $colonne => $valeur) {
            if (! in_array($colonne, $colonnes, true)) {
                $ligne['autres'][$colonne] = $valeur;
            }
        }

        return $ligne;
    }

    private function etablissementsDe(string $societe): array
    {
        try {
            return Etablissement::where('societe_code', $societe)
                ->pluck('code')->map(fn ($c) => trim((string) $c))->filter()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Identifiants susceptibles d'apparaître dans la traçabilité pour les comptes de cette
     * société : login, matricule et email, ECONOMAT n'utilisant pas partout le même.
     */
    private function identifiantsDe(string $societe): array
    {
        try {
            $ids = Affectation::where('societe_code', $societe)->pluck('rh_user_id')->unique();
            if ($ids->isEmpty()) {
                return [];
            }

            return RhUser::whereIn('Id', $ids)->get()
                ->flatMap(fn ($u) => [
                    $u->getRawOriginal('Login'),
                    $u->getRawOriginal('Matricule'),
                    $u->getRawOriginal('Email'),
                ])
                ->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private function assertCompteVisible(RhUser $rh): void
    {
        $user = auth()->user();
        if ($user->isSuperAdmin()) {
            return;
        }

        $courant = PerimetreConsole::codeCourant($user);
        $visible = $courant !== null && Affectation::where('societe_code', $courant)
            ->where('rh_user_id', $rh->Id)->exists();

        // 404 et non 403 : hors périmètre, l'existence du compte n'a pas à être confirmée.
        abort_unless($visible, 404, 'Utilisateur introuvable.');
    }

    private function nomTable(): string
    {
        return '[ECONOMAT].[dbo].['.Tracabilite::TABLE.']';
    }

    /** Réponse vide mais explicite : l'écran doit pouvoir dire POURQUOI il ne montre rien. */
    private function vide(Request $request, string $message, string $strategie = 'aucune', ?string $portee = null)
    {
        return [
            'colonnes' => Tracabilite::correspondance(),
            'colonnes_reelles' => Tracabilite::colonnesReelles(),
            'portee' => $portee,
            'strategie' => $strategie,
            'message' => $message,
            'data' => [],
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
        ];
    }
}
