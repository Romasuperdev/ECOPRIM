<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNiveauRequest;
use App\Http\Requests\UpdateNiveauRequest;
use App\Models\Niveau;

class NiveauController extends Controller
{
    public function index()
    {
        return Niveau::with('cycle')->orderBy('ordre')->get();
    }

    public function store(StoreNiveauRequest $request)
    {
        $niveau = Niveau::create($request->validated());

        return response()->json($niveau, 201);
    }

    public function show(Niveau $niveau)
    {
        return $niveau;
    }

    public function update(UpdateNiveauRequest $request, Niveau $niveau)
    {
        $niveau->update($request->validated());

        return response()->json($niveau);
    }

    public function destroy(Niveau $niveau)
    {
        $niveau->delete();

        return response()->noContent();
    }
}
