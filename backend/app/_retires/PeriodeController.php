<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Periode;
use Illuminate\Http\Request;

class PeriodeController extends Controller
{
    public function index(Request $request)
    {
        return Periode::with('anneeScolaire')
            ->when($request->filled('annee_scolaire_id'), fn ($q) => $q->where('annee_scolaire_id', $request->input('annee_scolaire_id')))
            ->orderBy('ordre')
            ->get();
    }
}
