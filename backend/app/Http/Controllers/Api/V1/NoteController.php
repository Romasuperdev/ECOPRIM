<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NoteVue;
use Illuminate\Http\Request;

/** Notes — lecture seule via la vue ECONOMAT V_NOTECLASSE. */
class NoteController extends Controller
{
    public function index(Request $request)
    {
        return NoteVue::query()
            ->when($request->filled('classe_code'), fn ($q) => $q->where('CodeClasse', $request->input('classe_code')))
            ->when($request->filled('matiere_code'), fn ($q) => $q->where('CodeMatiere', $request->input('matiere_code')))
            ->when($request->filled('session'), fn ($q) => $q->where('CodeSession', $request->input('session')))
            ->orderBy('Nom')
            ->paginate(min($request->integer('per_page', 30), 200));
    }
}
