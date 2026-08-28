<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConseilClasse;
use App\Models\Deliberation;
use Illuminate\Http\Request;

class DeliberationController extends Controller
{
    public function store(Request $request, ConseilClasse $conseil)
    {
        $validated = $request->validate([
            'eleve_id' => ['required', 'exists:eleves,id'],
            'moyenne_generale' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'decision' => ['nullable', 'string', 'in:passage,redoublement,orientation,avertissement'],
            'appreciation' => ['nullable', 'string'],
            'mention' => ['nullable', 'string', 'max:50'],
        ]);

        $deliberation = Deliberation::updateOrCreate(
            ['conseil_classe_id' => $conseil->id, 'eleve_id' => $validated['eleve_id']],
            $validated
        );

        return response()->json($deliberation->load('eleve'), 201);
    }

    public function destroy(ConseilClasse $conseil, Deliberation $deliberation)
    {
        abort_unless($deliberation->conseil_classe_id === $conseil->id, 404);

        $deliberation->delete();

        return response()->noContent();
    }
}
