<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Role;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rôles — catalogue CRUD (console_roles).
 *
 * Deux catégories : le catalogue général (societe_code NULL), géré par le Super Admin
 * depuis la console générale, et les rôles propres à une société (societe_code renseigné) —
 * gérés par quiconque administre cette société, Admin Société directement ou Admin
 * Établissement via la société de son propre établissement (PerimetreConsole::codeCourant()
 * résout déjà cette société dans les deux cas).
 */
class RoleController extends Controller
{
    public function index()
    {
        $courant = PerimetreConsole::codeCourant();
        $query = Role::orderBy('nom');

        // Vue générale (Super Admin sans société choisie) : tout le catalogue. Sinon, le
        // catalogue général plus les rôles propres à la société courante.
        if ($courant !== null) {
            $query->where(fn ($q) => $q->whereNull('societe_code')->orWhere('societe_code', $courant));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $societeCode = PerimetreConsole::codeCourant($user);

        // Un rôle du catalogue général (societe_code NULL) ne se crée qu'en vue générale,
        // réservée au Super Admin — inatteignable autrement, ce garde-fou est défensif.
        abort_if($societeCode === null && ! $user->isSuperAdmin(), 403,
            'Seul le Super Administrateur peut créer un rôle du catalogue général.');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('ecoprim.console_roles', 'code')],
            'nom' => ['required', 'string', 'max:100'],
        ]);

        $data['societe_code'] = $societeCode;

        return response()->json(Role::create($data), 201);
    }

    public function destroy(Role $role)
    {
        $user = auth()->user();

        if ($role->societe_code === null) {
            abort_unless($user->isSuperAdmin(), 403,
                'Seul le Super Administrateur peut retirer un rôle du catalogue général.');
        } else {
            // assertAutorisee() ne connaît que la société ; un Admin Établissement n'en
            // administre aucune directement, mais PerimetreConsole::codeCourant() lui en
            // résout une (celle de son établissement) — c'est elle qu'on compare ici.
            abort_unless($user->isSuperAdmin() || PerimetreConsole::codeCourant($user) === $role->societe_code, 403,
                "Ce rôle n'est pas dans votre périmètre.");
        }

        $role->delete();

        return response()->noContent();
    }
}
