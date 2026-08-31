<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Enseignant;
use Illuminate\Http\Request;

/** Enseignants — lecture seule (ECONOMAT.T_PROFESSEUR). */
class EnseignantController extends Controller
{
    public function index(Request $request)
    {
        return Enseignant::when($request->filled('q'), function ($query) use ($request) {
            $q = $request->input('q');
            $query->where(fn ($w) => $w->where('NomProfesseur', 'like', "%{$q}%")
                ->orWhere('PrenomProfesseur', 'like', "%{$q}%")
                ->orWhere('MatriculeProfesseur', 'like', "%{$q}%"));
        })
            ->orderBy('NomProfesseur')
            ->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Enseignant $enseignant)
    {
        return $enseignant;
    }
}
