<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CorProfClasseEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\DependancesAffectation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Affectation enseignant ↔ classe ↔ matière : ECONOMAT.dbo.T_CORPROFCLASSE.
 *
 * C'est la table dont l'emploi du temps DÉDUIT l'enseignant d'un créneau, et sur laquelle
 * repose le refus qu'un professeur soit dans deux classes à la même heure. Elle est donc
 * en amont du planning : ce qu'on affecte ici se répercute là-bas.
 *
 * Règles retenues :
 *   - une matière n'a qu'un enseignant par classe et par année — réaffecter, c'est
 *     remplacer, pas empiler ;
 *   - un seul titulaire (colonne Principale) par classe et par année ;
 *   - le retrait est une suppression réelle, mais refusée si des créneaux ou des notes
 *     en dépendent (voir DependancesAffectation) ;
 *   - une année clôturée est en consultation seule, comme partout ailleurs.
 */
class AffectationEnseignantController extends Controller
{
    public function __construct(private CorProfClasseEcrivain $ecrivain) {}

    /** Classes, matières et enseignants proposés, bornés à l'année de travail. */
    public function referentiels()
    {
        return [
            'annee' => ContexteScolaire::annee(),
            'annee_cloturee' => AnneeScolaireGuard::estCloturee(ContexteScolaire::annee()),
            'classes' => $this->lire('T_CLASSE', 'LibelleClasse', fn ($l) => [
                'code' => trim((string) $l->CodeClasse),
                'libelle' => $l->LibelleClasse ?: $l->CodeClasse,
                'niveau_code' => $l->CodN ?? null,
            ], 'ANNEE'),
            'matieres' => $this->lire('T_MATIERE', 'LibelleMatiere', fn ($l) => [
                'code' => trim((string) $l->CodeMatiere),
                'libelle' => $l->LibelleMatiere ?: $l->CodeMatiere,
            ]),
            'enseignants' => $this->lire('T_PROFESSEUR', 'NomProfesseur', fn ($l) => [
                'code' => (int) $l->Code,
                'matricule' => $l->MatriculeProfesseur ?? null,
                'nom' => trim(($l->PrenomProfesseur ?? '').' '.($l->NomProfesseur ?? '')) ?: (string) $l->Code,
            ]),
        ];
    }

    /** @param  string|null  $colonneAnnee  Borne l'année quand la table la porte. */
    private function lire(string $table, string $tri, callable $projection, ?string $colonneAnnee = null): array
    {
        try {
            return DB::connection('economat')->table($table)
                ->when($colonneAnnee, fn ($q) => ContexteScolaire::appliquer($q, $colonneAnnee))
                ->orderBy($tri)->get()->map($projection)->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Affectations de l'année, filtrables par classe ou par enseignant — les deux
     * lectures dont on a besoin : « qui enseigne dans cette classe » et « où enseigne ce
     * professeur ».
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'classe' => ['nullable', 'string', 'max:50'],
            'enseignant' => ['nullable', 'integer'],
        ]);

        $annee = ContexteScolaire::annee();

        try {
            $lignes = $this->ecrivain->requete()
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->when($data['classe'] ?? null, fn ($q, $c) => $q->where('CodeClasse', $c))
                ->when($data['enseignant'] ?? null, fn ($q, $e) => $q->where('CodeProfesseur', $e))
                ->get();
        } catch (Throwable $e) {
            $lignes = collect();
        }

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'affectations' => $lignes->map(fn ($l) => $this->ligne($l))
                ->sortBy(fn ($a) => [$a['classe_libelle'], $a['matiere_libelle']])
                ->values(),
        ];
    }

    private function ligne(object $l): array
    {
        $dependances = DependancesAffectation::compter(
            $l->CodeClasse ?? null, $l->CodeMatiere ?? null, $l->ANNEE ?? null
        );

        return [
            'id' => (int) $l->Code,
            'classe' => trim((string) ($l->CodeClasse ?? '')),
            'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $l->CodeClasse ?? null),
            'matiere' => trim((string) ($l->CodeMatiere ?? '')),
            'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $l->CodeMatiere ?? null),
            'enseignant' => $l->CodeProfesseur ? (int) $l->CodeProfesseur : null,
            'enseignant_nom' => $this->nomProfesseur($l->CodeProfesseur ?? null),
            'principale' => (bool) ($l->Principale ?? false),
            'annee' => $l->ANNEE ?? null,
            // Affiché sur la ligne : l'utilisateur sait AVANT de cliquer pourquoi le
            // retrait sera refusé.
            'creneaux' => $dependances['creneaux'],
            'notes' => $dependances['notes'],
            'retirable' => $dependances['creneaux'] === 0 && $dependances['notes'] === 0,
        ];
    }

    private function libelle(string $table, string $cle, string $colonne, ?string $valeur): ?string
    {
        if (! $valeur) {
            return null;
        }
        try {
            return DB::connection('economat')->table($table)
                ->where($cle, $valeur)->value($colonne) ?: $valeur;
        } catch (Throwable $e) {
            return $valeur;
        }
    }

    private function nomProfesseur($code): ?string
    {
        if (! $code) {
            return null;
        }
        try {
            $p = DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)->first();
        } catch (Throwable $e) {
            return null;
        }

        return $p ? (trim(($p->PrenomProfesseur ?? '').' '.($p->NomProfesseur ?? '')) ?: (string) $code) : null;
    }

    private function regles(): array
    {
        return [
            'classe' => ['required', 'string', 'max:50'],
            'matiere' => ['required', 'string', 'max:50'],
            'enseignant' => ['required', 'integer'],
            'principale' => ['nullable', 'boolean'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles());
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "L'affectation d'un enseignant");
        $this->assertReferences($data);

        // Une matière n'a qu'un enseignant par classe : on le dit plutôt que de créer un
        // doublon dont l'emploi du temps ne saurait quel professeur déduire.
        if ($existante = $this->ecrivain->pourClasseEtMatiere($data['classe'], $data['matiere'], $annee)) {
            $nom = $this->nomProfesseur($existante->CodeProfesseur);
            throw ValidationException::withMessages(['matiere' => [
                'Cette matière est déjà assurée dans cette classe'
                .($nom ? " par {$nom}" : '')
                .'. Modifiez l\'affectation existante pour changer d\'enseignant.',
            ]]);
        }

        $code = $this->ecrivain->creer($data + ['annee' => $annee, 'principale' => (bool) ($data['principale'] ?? false)]);

        if (! empty($data['principale'])) {
            $this->ecrivain->definirTitulaire($data['classe'], $annee, $code);
        }

        return response()->json($this->ligne($this->ecrivain->trouver($code)), 201);
    }

    public function update(Request $request, int $affectation)
    {
        $ligne = $this->ecrivain->trouver($affectation);
        abort_if(! $ligne, 404, 'Affectation introuvable.');

        $data = $request->validate([
            'enseignant' => ['required', 'integer'],
            'principale' => ['nullable', 'boolean'],
        ]);
        AnneeScolaireGuard::assertModifiable($ligne->ANNEE, 'La modification de cette affectation');
        $this->assertReferences(['enseignant' => $data['enseignant']]);

        $this->ecrivain->modifier($affectation, ['enseignant' => $data['enseignant']]);

        if (array_key_exists('principale', $data)) {
            $data['principale']
                ? $this->ecrivain->definirTitulaire($ligne->CodeClasse, $ligne->ANNEE, $affectation)
                : $this->ecrivain->modifier($affectation, ['principale' => false]);
        }

        return response()->json($this->ligne($this->ecrivain->trouver($affectation)));
    }

    /**
     * Retrait — suppression réelle, refusée si des créneaux ou des notes en dépendent.
     * Voir DependancesAffectation pour le détail de ce qui bloque et pourquoi.
     */
    public function destroy(int $affectation)
    {
        $ligne = $this->ecrivain->trouver($affectation);
        abort_if(! $ligne, 404, 'Affectation introuvable.');

        AnneeScolaireGuard::assertModifiable($ligne->ANNEE, 'Le retrait de cette affectation');
        DependancesAffectation::assertRetraitPossible(
            $ligne->CodeClasse ?? null, $ligne->CodeMatiere ?? null, $ligne->ANNEE ?? null
        );

        $this->ecrivain->supprimer($affectation);

        return response()->noContent();
    }

    /** Refuse une classe, une matière ou un enseignant qui n'existe pas dans ECONOMAT. */
    private function assertReferences(array $data): void
    {
        $verifs = [
            'classe' => ['T_CLASSE', 'CodeClasse', 'Cette classe est inconnue.'],
            'matiere' => ['T_MATIERE', 'CodeMatiere', 'Cette matière est inconnue.'],
            'enseignant' => ['T_PROFESSEUR', 'Code', 'Cet enseignant est inconnu.'],
        ];

        foreach ($verifs as $champ => [$table, $cle, $message]) {
            if (! isset($data[$champ])) {
                continue;
            }
            try {
                $existe = DB::connection('economat')->table($table)->where($cle, $data[$champ])->exists();
            } catch (Throwable $e) {
                continue; // Référentiel injoignable : on ne bloque pas sur ce qu'on ne peut pas vérifier.
            }
            if (! $existe) {
                throw ValidationException::withMessages([$champ => [$message]]);
            }
        }
    }
}
