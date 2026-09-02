<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use Illuminate\Http\Request;
use App\Support\ContexteScolaire;

/** Absences — lecture seule (ECONOMAT.T_ABSENCEELEVE). */
class AbsenceController extends Controller
{
    public function index(Request $request)
    {
        return Absence::with('eleve')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeCour'))
            ->when($request->filled('classe_code'), fn ($q) => $q->where('CodeClasse', $request->input('classe_code')))
            ->when($request->filled('matricule'), fn ($q) => $q->where('Matricule', $request->input('matricule')))
            ->orderByDesc('Date')
            ->paginate(min($request->integer('per_page', 30), 200));
    }

    public function show(Absence $absence)
    {
        return $absence->load('eleve');
    }
}
