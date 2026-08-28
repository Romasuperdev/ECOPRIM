<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCycleRequest;
use App\Models\Cycle;

class CycleController extends Controller
{
    public function index()
    {
        return Cycle::orderBy('ordre')->get();
    }

    public function store(StoreCycleRequest $request)
    {
        $cycle = Cycle::create($request->validated());

        return response()->json($cycle, 201);
    }

    public function update(StoreCycleRequest $request, Cycle $cycle)
    {
        $cycle->update($request->validated());

        return response()->json($cycle);
    }

    public function destroy(Cycle $cycle)
    {
        if ($cycle->niveaux()->exists()) {
            abort(422, 'Ce cycle est utilisé par au moins un niveau et ne peut pas être supprimé.');
        }

        $cycle->delete();

        return response()->noContent();
    }
}
