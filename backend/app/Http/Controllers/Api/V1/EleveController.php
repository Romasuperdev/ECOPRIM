<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Eleve;
use Illuminate\Http\Request;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;

/** Élèves — lecture seule (ECONOMAT.T_ETUDIANT). */
class EleveController extends Controller
{
    public function index(Request $request)
    {
        return Eleve::with('classe')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            // T_ETUDIANT n'a pas d'établissement : on passe par sa classe.
            ->tap(fn ($q) => PerimetreEtablissement::appliquerParClasse($q))
            // Filtre par classe : sert notamment à la saisie des absences, où l'on part
            // de l'effectif d'une classe.
            ->when($request->filled('classe_code'), fn ($q) => $q->where('CodeClasse', $request->input('classe_code')))
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
        PerimetreEtablissement::exigerClasse($eleve->getRawOriginal('CodeClasse'), 'Élève introuvable.');

        return $eleve->load('classe');
    }
}
