<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
use Illuminate\Http\Request;

/** Sociétés — CRUD (base propre ECOPRIM : console_societes). */
class SocieteController extends Controller
{
    public function index(Request $request)
    {
        return Societe::withCount('etablissements')
            ->when($request->filled('q'), fn ($x) => $x->where('nom', 'like', "%{$request->input('q')}%"))
            ->orderBy('nom')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function show(Societe $societe)
    {
        return $societe->loadCount('etablissements');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:ecoprim.console_societes,code'],
            'nom' => ['required', 'string', 'max:150'],
            'ville' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'representant' => ['nullable', 'string', 'max:150'],
        ]);

        return response()->json(Societe::create($data), 201);
    }

    public function update(Request $request, Societe $societe)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:ecoprim.console_societes,code,'.$societe->id],
            'nom' => ['required', 'string', 'max:150'],
            'ville' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'representant' => ['nullable', 'string', 'max:150'],
        ]);
        $societe->update($data);

        return response()->json($societe);
    }

    public function activer(Societe $societe)
    {
        $societe->update(['actif' => true]);

        return response()->json($societe);
    }

    public function desactiver(Societe $societe)
    {
        $societe->update(['actif' => false]);

        return response()->json($societe);
    }
}
