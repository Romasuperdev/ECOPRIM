<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProgrammeRequest;
use App\Http\Requests\UpdateProgrammeRequest;
use App\Models\Programme;
use Illuminate\Http\Request;

class ProgrammeController extends Controller
{
    public function index(Request $request)
    {
        return Programme::with('niveau', 'matiere', 'anneeScolaire')
            ->when($request->filled('niveau_id'), fn ($q) => $q->where('niveau_id', $request->input('niveau_id')))
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->orderBy('titre')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreProgrammeRequest $request)
    {
        $programme = Programme::create($request->validated());

        return response()->json($programme->load('niveau', 'matiere', 'anneeScolaire'), 201);
    }

    public function show(Programme $programme)
    {
        return $programme->load('niveau', 'matiere', 'anneeScolaire');
    }

    public function update(UpdateProgrammeRequest $request, Programme $programme)
    {
        $programme->update($request->validated());

        return response()->json($programme->load('niveau', 'matiere', 'anneeScolaire'));
    }

    public function destroy(Programme $programme)
    {
        $programme->delete();

        return response()->noContent();
    }
}
