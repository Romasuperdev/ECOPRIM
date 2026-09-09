<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Évaluations PLANIFIÉES (un devoir, une composition à venir...) — table propre à NEXORA
 * (`App\Models\Evaluation`, connexion `ecoprim`), pas ECONOMAT : titre, coefficient, note
 * maximale, créneau horaire et enseignant n'ont pas de colonne dans `T_NOTEENTETE`.
 *
 * À distinguer de `RapportController::evaluations()`, qui restitue les évaluations DÉJÀ
 * notées (agrégat de `V_NOTECLASSE`) : celle-ci sert à en annoncer une, avant toute note.
 * `classe_code`/`matiere_code`/`enseignant_code` pointent vers ECONOMAT en lecture seule,
 * vérifiés à la saisie (`Rule::exists`) faute de contrainte de clé étrangère inter-base.
 *
 * Donnée propre à NEXORA : suppression réelle, sans la retenue de dépendances qui vaut
 * pour les tables partagées (rien n'en dépend en aval, ni ECONOMAT ni une autre app).
 */
class EvaluationController extends Controller
{
    private const TYPES = ['Devoir', 'Devoir surveillé', 'Interrogation écrite', 'Interrogation orale', 'Composition'];

    /** Classes, matières, enseignants et types proposés, classes bornées à l'année de travail. */
    public function referentiels()
    {
        $annee = ContexteScolaire::annee();

        return [
            'annee' => $annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($annee),
            'types' => self::TYPES,
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

        return Evaluation::query()
            ->when($annee, fn ($q) => $q->where('annee', $annee))
            ->when($request->filled('classe'), fn ($q) => $q->where('classe_code', $request->input('classe')))
            ->orderByDesc('date')
            ->get()
            ->map(fn ($e) => $this->ligne($e))
            ->values();
    }

    private function ligne(Evaluation $e): array
    {
        return [
            'id' => $e->id,
            'titre' => $e->titre,
            'classe' => $e->classe_code,
            'classe_libelle' => $this->libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $e->classe_code),
            'matiere' => $e->matiere_code,
            'matiere_libelle' => $this->libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $e->matiere_code),
            'enseignant' => $e->enseignant_code,
            'enseignant_nom' => $this->nomProfesseur($e->enseignant_code),
            'type' => $e->type,
            'date' => optional($e->date)->format('Y-m-d'),
            'heure_debut' => $e->heure_debut,
            'heure_fin' => $e->heure_fin,
            'coefficient' => $e->coefficient,
            'note_maximale' => $e->note_maximale,
            'annee' => $e->annee,
            'annee_cloturee' => AnneeScolaireGuard::estCloturee($e->annee),
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
            'classe' => ['required', 'string', 'max:50', Rule::exists('economat.T_CLASSE', 'CodeClasse')],
            'matiere' => ['required', 'string', 'max:50', Rule::exists('economat.T_MATIERE', 'CodeMatiere')],
            'enseignant' => ['nullable', 'integer', Rule::exists('economat.T_PROFESSEUR', 'Code')],
            'type' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date'],
            'heure_debut' => ['nullable', 'string', 'max:10'],
            'heure_fin' => ['nullable', 'string', 'max:10'],
            'coefficient' => ['required', 'numeric', 'min:0.1', 'max:20'],
            'note_maximale' => ['required', 'numeric', 'min:1', 'max:100'],
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->regles());
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La planification d'une évaluation");

        $evaluation = Evaluation::create([
            'titre' => $d['titre'], 'classe_code' => $d['classe'], 'matiere_code' => $d['matiere'],
            'enseignant_code' => $d['enseignant'] ?? null, 'type' => $d['type'], 'date' => $d['date'],
            'heure_debut' => $d['heure_debut'] ?? null, 'heure_fin' => $d['heure_fin'] ?? null,
            'coefficient' => $d['coefficient'], 'note_maximale' => $d['note_maximale'], 'annee' => $annee,
        ]);

        return response()->json($this->ligne($evaluation), 201);
    }

    public function update(Request $request, Evaluation $evaluation)
    {
        $d = $request->validate($this->regles());
        AnneeScolaireGuard::assertModifiable($evaluation->annee, 'La modification de cette évaluation');

        $evaluation->update([
            'titre' => $d['titre'], 'classe_code' => $d['classe'], 'matiere_code' => $d['matiere'],
            'enseignant_code' => $d['enseignant'] ?? null, 'type' => $d['type'], 'date' => $d['date'],
            'heure_debut' => $d['heure_debut'] ?? null, 'heure_fin' => $d['heure_fin'] ?? null,
            'coefficient' => $d['coefficient'], 'note_maximale' => $d['note_maximale'],
        ]);

        return response()->json($this->ligne($evaluation->refresh()));
    }

    public function destroy(Evaluation $evaluation)
    {
        AnneeScolaireGuard::assertModifiable($evaluation->annee, 'La suppression de cette évaluation');
        $evaluation->delete();

        return response()->noContent();
    }
}
