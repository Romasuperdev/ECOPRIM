<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\UpdateNoteRequest;
use App\Models\Note;
use App\Support\AnneeScolaireGuard;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        return Note::with('eleve', 'matiere', 'periode')
            ->when($request->filled('classe_id'), fn ($q) => $q->whereHas(
                'eleve',
                fn ($q) => $q->where('classe_id', $request->input('classe_id'))
            ))
            ->when($request->filled('eleve_id'), fn ($q) => $q->where('eleve_id', $request->input('eleve_id')))
            ->when($request->filled('matiere_id'), fn ($q) => $q->where('matiere_id', $request->input('matiere_id')))
            ->when($request->filled('periode_id'), fn ($q) => $q->where('periode_id', $request->input('periode_id')))
            ->orderByDesc('date_evaluation')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreNoteRequest $request)
    {
        $validated = $request->validated();
        $this->guardPeriode($validated['periode_id'] ?? null, $request);

        $note = Note::create($validated);

        return response()->json($note->load('eleve', 'matiere'), 201);
    }

    public function show(Note $note)
    {
        return $note->load('eleve', 'matiere', 'periode', 'enseignant');
    }

    public function update(UpdateNoteRequest $request, Note $note)
    {
        $validated = $request->validated();
        $this->guardPeriode($validated['periode_id'] ?? $note->periode_id, $request);

        $note->update($validated);

        return response()->json($note->load('eleve', 'matiere'));
    }

    public function destroy(Note $note, Request $request)
    {
        $this->guardPeriode($note->periode_id, $request);

        $note->delete();

        return response()->noContent();
    }

    private function guardPeriode(?int $periodeId, Request $request): void
    {
        if (! $periodeId) {
            return;
        }

        $anneeScolaireId = \App\Models\Periode::find($periodeId)?->annee_scolaire_id;
        AnneeScolaireGuard::assertModifiable($anneeScolaireId, $request);
    }
}
