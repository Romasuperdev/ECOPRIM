<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Societe;
use Illuminate\Http\Request;

/** Sociétés — lecture seule (dbmasterbacou.US_SOCIETE). */
class SocieteController extends Controller
{
    public function index(Request $request)
    {
        return Societe::query()
            ->when($request->filled('q'), fn ($query) => $query->where('NOMSOCIETE', 'like', "%{$request->input('q')}%"))
            ->orderBy('NOMSOCIETE')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function show(Societe $societe)
    {
        return $societe;
    }
}
