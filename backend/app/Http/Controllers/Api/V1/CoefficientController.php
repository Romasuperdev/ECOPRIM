<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CoefficientMatiere;
use App\Services\GrilleCoefficients;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Barèmes et coefficients des matières, par niveau ou par classe.
 *
 * DEUX LECTURES, DEUX FORMES, DEUX ROUTES. `index` rend la GRILLE — matières en lignes,
 * niveaux en colonnes — c'est ce que l'écran édite. `classe` rend la grille RÉSOLUE d'une
 * classe, matière par matière, après surcharge : c'est ce dont la saisie des notes a besoin.
 * Une seule route qui rendrait l'une ou l'autre selon ses paramètres aurait donné deux
 * formes de réponse à maintenir ensemble.
 *
 * ON ÉCRIT POUR L'ÉTABLISSEMENT DE TRAVAIL, jamais pour un autre : le code vient de la
 * session, pas de la requête. La grille commune (etablissement_code null) ne se modifie pas
 * par cet écran — elle se pose par la commande d'import, une fois, à l'installation.
 */
class CoefficientController extends Controller
{
    public function __construct(private GrilleCoefficients $grille) {}

    /**
     * La grille éditable : les matières, les niveaux, et ce qui est réglé pour chaque
     * croisement. Une case absente veut dire « matière non enseignée à ce niveau ».
     */
    public function index(Request $request)
    {
        $annee = ContexteScolaire::annee();
        $etablissement = PerimetreEtablissement::code();

        $niveaux = $this->niveaux();
        $matieres = $this->matieres();

        $cellules = [];
        foreach ($niveaux as $niveau) {
            foreach ($this->grille->pourNiveau($niveau['code'], $etablissement, $annee) as $matiere => $detail) {
                $cellules[$matiere][$niveau['code']] = $detail;
            }
        }

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'etablissement' => $etablissement,
            'etablissement_nom' => PerimetreEtablissement::nom(),
            'niveaux' => $niveaux,
            'matieres' => $matieres,
            'cellules' => $cellules,
        ];
    }

    /** La grille résolue d'une classe : ce que la saisie des notes appliquera. */
    public function classe(Request $request, string $classe)
    {
        $annee = ContexteScolaire::annee();

        return [
            'classe' => $classe,
            'niveau' => $this->grille->niveauDe($classe),
            'annee' => $annee,
            'coefficients' => $this->grille->pourClasse($classe, PerimetreEtablissement::code(), $annee),
        ];
    }

    /**
     * Enregistrement en masse de la grille de l'établissement.
     *
     * Une case vidée SUPPRIME la surcharge au lieu de poser un zéro : la matière redevient
     * ce que dit la grille commune, ou n'est plus enseignée à ce niveau. Un coefficient à 0
     * voudrait dire « comptée pour rien », ce qui n'est pas la même chose et se saisit
     * explicitement.
     */
    public function update(Request $request)
    {
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, 'Le réglage des coefficients');

        $etablissement = PerimetreEtablissement::code();
        if ($etablissement === null) {
            throw ValidationException::withMessages([
                'etablissement' => "Choisissez un établissement de travail dans l'en-tête : une grille se règle par école.",
            ]);
        }

        $data = $request->validate([
            'lignes' => ['present', 'array'],
            'lignes.*.niveau_code' => ['nullable', 'string', 'max:50'],
            'lignes.*.classe_code' => ['nullable', 'string', 'max:50'],
            'lignes.*.matiere_code' => ['required', 'string', 'max:50'],
            'lignes.*.coefficient' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'lignes.*.note_max' => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            // Sans année, la grille vaut pour toutes : c'est un choix explicite, pas un oubli.
            'toutes_annees' => ['nullable', 'boolean'],
        ]);

        $portee = ($data['toutes_annees'] ?? false) ? null : $annee;
        $bilan = ['enregistrees' => 0, 'supprimees' => 0];

        DB::connection('ecoprim')->transaction(function () use ($data, $etablissement, $portee, &$bilan) {
            foreach ($data['lignes'] as $i => $ligne) {
                $cle = $this->cle($ligne, $i, $etablissement, $portee);

                if (($ligne['coefficient'] ?? null) === null || $ligne['coefficient'] === '') {
                    $bilan['supprimees'] += CoefficientMatiere::where($cle)->delete();

                    continue;
                }

                CoefficientMatiere::updateOrCreate($cle, [
                    'coefficient' => (float) $ligne['coefficient'],
                    'note_max' => ($ligne['note_max'] ?? null) !== null ? (float) $ligne['note_max'] : null,
                    'actif' => true,
                ]);
                $bilan['enregistrees']++;
            }
        });

        return $bilan + ['annee' => $portee, 'etablissement' => $etablissement];
    }

    /** Retire une surcharge : la matière retombe sur la grille commune. */
    public function destroy(CoefficientMatiere $coefficient)
    {
        // On ne supprime que ce que cet établissement a posé. La grille commune
        // (etablissement_code null) appartient à l'installation, pas à une école.
        $etablissement = PerimetreEtablissement::code();
        abort_unless(
            $coefficient->etablissement_code !== null && $coefficient->etablissement_code === $etablissement,
            403,
            "Cette ligne n'appartient pas à votre établissement : seule la grille commune la porte.",
        );

        $coefficient->delete();

        return response()->noContent();
    }

    /** La clé d'unicité d'une ligne : la portée, pas les valeurs. */
    private function cle(array $ligne, int $i, string $etablissement, ?string $annee): array
    {
        $niveau = $ligne['niveau_code'] ?? null;
        $classe = $ligne['classe_code'] ?? null;

        if (($niveau === null) === ($classe === null)) {
            throw ValidationException::withMessages([
                "lignes.$i" => 'Indiquez un niveau OU une classe, pas les deux ni aucun des deux.',
            ]);
        }

        return [
            'etablissement_code' => $etablissement,
            'annee' => $annee,
            'niveau_code' => $niveau,
            'classe_code' => $classe,
            'matiere_code' => $ligne['matiere_code'],
        ];
    }

    /** Niveaux de l'année et de l'établissement de travail, comme les autres écrans. */
    private function niveaux(): array
    {
        $requete = DB::connection('economat')->table('T_NIVEAU')
            ->select('CodeNiveau', 'LibelleNiveau', 'Ordre');
        ContexteScolaire::appliquer($requete, 'ANNEE');

        $etablissement = PerimetreEtablissement::code();
        if ($etablissement !== null) {
            $requete->where(fn ($q) => $q->where('CODEETABLISSEMENT', $etablissement)
                ->orWhereNull('CODEETABLISSEMENT'));
        }

        return $requete->orderBy('Ordre')->orderBy('CodeNiveau')->get()
            // CodeNiveau n'est pas unique dans T_NIVEAU : le même code existe pour
            // plusieurs établissements. On n'en garde qu'une colonne par code, sinon
            // l'écran afficherait deux fois « CP1 » sans savoir les distinguer.
            ->unique(fn ($n) => trim((string) $n->CodeNiveau))
            ->map(fn ($n) => [
                'code' => trim((string) $n->CodeNiveau),
                'libelle' => $n->LibelleNiveau ?: trim((string) $n->CodeNiveau),
            ])->values()->all();
    }

    private function matieres(): array
    {
        return DB::connection('economat')->table('T_MATIERE')
            ->select('CodeMatiere', 'LibelleMatiere')
            ->orderBy('LibelleMatiere')->get()
            ->map(fn ($m) => [
                'code' => trim((string) $m->CodeMatiere),
                'libelle' => $m->LibelleMatiere ?: trim((string) $m->CodeMatiere),
            ])->all();
    }
}
