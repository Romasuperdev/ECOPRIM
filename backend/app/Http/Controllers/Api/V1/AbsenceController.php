<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAbsenceRequest;
use App\Http\Requests\UpdateAbsenceRequest;
use App\Models\Absence;
use App\Models\Eleve;
use App\Support\AnneeScolaireGuard;
use Illuminate\Http\Request;

class AbsenceController extends Controller
{
    public function index(Request $request)
    {
        return Absence::with('eleve', 'matiere')
            ->when($request->filled('eleve_id'), fn ($q) => $q->where('eleve_id', $request->input('eleve_id')))
            ->when($request->filled('classe_id'), fn ($q) => $q->whereHas(
                'eleve',
                fn ($q) => $q->where('classe_id', $request->input('classe_id'))
            ))
            ->orderByDesc('date_absence')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreAbsenceRequest $request)
    {
        $validated = $request->validated();
        $this->guardEleve($validated['eleve_id'], $request);

        $absence = Absence::create($validated);

        return response()->json($absence->load('eleve'), 201);
    }

    public function show(Absence $absence)
    {
        return $absence->load('eleve', 'matiere');
    }

    public function update(UpdateAbsenceRequest $request, Absence $absence)
    {
        $this->guardEleve($absence->eleve_id, $request);

        $absence->update($request->validated());

        return response()->json($absence->load('eleve'));
    }

    public function destroy(Absence $absence, Request $request)
    {
        $this->guardEleve($absence->eleve_id, $request);

        $absence->delete();

        return response()->noContent();
    }

    private function guardEleve(int $eleveId, Request $request): void
    {
        $anneeScolaireId = Eleve::find($eleveId)?->classe?->annee_scolaire_id;
        AnneeScolaireGuard::assertModifiable($anneeScolaireId, $request);
    }
}
