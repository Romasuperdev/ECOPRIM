<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Etablissement;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Établissements — CRUD (console_etablissements), rattachés à une seule société. */
class EtablissementController extends Controller
{
    public function index(Request $request)
    {
        return Etablissement::with('societe')
            ->when($request->filled('societe_code'), fn ($x) => $x->where('societe_code', $request->input('societe_code')))
            ->when($request->filled('q'), fn ($x) => $x->where('intitule', 'like', "%{$request->input('q')}%"))
            ->orderBy('intitule')
            ->paginate(min($request->integer('per_page', 20), 200));
    }

    public function show(Etablissement $etablissement)
    {
        return $etablissement->load('societe');
    }

    private function regles(?int $id = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('ecoprim.console_etablissements', 'code')->ignore($id)],
            'intitule' => ['required', 'string', 'max:150'],
            'type' => ['nullable', 'string', 'max:50'],
            'adresse' => ['nullable', 'string', 'max:200'],
            'ville' => ['nullable', 'string', 'max:100'],
            'pays' => ['nullable', 'string', 'max:50'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'site_web' => ['nullable', 'string', 'max:150'],
            // Rattachement obligatoire à une société existante.
            'societe_code' => ['required', 'string', Rule::exists('ecoprim.console_societes', 'code')],
        ];
    }

    public function store(Request $request)
    {
        return response()->json(Etablissement::create($request->validate($this->regles()))->load('societe'), 201);
    }

    public function update(Request $request, Etablissement $etablissement)
    {
        $etablissement->update($request->validate($this->regles($etablissement->id)));

        return response()->json($etablissement->load('societe'));
    }

    public function activer(Etablissement $etablissement)
    {
        $etablissement->update(['actif' => true]);

        return response()->json($etablissement);
    }

    public function desactiver(Etablissement $etablissement)
    {
        $etablissement->update(['actif' => false]);

        return response()->json($etablissement);
    }
}
