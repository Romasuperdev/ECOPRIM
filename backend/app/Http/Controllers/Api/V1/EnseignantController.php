<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEnseignantRequest;
use App\Http\Requests\UpdateEnseignantRequest;
use App\Models\AnneeScolaire;
use App\Models\Enseignant;
use Illuminate\Http\Request;

class EnseignantController extends Controller
{
    public function index(Request $request)
    {
        return Enseignant::orderBy('nom')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreEnseignantRequest $request)
    {
        $enseignant = Enseignant::create($request->validated());

        return response()->json($enseignant, 201);
    }

    public function show(Enseignant $enseignant)
    {
        return $enseignant;
    }

    public function update(UpdateEnseignantRequest $request, Enseignant $enseignant)
    {
        $enseignant->update($request->validated());

        return response()->json($enseignant);
    }

    public function destroy(Enseignant $enseignant)
    {
        if ($this->aDesAffectationsActives($enseignant)) {
            abort(422, "Cet enseignant a des affectations actives sur l'année en cours : désactivez-le plutôt que de le supprimer.");
        }

        $enseignant->delete();

        return response()->noContent();
    }

    public function desactiver(Enseignant $enseignant)
    {
        $enseignant->update(['actif' => false]);

        return response()->json($enseignant);
    }

    private function aDesAffectationsActives(Enseignant $enseignant): bool
    {
        $anneeActive = AnneeScolaire::where('active', true)->first();

        if (! $anneeActive) {
            return $enseignant->classesPrincipales()->exists() || $enseignant->intervenants()->exists();
        }

        return $enseignant->classesPrincipales()->where('annee_scolaire_id', $anneeActive->id)->exists()
            || $enseignant->intervenants()->where('annee_scolaire_id', $anneeActive->id)->exists();
    }
}
