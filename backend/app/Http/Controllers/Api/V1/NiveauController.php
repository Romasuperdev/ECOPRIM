<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Niveau;

/** Niveaux — lecture seule (ECONOMAT.T_NIVEAU). */
class NiveauController extends Controller
{
    public function index()
    {
        return Niveau::with('cycle')->orderBy('Ordre')->get();
    }

    public function show(Niveau $niveau)
    {
        return $niveau->load('cycle');
    }
}
