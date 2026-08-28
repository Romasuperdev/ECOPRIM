<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAffectationRequest;
use App\Models\Affectation;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class AffectationController extends Controller
{
    public function index(Request $request)
    {
        $query = Affectation::with('user', 'societe', 'etablissement', 'role');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('etablissement_id')) {
            $query->where('etablissement_id', $request->integer('etablissement_id'));
        }

        return $query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreAffectationRequest $request)
    {
        $affectation = Affectation::create($request->validated() + ['actif' => true]);
        $affectation->user->assignRole($affectation->role);

        ActivityLogger::log($request, 'create', 'affectations', Affectation::class, $affectation->id, null, $affectation->toArray());

        return response()->json($affectation->load('user', 'societe', 'etablissement', 'role'), 201);
    }

    /**
     * Termine une affectation : on ne supprime jamais la ligne (rule #6),
     * on la marque inactive avec une date de fin, l'historique reste consultable.
     */
    public function destroy(Request $request, Affectation $affectation)
    {
        $avant = $affectation->toArray();
        $affectation->update(['actif' => false, 'date_fin' => now()->toDateString()]);

        $encorePresente = Affectation::where('user_id', $affectation->user_id)
            ->where('role_id', $affectation->role_id)
            ->where('actif', true)
            ->exists();

        if (! $encorePresente) {
            $affectation->user->removeRole($affectation->role);
        }

        ActivityLogger::log($request, 'deactivate', 'affectations', Affectation::class, $affectation->id, $avant, $affectation->toArray());

        return response()->json($affectation);
    }
}
