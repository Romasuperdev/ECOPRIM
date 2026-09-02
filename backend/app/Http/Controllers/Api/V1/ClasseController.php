<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use Illuminate\Http\Request;
use App\Support\ContexteScolaire;

/** Classes — lecture seule (ECONOMAT.T_CLASSE). */
class ClasseController extends Controller
{
    public function index(Request $request)
    {
        return Classe::with('niveau')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            ->when($request->filled('q'), fn ($query) => $query->where('LibelleClasse', 'like', "%{$request->input('q')}%"))
            ->orderBy('LibelleClasse')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Classe $classe)
    {
        return $classe->load('niveau');
    }
}
