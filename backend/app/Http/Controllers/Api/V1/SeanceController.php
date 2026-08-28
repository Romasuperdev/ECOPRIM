<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSeanceRequest;
use App\Http\Requests\UpdateSeanceRequest;
use App\Models\Seance;
use Illuminate\Http\Request;

class SeanceController extends Controller
{
    public function index(Request $request)
    {
        return Seance::with('classe', 'matiere', 'enseignant', 'programme')
            ->when($request->filled('classe_id'), fn ($q) => $q->where('classe_id', $request->input('classe_id')))
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->orderByDesc('date_seance')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreSeanceRequest $request)
    {
        $seance = Seance::create($request->validated());

        return response()->json($seance->load('classe', 'matiere'), 201);
    }

    public function show(Seance $seance)
    {
        return $seance->load('classe', 'matiere', 'enseignant', 'programme');
    }

    public function update(UpdateSeanceRequest $request, Seance $seance)
    {
        $seance->update($request->validated());

        return response()->json($seance->load('classe', 'matiere'));
    }

    public function destroy(Seance $seance)
    {
        $seance->delete();

        return response()->noContent();
    }
}
