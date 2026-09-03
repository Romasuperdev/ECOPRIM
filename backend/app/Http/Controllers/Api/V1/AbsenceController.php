<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Eleve;
use App\Services\AbsenceEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Absences élèves — ECONOMAT.dbo.T_ABSENCEELEVE.
 *
 * Saisie, correction et retrait. Les règles tiennent au fait qu'une absence est un
 * constat quotidien :
 *   - l'élève doit exister et appartenir à l'année de travail ;
 *   - la classe enregistrée est celle de l'élève, pas une saisie libre — sinon les
 *     relevés par classe deviendraient faux ;
 *   - pas deux absences pour le même élève, le même jour, à la même heure : c'est une
 *     double saisie ;
 *   - pas d'absence dans le futur ;
 *   - une année clôturée est en consultation seule.
 */
class AbsenceController extends Controller
{
    public function __construct(private AbsenceEcrivain $ecrivain) {}

    public function index(Request $request)
    {
        return Absence::with('eleve')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeCour'))
            ->when($request->filled('classe_code'), fn ($q) => $q->where('CodeClasse', $request->input('classe_code')))
            ->when($request->filled('matricule'), fn ($q) => $q->where('Matricule', $request->input('matricule')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('Date', $request->input('date')))
            ->orderByDesc('Date')
            ->paginate(min($request->integer('per_page', 30), 200));
    }

    public function show(Absence $absence)
    {
        return $absence->load('eleve');
    }

    private function regles(bool $creation): array
    {
        return [
            'matricule' => [$creation ? 'required' : 'sometimes', 'string', 'max:50'],
            'date' => [$creation ? 'required' : 'sometimes', 'date', 'before_or_equal:today'],
            'heure' => ['nullable', 'string', 'max:20'],
            'motif' => ['nullable', 'string', 'max:200'],
            'justifiee' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La saisie d'une absence");

        $eleve = $this->eleve($data['matricule']);
        $this->assertPasDeDoublon($data);

        $code = $this->ecrivain->creer($data + [
            // La classe suit l'élève : on ne la laisse pas saisir librement.
            'classe' => $eleve->getRawOriginal('CodeClasse'),
            'eleve_code' => $eleve->getRawOriginal('Code'),
            'annee' => $annee,
            'justifiee' => (bool) ($data['justifiee'] ?? false),
        ]);

        return response()->json(Absence::with('eleve')->findOrFail($code), 201);
    }

    public function update(Request $request, int $absence)
    {
        $ligne = $this->ecrivain->trouver($absence);
        abort_if(! $ligne, 404, 'Absence introuvable.');

        $data = $request->validate($this->regles(false));
        AnneeScolaireGuard::assertModifiable($ligne->AnneeCour ?? null, 'La modification de cette absence');

        if (isset($data['matricule'])) {
            $eleve = $this->eleve($data['matricule']);
            $data['classe'] = $eleve->getRawOriginal('CodeClasse');
            $data['eleve_code'] = $eleve->getRawOriginal('Code');
        }

        $this->assertPasDeDoublon([
            'matricule' => $data['matricule'] ?? $ligne->Matricule,
            'date' => $data['date'] ?? $ligne->Date,
            'heure' => $data['heure'] ?? $ligne->Heure,
        ], $absence);

        $this->ecrivain->modifier($absence, $data);

        return response()->json(Absence::with('eleve')->findOrFail($absence));
    }

    /**
     * Retrait — suppression réelle : une absence saisie par erreur n'a pas d'autre voie
     * de correction, et rien n'en dépend en aval. Voir AbsenceEcrivain pour le détail de
     * cette exception à la règle « jamais de DELETE ».
     */
    public function destroy(int $absence)
    {
        $ligne = $this->ecrivain->trouver($absence);
        abort_if(! $ligne, 404, 'Absence introuvable.');

        AnneeScolaireGuard::assertModifiable($ligne->AnneeCour ?? null, 'Le retrait de cette absence');

        $this->ecrivain->supprimer($absence);

        return response()->noContent();
    }

    /** L'élève doit exister DANS l'année de travail : sinon l'absence serait orpheline. */
    private function eleve(string $matricule): Eleve
    {
        $eleve = Eleve::where('Matricule', $matricule)
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
            ->first();

        if (! $eleve) {
            throw ValidationException::withMessages([
                'matricule' => ["Aucun élève « {$matricule} » n'est inscrit pour cette année."],
            ]);
        }

        return $eleve;
    }

    private function assertPasDeDoublon(array $d, ?int $sauf = null): void
    {
        if ($this->ecrivain->doublon($d, $sauf)) {
            throw ValidationException::withMessages([
                'date' => ['Cet élève a déjà une absence enregistrée pour ce jour et cette heure.'],
            ]);
        }
    }
}
