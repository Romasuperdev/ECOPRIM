<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cycle;
use App\Services\ReferentielEcrivain;
use App\Support\DependancesReferentiel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Cycles — ECONOMAT.T_CYCLE. Création, modification, suppression conditionnelle. */
class CycleController extends Controller
{
    private const LIENS = [
        ['table' => 'T_NIVEAU', 'colonne' => 'CodeCycle', 'libelle' => 'niveau'],
        ['table' => 'T_MATIERE', 'colonne' => 'CodeCycle', 'libelle' => 'matière'],
    ];

    private ReferentielEcrivain $ecrivain;

    public function __construct()
    {
        // La clé d'écriture est le compteur Num ; CodeCycle est une donnée métier, saisie.
        $this->ecrivain = new ReferentielEcrivain('T_CYCLE', 'Num', [
            'code' => 'CodeCycle',
            'libelle' => 'LibelleCycle',
            'primaire' => 'Primaire',
        ]);
    }

    public function index()
    {
        return Cycle::orderBy('LibelleCycle')->get();
    }

    public function show(Cycle $cycle)
    {
        return $cycle;
    }

    private function regles(bool $creation): array
    {
        return [
            'code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
            'libelle' => [$creation ? 'required' : 'sometimes', 'string', 'max:60'],
            'primaire' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $this->assertCodeLibre($data['code']);

        $num = $this->ecrivain->creer($data, ['primaire']);

        return response()->json(Cycle::where('Num', $num)->firstOrFail(), 201);
    }

    public function update(Request $request, Cycle $cycle)
    {
        $data = $request->validate($this->regles(false));
        $num = $cycle->getRawOriginal('Num');

        if (isset($data['code'])) {
            $this->assertCodeLibre($data['code'], $num);
        }

        $this->ecrivain->modifier($num, $data, ['primaire']);

        return response()->json(Cycle::where('Num', $num)->firstOrFail());
    }

    public function destroy(Cycle $cycle)
    {
        DependancesReferentiel::assertRetraitPossible(
            self::LIENS, $cycle->getRawOriginal('CodeCycle'), 'Ce cycle'
        );

        $this->ecrivain->supprimer($cycle->getRawOriginal('Num'));

        return response()->noContent();
    }

    private function assertCodeLibre(string $code, $sauf = null): void
    {
        if ($this->ecrivain->codeExiste('code', $code, $sauf)) {
            throw ValidationException::withMessages(['code' => ["Le cycle « {$code} » existe déjà."]]);
        }
    }
}
