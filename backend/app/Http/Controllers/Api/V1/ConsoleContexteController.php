<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Models\RhUser;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Throwable;

/**
 * Contexte de la console : quelle société on administre, et lesquelles on a le droit
 * d'administrer. Même mécanique que le contexte scolaire (établissement + année) : le
 * choix vit dans la session, pas dans l'URL.
 */
class ConsoleContexteController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $courant = PerimetreConsole::codeCourant($user);
        $disponibles = PerimetreConsole::disponibles($user);

        return response()->json([
            // Deux sortes d'administrateur : le front en a besoin pour masquer la console
            // générale à un Admin Société plutôt que de lui servir des 403.
            'super_admin' => $user->isSuperAdmin(),
            'admin_societe' => $user->estAdminSociete(),
            'societe_code' => $courant,
            'societe_nom' => collect($disponibles)->firstWhere('code', $courant)['nom'] ?? $courant,
            // Un Admin Société ne peut pas sortir de sa société : le sélecteur se fige.
            'societe_verrouillee' => ! $user->isSuperAdmin(),
            'societes' => $disponibles,
        ]);
    }

    /**
     * Chiffres d'accueil de la console, bornés au périmètre.
     *
     * Un Admin Société n'a pas accès à /societes : sans cet endpoint, sa page d'accueil
     * appelait une route interdite et restait vide. Le nombre de sociétés n'est renvoyé
     * qu'au Super Admin, et seulement en vue générale — ailleurs il n'aurait pas de sens.
     */
    public function tableauDeBord(Request $request)
    {
        $user = $request->user();
        $courant = PerimetreConsole::codeCourant($user);
        $general = $user->isSuperAdmin() && $courant === null;

        return response()->json([
            'societe_code' => $courant,
            'societe_nom' => $general
                ? null
                : (collect(PerimetreConsole::disponibles($user))->firstWhere('code', $courant)['nom'] ?? $courant),
            'vue_generale' => $general,
            'societes' => $general ? count(PerimetreConsole::disponibles($user)) : null,
            'etablissements' => $this->compter(fn () => PerimetreConsole::appliquer(Etablissement::query())->count()),
            // En vue générale, /admin/utilisateurs liste tous les comptes RH_USER sans les
            // borner à une affectation ECOPRIM (voir UserController::cloisonner) — la tuile
            // doit compter la même chose, sinon elle affiche 0 alors que la liste montre des
            // comptes réels. Dès qu'une société est courante, les deux se recoupent déjà :
            // seuls les comptes affectés dans cette société apparaissent.
            'utilisateurs' => $this->compter(fn () => $general
                ? RhUser::count()
                : PerimetreConsole::appliquer(Affectation::query())->distinct()->count('rh_user_id')
            ),
            'affectations' => $this->compter(fn () => PerimetreConsole::appliquer(Affectation::query())->count()),
        ]);
    }

    /** Un chiffre indisponible vaut null : une tuile vide plutôt qu'une page en erreur. */
    private function compter(callable $calcul): ?int
    {
        try {
            return (int) $calcul();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Bascule la console sur une autre société. Un code vide ramène le Super Admin à la vue générale. */
    public function definirSociete(Request $request)
    {
        $data = $request->validate(['societe_code' => ['nullable', 'string', 'max:50']]);
        $code = trim((string) ($data['societe_code'] ?? ''));

        if ($code === '') {
            abort_unless($request->user()->isSuperAdmin(), 403,
                'Vous administrez une société précise : la vue générale ne vous est pas ouverte.');
            $request->session()->forget(PerimetreConsole::CLE_SESSION);

            return $this->show($request);
        }

        PerimetreConsole::assertAutorisee($code);
        $request->session()->put(PerimetreConsole::CLE_SESSION, $code);

        return $this->show($request);
    }
}
