<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInscriptionRequest;
use App\Models\Inscription;
use Illuminate\Http\Request;

class InscriptionController extends Controller
{
    public function index(Request $request)
    {
        return Inscription::with('eleve', 'anneeScolaire', 'classe')
            ->when($request->filled('eleve_id'), fn ($q) => $q->where('eleve_id', $request->input('eleve_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderByDesc('date_mouvement')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreInscriptionRequest $request)
    {
        $inscription = Inscription::create($request->validated());

        // Une (ré)inscription ou un transfert entrant met à jour la classe courante de l'élève
        if (in_array($inscription->type, ['inscription', 'reinscription', 'transfert_entrant']) && $inscription->classe_id) {
            $inscription->eleve->update(['classe_id' => $inscription->classe_id]);
        }

        return response()->json($inscription->load('eleve', 'anneeScolaire', 'classe'), 201);
    }

    public function show(Inscription $inscription)
    {
        return $inscription->load('eleve', 'anneeScolaire', 'classe');
    }

    public function destroy(Inscription $inscription)
    {
        $inscription->delete();

        return response()->noContent();
    }
}
