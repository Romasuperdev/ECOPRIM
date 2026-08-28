<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\ClasseMatiereEnseignant;
use Illuminate\Http\Request;

class ClasseIntervenantController extends Controller
{
    public function index(Classe $classe)
    {
        return $classe->intervenants()->with('matiere', 'enseignant', 'anneeScolaire')->get();
    }

    public function store(Request $request, Classe $classe)
    {
        $validated = $request->validate([
            'matiere_id' => ['required', 'exists:matieres,id'],
            'enseignant_id' => ['required', 'exists:enseignants,id'],
            'annee_scolaire_id' => ['required', 'exists:annees_scolaires,id'],
            'coefficient' => ['nullable', 'integer', 'min:1'],
        ]);

        $intervenant = ClasseMatiereEnseignant::updateOrCreate(
            [
                'classe_id' => $classe->id,
                'matiere_id' => $validated['matiere_id'],
                'annee_scolaire_id' => $validated['annee_scolaire_id'],
            ],
            [
                'enseignant_id' => $validated['enseignant_id'],
                'coefficient' => $validated['coefficient'] ?? null,
            ]
        );

        return response()->json($intervenant->load('matiere', 'enseignant'), 201);
    }

    public function destroy(Classe $classe, ClasseMatiereEnseignant $intervenant)
    {
        abort_unless($intervenant->classe_id === $classe->id, 404);

        $intervenant->delete();

        return response()->noContent();
    }
}
