<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;

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
