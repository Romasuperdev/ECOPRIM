<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRessourceRequest;
use App\Http\Requests\UpdateRessourceRequest;
use App\Models\Ressource;
use Illuminate\Http\Request;

class RessourceController extends Controller
{
    public function index(Request $request)
    {
        return Ressource::with('matiere', 'niveau', 'enseignant')
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreRessourceRequest $request)
    {
        $ressource = Ressource::create($request->validated());

        return response()->json($ressource->load('matiere', 'niveau'), 201);
    }

    public function show(Ressource $ressource)
    {
        return $ressource->load('matiere', 'niveau', 'enseignant');
    }

    public function update(UpdateRessourceRequest $request, Ressource $ressource)
    {
        $ressource->update($request->validated());

        return response()->json($ressource->load('matiere', 'niveau'));
    }

    public function destroy(Ressource $ressource)
    {
        $ressource->delete();

        return response()->noContent();
    }
}
