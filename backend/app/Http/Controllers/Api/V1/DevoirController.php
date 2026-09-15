<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Devoir;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Devoirs donnés à une classe — table propre à NEXORA (`App\Models\Devoir`, connexion
 * `ecoprim`), pas ECONOMAT : le cahier de texte ne consigne que ce qui a été vu en
 * classe, pas un travail à rendre avec une date de remise.
 *
 * `classe_code`/`matiere_code`/`enseignant_code` pointent vers ECONOMAT en lecture
 * seule, vérifiés à la saisie (`Rule::exists`) faute de contrainte de clé étrangère
 * inter-base. Donnée propre à NEXORA : suppression réelle, comme les évaluations
 * planifiées.
 */
class DevoirController extends Controller
{
    /** Classes, matières et enseignants proposés, classes bornées à l'année de travail. */
    public function referentiels()
    {
        $annee = ContexteScolaire::annee();

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'classes' => $this->lire('T_CLASSE', 'LibelleClasse', fn ($l) => [
                'code' => trim((string) $l->CodeClasse), 'libelle' => $l->LibelleClasse ?: $l->CodeClasse,
            ], 'ANNEE'),
            'matieres' => $this->lire('T_MATIERE', 'LibelleMatiere', fn ($l) => [
                'code' => trim((string) $l->CodeMatiere), 'libelle' => $l->LibelleMatiere ?: $l->CodeMatiere,
            ]),
            'enseignants' => $this->lire('T_PROFESSEUR', 'NomProfesseur', fn ($l) => [
                'code' => (int) $l->Code,
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

    public function index(Request $request)
    {
        $annee = ContexteScolaire::annee();

        return Devoir::query()
            ->when($annee, fn ($q) => $q->where('annee', $annee))
            ->when($request->filled('classe'), fn ($q) => $q->where('classe_code', $request->input('classe')))
            ->orderByDesc('date_remise')
            ->get()
            ->map(fn ($d) => $this->ligne($d))
            ->values();
    }

    private function ligne(Devoir $d): array
    {
        return [
            'id' => $d->id,
            'titre' => $d->titre,
            'consigne' => $d->consigne,
            'classe' => $d->classe_code,
            'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $d->classe_code),
            'matiere' => $d->matiere_code,
            'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $d->matiere_code),
            'enseignant' => $d->enseignant_code,
            'enseignant_nom' => $this->nomProfesseur($d->enseignant_code),
            'date_remise' => optional($d->date_remise)->format('Y-m-d'),
            'annee' => $d->annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($d->annee),
        ];
    }

    private function libelle(string $table, string $cle, string $colonne, ?string $valeur): ?string
    {
        if (! $valeur) {
            return null;
        }
        try {
            return DB::connection('economat')->table($table)->where($cle, $valeur)->value($colonne) ?: $valeur;
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
            'titre' => ['required', 'string', 'max:150'],
            'consigne' => ['nullable', 'string'],
            'classe' => ['required', 'string', 'max:50', Rule::exists('economat.T_CLASSE', 'CodeClasse')],
            'matiere' => ['required', 'string', 'max:50', Rule::exists('economat.T_MATIERE', 'CodeMatiere')],
            'enseignant' => ['nullable', 'integer', Rule::exists('economat.T_PROFESSEUR', 'Code')],
            'date_remise' => ['required', 'date'],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->regles());
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'un devoir");

        $devoir = Devoir::create([
            'titre' => $d['titre'], 'consigne' => $d['consigne'] ?? null,
            'classe_code' => $d['classe'], 'matiere_code' => $d['matiere'],
            'enseignant_code' => $d['enseignant'] ?? null, 'date_remise' => $d['date_remise'], 'annee' => $annee,
        ]);

        return response()->json($this->ligne($devoir), 201);
    }

    public function update(Request $request, Devoir $devoir)
    {
        $d = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($devoir->annee, 'La modification de ce devoir');

        $devoir->update([
            'titre' => $d['titre'], 'consigne' => $d['consigne'] ?? null,
            'classe_code' => $d['classe'], 'matiere_code' => $d['matiere'],
            'enseignant_code' => $d['enseignant'] ?? null, 'date_remise' => $d['date_remise'],
        ]);

        return response()->json($this->ligne($devoir->refresh()));
    }

    public function destroy(Devoir $devoir)
    {
        AnneeScolaireGuard::assertModifiable($devoir->annee, 'La suppression de ce devoir');
        $devoir->delete();

        return response()->noContent();
    }
}
