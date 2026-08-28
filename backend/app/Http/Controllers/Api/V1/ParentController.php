<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParentRequest;
use App\Http\Requests\UpdateParentRequest;
use App\Models\ParentEleve;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function index(Request $request)
    {
        return ParentEleve::orderBy('nom')->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreParentRequest $request)
    {
        $parent = ParentEleve::create($request->validated());

        return response()->json($parent, 201);
    }

    public function show(ParentEleve $parent)
    {
        return $parent->load('eleves');
    }

    public function update(UpdateParentRequest $request, ParentEleve $parent)
    {
        $parent->update($request->validated());

        return response()->json($parent);
    }

    public function destroy(ParentEleve $parent)
    {
        $parent->delete();

        return response()->noContent();
    }

    public function attachEleve(Request $request, ParentEleve $parent)
    {
        $validated = $request->validate([
            'eleve_id' => ['required', 'exists:eleves,id'],
            'lien_parente' => ['required', 'string', 'in:pere,mere,tuteur'],
        ]);

        $parent->eleves()->syncWithoutDetaching([
            $validated['eleve_id'] => ['lien_parente' => $validated['lien_parente']],
        ]);

        return response()->json($parent->load('eleves'), 201);
    }

    public function detachEleve(ParentEleve $parent, int $eleve)
    {
        $parent->eleves()->detach($eleve);

        return response()->noContent();
    }
}
