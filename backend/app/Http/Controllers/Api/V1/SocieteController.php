<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Societe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Importe dans console_societes les sociétés RÉELLES lues dans dbmasterbacou.US_SOCIETE
     * (lecture seule). N'ajoute que les sociétés absentes : les enregistrements déjà
     * présents dans ECOPRIM (éventuellement édités ici) ne sont jamais réécrits.
     */
    public function importer()
    {
        $existants = Societe::pluck('code')->all();
        $crees = 0;
        $ignores = 0;

        foreach (DB::connection('master')->table('US_SOCIETE')->get() as $l) {
            $code = trim((string) ($l->CODESOCIETE ?? ''));
            if ($code === '') {
                continue;
            }
            if (in_array($code, $existants, true)) {
                $ignores++;

                continue;
            }

            Societe::create([
                'code' => $code,
                'actif' => true,
                'nom' => trim((string) ($l->NOMSOCIETE ?? '')) ?: $code,
                'ville' => $l->VILLESOCIETE ?? null,
                'adresse' => $l->AD1SOCIETE ?? ($l->ADRESSE ?? null),
                'telephone' => $l->TELSOCIETE ?? null,
                'email' => $l->EMAILSOCIETE ?? null,
                'representant' => $l->NOMPRENOMREPRESENTANT ?? ($l->REPRESENTANT ?? null),
            ]);
            $existants[] = $code;
            $crees++;
        }

        return response()->json([
            'importes' => $crees,
            'ignores' => $ignores,
            'message' => "{$crees} société(s) importée(s), {$ignores} déjà présente(s).",
        ]);
    }
}
