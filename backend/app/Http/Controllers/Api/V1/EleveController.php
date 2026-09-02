<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use Illuminate\Http\Request;
use App\Support\ContexteScolaire;

/** Élèves — lecture seule (ECONOMAT.T_ETUDIANT). */
class EleveController extends Controller
{
    public function index(Request $request)
    {
        return Eleve::with('classe')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->input('q');
                $query->where(fn ($w) => $w->where('Nom', 'like', "%{$q}%")
                    ->orWhere('Prenom', 'like', "%{$q}%")
                    ->orWhere('Matricule', 'like', "%{$q}%"));
            })
            ->orderBy('Nom')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Eleve $eleve)
    {
        return $eleve->load('classe');
    }
}
