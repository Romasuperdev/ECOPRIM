<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMatiereRequest;
use App\Http\Requests\UpdateMatiereRequest;
use App\Models\Matiere;
use Illuminate\Http\Request;

class MatiereController extends Controller
{
    public function index(Request $request)
    {
        return Matiere::orderBy('libelle')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreMatiereRequest $request)
    {
        $matiere = Matiere::create($request->validated());

        return response()->json($matiere, 201);
    }

    public function show(Matiere $matiere)
    {
        return $matiere;
    }

    public function update(UpdateMatiereRequest $request, Matiere $matiere)
    {
        $matiere->update($request->validated());

        return response()->json($matiere);
    }

    public function destroy(Matiere $matiere)
    {
        if ($matiere->notes()->exists()) {
            abort(422, 'Cette matière est déjà utilisée dans des évaluations et ne peut pas être supprimée.');
        }

        $matiere->delete();

        return response()->noContent();
    }
}
