<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SocieteUtilisateurEcrivain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Société - Utilisateurs : rattacher un compte à une ou plusieurs sociétés, au niveau de
 * la plateforme. Super Admin seul (groupe `console:generale`) : ces liaisons valent pour
 * toutes les applications de la suite, pas seulement pour ECOPRIM.
 *
 * La liste elle-même reste servie par PlateformeController::affectations ; ce contrôleur
 * n'ajoute que l'écriture, et les listes dont le formulaire a besoin.
 */
class SocieteUtilisateurController extends Controller
{
    public function __construct(private SocieteUtilisateurEcrivain $ecrivain) {}

    /** Comptes actifs et sociétés de la plateforme, pour les listes du formulaire. */
    public function referentiels()
    {
        $utilisateurs = DB::connection('master')->table('RH_USER')
            ->where(fn ($q) => $q->where('Supprimer', false)->orWhereNull('Supprimer'))
            ->orderBy('Login')
            ->get(['Id', 'Login', 'Nom', 'Prenom'])
            ->map(fn ($u) => [
                'id' => (int) $u->Id,
                'login' => trim((string) $u->Login),
                'nom' => trim(trim((string) $u->Prenom).' '.trim((string) $u->Nom)) ?: null,
            ])->values();

        $societes = DB::connection('master')->table('US_SOCIETE')
            ->orderBy('NOMSOCIETE')
            ->get(['CODESOCIETE', 'NOMSOCIETE'])
            ->map(fn ($s) => [
                'code' => trim((string) $s->CODESOCIETE),
                'nom' => trim((string) $s->NOMSOCIETE) ?: trim((string) $s->CODESOCIETE),
            ])->values();

        return ['utilisateurs' => $utilisateurs, 'societes' => $societes];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'utilisateur_id' => ['required', 'integer'],
            'societes' => ['required', 'array', 'min:1'],
            'societes.*' => ['string', 'max:50'],
            'admin_societe' => ['boolean'],
        ]);

        $crees = $this->ecrivain->rattacher(
            (int) $data['utilisateur_id'], $data['societes'], (bool) ($data['admin_societe'] ?? false)
        );

        return response()->json(['crees' => $crees], 201);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['admin_societe' => ['required', 'boolean']]);
        $this->ecrivain->definirAdmin($id, (bool) $data['admin_societe']);

        return response()->noContent();
    }

    public function destroy(int $id)
    {
        $this->ecrivain->detacher($id);

        return response()->noContent();
    }
}
