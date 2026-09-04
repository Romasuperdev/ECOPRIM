<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NiveauMatiereCoefficient;
use Illuminate\Http\Request;

class CoefficientController extends Controller
{
    public function index(Request $request)
    {
        return NiveauMatiereCoefficient::with('niveau', 'matiere')
            ->when($request->filled('niveau_id'), fn ($q) => $q->where('niveau_id', $request->input('niveau_id')))
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'niveau_id' => ['required', 'exists:niveaux,id'],
            'matiere_id' => ['required', 'exists:matieres,id'],
            'coefficient' => ['required', 'integer', 'min:1'],
        ]);

        $coefficient = NiveauMatiereCoefficient::updateOrCreate(
            ['niveau_id' => $validated['niveau_id'], 'matiere_id' => $validated['matiere_id']],
            ['coefficient' => $validated['coefficient']]
        );

        return response()->json($coefficient->load('niveau', 'matiere'), 201);
    }

    public function destroy(NiveauMatiereCoefficient $coefficient)
    {
        $coefficient->delete();

        return response()->noContent();
    }
}
