<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;

/**
 * Années scolaires — lecture seule sur ECONOMAT.T_ANNEEACADEMIQUE.
 */
class AnneeScolaireController extends Controller
{
    public function index()
    {
        return AnneeScolaire::orderByDesc('DEBUT')->get();
    }

    public function show(AnneeScolaire $anneeScolaire)
    {
        return $anneeScolaire;
    }
}
