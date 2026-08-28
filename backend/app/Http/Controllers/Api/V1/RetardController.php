<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRetardRequest;
use App\Http\Requests\UpdateRetardRequest;
use App\Models\Eleve;
use App\Models\Retard;
use App\Support\AnneeScolaireGuard;
use Illuminate\Http\Request;

class RetardController extends Controller
{
    public function index(Request $request)
    {
        return Retard::with('eleve')
            ->when($request->filled('eleve_id'), fn ($q) => $q->where('eleve_id', $request->input('eleve_id')))
            ->when($request->filled('classe_id'), fn ($q) => $q->whereHas(
                'eleve',
                fn ($q) => $q->where('classe_id', $request->input('classe_id'))
            ))
            ->orderByDesc('date_retard')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreRetardRequest $request)
    {
        $validated = $request->validated();
        $this->guardEleve($validated['eleve_id'], $request);

        $retard = Retard::create($validated);

        return response()->json($retard->load('eleve'), 201);
    }

    public function show(Retard $retard)
    {
        return $retard->load('eleve');
    }

    public function update(UpdateRetardRequest $request, Retard $retard)
    {
        $this->guardEleve($retard->eleve_id, $request);

        $retard->update($request->validated());

        return response()->json($retard->load('eleve'));
    }

    public function destroy(Retard $retard, Request $request)
    {
        $this->guardEleve($retard->eleve_id, $request);

        $retard->delete();

        return response()->noContent();
    }

    private function guardEleve(int $eleveId, Request $request): void
    {
        $anneeScolaireId = Eleve::find($eleveId)?->classe?->annee_scolaire_id;
        AnneeScolaireGuard::assertModifiable($anneeScolaireId, $request);
    }
}
