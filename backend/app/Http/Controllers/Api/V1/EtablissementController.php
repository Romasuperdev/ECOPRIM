<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Etablissement;
use Illuminate\Http\Request;

/** Établissements — lecture seule (dbmasterbacou.T_ETABLISSEMENT). */
class EtablissementController extends Controller
{
    public function index(Request $request)
    {
        return Etablissement::query()
            ->when($request->filled('q'), fn ($query) => $query->where('RAISONSOCIALE', 'like', "%{$request->input('q')}%"))
            ->orderBy('RAISONSOCIALE')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function show(Etablissement $etablissement)
    {
        return $etablissement;
    }
}
