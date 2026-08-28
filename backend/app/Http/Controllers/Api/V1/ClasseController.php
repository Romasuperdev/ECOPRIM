<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClasseRequest;
use App\Http\Requests\UpdateClasseRequest;
use App\Models\Classe;
use Illuminate\Http\Request;

class ClasseController extends Controller
{
    public function index(Request $request)
    {
        return Classe::with('niveau', 'anneeScolaire', 'enseignantPrincipal')
            ->orderBy('nom')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function store(StoreClasseRequest $request)
    {
        $classe = Classe::create($request->validated());

        return response()->json($classe, 201);
    }

    public function show(Classe $classe)
    {
        return $classe->load('niveau', 'anneeScolaire', 'enseignantPrincipal', 'eleves');
    }

    public function update(UpdateClasseRequest $request, Classe $classe)
    {
        $classe->update($request->validated());

        return response()->json($classe);
    }

    public function destroy(Classe $classe)
    {
        $classe->delete();

        return response()->noContent();
    }

    public function archiver(Classe $classe)
    {
        if (! $classe->anneeScolaire || ! $classe->anneeScolaire->cloturee) {
            abort(422, "Une classe ne peut être archivée que si son année scolaire est clôturée.");
        }

        $classe->update(['archivee' => true]);

        return response()->json($classe);
    }
}
