<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Niveau;
use App\Support\ContexteScolaire;

/** Niveaux — lecture seule (ECONOMAT.T_NIVEAU). */
class NiveauController extends Controller
{
    public function index()
    {
        return Niveau::with('cycle')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            ->orderBy('Ordre')->get();
    }

    public function show(Niveau $niveau)
    {
        return $niveau->load('cycle');
    }
}
