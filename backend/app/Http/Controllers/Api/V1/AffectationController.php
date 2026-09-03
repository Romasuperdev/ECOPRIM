<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Models\RhUser;
use App\Support\PerimetreConsole;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Affectations : un rôle d'un utilisateur (RH_USER) dans un établissement.
 * Règles : un utilisateur ne peut être affecté qu'à des établissements de LA MÊME société ;
 * pas de doublon (même utilisateur, même établissement, même rôle). Un Admin Société ne
 * peut affecter que dans sa propre société — sans quoi il se donnerait un accès ailleurs.
 */
class AffectationController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'rh_user_id' => ['required', 'integer'],
            'etablissement_code' => ['required', 'string', Rule::exists('ecoprim.console_etablissements', 'code')],
            'role_id' => ['required', Rule::exists('ecoprim.console_roles', 'id')],
        ]);

        // L'utilisateur doit exister dans RH_USER (lecture seule).
        abort_unless(RhUser::find($data['rh_user_id']), 422, "Utilisateur RH_USER introuvable.");

        $etab = Etablissement::where('code', $data['etablissement_code'])->firstOrFail();
        PerimetreConsole::assertAutorisee($etab->societe_code);

        // Règle : un utilisateur appartient à une seule société.
        $existante = Affectation::where('rh_user_id', $data['rh_user_id'])->first();
        if ($existante && $existante->societe_code !== $etab->societe_code) {
            throw ValidationException::withMessages([
                'etablissement_code' => ["Cet utilisateur est déjà rattaché à une autre société ({$existante->societe_code}) ; on ne peut l'affecter qu'à des établissements de cette société."],
            ]);
        }

        // Anti-doublon (utilisateur + établissement + rôle).
        $doublon = Affectation::where('rh_user_id', $data['rh_user_id'])
            ->where('etablissement_code', $data['etablissement_code'])
            ->where('role_id', $data['role_id'])->exists();
        if ($doublon) {
            throw ValidationException::withMessages(['role_id' => ['Cet utilisateur a déjà ce rôle dans cet établissement.']]);
        }

        $affectation = Affectation::create([
            'rh_user_id' => $data['rh_user_id'],
            'societe_code' => $etab->societe_code,
            'etablissement_code' => $data['etablissement_code'],
            'role_id' => $data['role_id'],
        ]);

        return response()->json($affectation->load(['etablissement', 'role']), 201);
    }

    public function destroy(Affectation $affectation)
    {
        PerimetreConsole::assertAutorisee($affectation->societe_code);
        $affectation->delete();

        return response()->noContent();
    }
}
