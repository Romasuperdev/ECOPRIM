<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Matiere;
use App\Services\ReferentielEcrivain;
use App\Support\DependancesReferentiel;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Matières — ECONOMAT.T_MATIERE.
 *
 * Une matière n'est pas rattachée à une année : elle traverse les années scolaires, il n'y
 * a donc pas de verrou de clôture ici. La suppression est refusée dès qu'une affectation,
 * un créneau ou une note s'y réfère.
 *
 * NB — on lit les colonnes réelles avec getRawOriginal() et jamais getAttribute() : ces
 * modèles exposent des alias (« code » pour CodeMatiere), et Laravel dérive le MÊME nom
 * d'accesseur pour « Code » et pour « code ». getAttribute('Code') renverrait donc
 * CodeMatiere — soit « MATH » au lieu de 1, et un 404 à la moindre écriture.
 */
class MatiereController extends Controller
{
    private const LIENS = [
        ['table' => 'T_CORPROFCLASSE', 'colonne' => 'CodeMatiere', 'libelle' => 'affectation d’enseignant'],
        ['table' => 'T_EMPLOIDUTEMPS', 'colonne' => 'CODEMATIERE', 'libelle' => "créneau d'emploi du temps"],
        ['table' => 'V_NOTECLASSE', 'colonne' => 'CodeMatiere', 'libelle' => 'note'],
    ];

    private ReferentielEcrivain $ecrivain;

    public function __construct()
    {
        $this->ecrivain = new ReferentielEcrivain('T_MATIERE', 'Code', [
            'code' => 'CodeMatiere',
            'libelle' => 'LibelleMatiere',
            'type' => 'Type',
            'cycle_code' => 'CodeCycle',
            'composition' => 'Composition',
        ]);
    }

    public function index(Request $request)
    {
        return Matiere::orderBy('LibelleMatiere')->paginate(min($request->integer('per_page', 15), 200));
    }

    public function show(Matiere $matiere)
    {
        return $matiere;
    }

    private function regles(bool $creation): array
    {
        return [
            'code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
            'libelle' => [$creation ? 'required' : 'sometimes', 'string', 'max:60'],
            'type' => ['nullable', 'string', 'max:30'],
            'cycle_code' => ['nullable', 'string', 'max:20'],
            'composition' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $this->assertCodeLibre($data['code']);

        $id = $this->ecrivain->creer($data, ['composition']);

        return response()->json(Matiere::findOrFail($id), 201);
    }

    public function update(Request $request, Matiere $matiere)
    {
        $data = $request->validate($this->regles(false));
        $id = $matiere->getRawOriginal('Code');

        if (isset($data['code'])) {
            $this->assertCodeLibre($data['code'], $id);
        }

        $this->ecrivain->modifier($id, $data, ['composition']);

        return response()->json(Matiere::findOrFail($id));
    }

    public function destroy(Matiere $matiere)
    {
        DependancesReferentiel::assertRetraitPossible(
            self::LIENS, $matiere->getRawOriginal('CodeMatiere'), 'Cette matière'
        );

        $this->ecrivain->supprimer($matiere->getRawOriginal('Code'));

        return response()->noContent();
    }

    private function assertCodeLibre(string $code, $sauf = null): void
    {
        if ($this->ecrivain->codeExiste('code', $code, $sauf)) {
            throw ValidationException::withMessages(['code' => ["La matière « {$code} » existe déjà."]]);
        }
    }
}
