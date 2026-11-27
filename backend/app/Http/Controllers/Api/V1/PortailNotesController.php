<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Notes\EvaluationsNotes;
use App\Support\PorteeEnseignant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Espace Enseignant : évaluations notées de ses matières — créer, saisir au pavé
 * numérique, enregistrer en brouillon, publier aux parents.
 *
 * L'autorité, c'est d'enseigner la matière dans la classe (T_CORPROFCLASSE), vérifiée à
 * chaque action, comme pour la feuille de notes existante.
 */
class PortailNotesController extends Controller
{
    public function __construct(private EvaluationsNotes $evaluations) {}

    private function assertEnseigne(Request $request, ?string $classe, ?string $matiere): void
    {
        if (! PorteeEnseignant::enseigne($request->user(), $classe, $matiere)) {
            throw new HttpException(403, "Vous n'enseignez pas cette matière dans cette classe.");
        }
    }

    private function assertEnteteAutorisee(Request $request, int $entete): void
    {
        [$classe, $matiere] = $this->evaluations->classeEtMatiere($entete);
        $this->assertEnseigne($request, $classe, $matiere);
    }

    public function index(Request $request)
    {
        $classe = (string) $request->validate(['classe' => ['required', 'string', 'max:50']])['classe'];
        if (! in_array($classe, PorteeEnseignant::classes($request->user()), true)) {
            throw new HttpException(403, "Cette classe n'est pas parmi celles que vous enseignez.");
        }

        return [
            'types' => EvaluationsNotes::TYPES,
            'evaluations' => $this->evaluations->lister($classe, PorteeEnseignant::matieres($request->user(), $classe)),
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'classe' => ['required', 'string', 'max:50'],
            'matiere' => ['required', 'string', 'max:50'],
            'titre' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(EvaluationsNotes::TYPES)],
            'session' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date_format:Y-m-d'],
            'bareme' => ['nullable', 'numeric', 'min:1', 'max:200'],
        ]);
        $this->assertEnseigne($request, $d['classe'], $d['matiere']);

        return response()->json($this->evaluations->creer($request->user(), $d), 201);
    }

    public function show(Request $request, int $entete)
    {
        $this->assertEnteteAutorisee($request, $entete);

        return $this->evaluations->feuille($entete);
    }

    public function enregistrer(Request $request, int $entete)
    {
        $this->assertEnteteAutorisee($request, $entete);
        $d = $request->validate([
            'notes' => ['present', 'array', 'max:200'],
            'notes.*.matricule' => ['required', 'string', 'max:50'],
            'notes.*.note' => ['nullable', 'numeric', 'min:0'],
            'notes.*.absent' => ['nullable', 'boolean'],
            'publier' => ['nullable', 'boolean'],
        ]);

        return $this->evaluations->enregistrer(
            $entete,
            array_map(fn ($n) => $n + ['note' => null], $d['notes']),
            (bool) ($d['publier'] ?? false),
        );
    }
}
