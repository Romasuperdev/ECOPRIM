<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matiere;
use Illuminate\Http\Request;

/** Matières — lecture seule (ECONOMAT.T_MATIERE). */
class MatiereController extends Controller
{
    public function index(Request $request)
    {
        return Matiere::orderBy('LibelleMatiere')->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Matiere $matiere)
    {
        return $matiere;
    }
}
