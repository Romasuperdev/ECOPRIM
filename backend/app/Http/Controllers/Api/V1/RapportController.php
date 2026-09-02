<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Support\ContexteScolaire;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rapports pédagogiques, lus dans ECONOMAT.
 *
 * ECONOMAT calcule déjà moyennes, rangs et coefficients : NEXORA les restitue, il ne
 * les recalcule pas. Tout est restreint à l'année de travail choisie dans l'en-tête.
 *
 * (Version précédente : ces trois endpoints s'appuyaient sur des tables locales
 * `notes`/`retards` et sur des relations `Classe::eleves` / `Eleve::absences`
 * inexistantes — ils renvoyaient donc une erreur 500.)
 */
class RapportController extends Controller
{
    /** Moyennes et classement des élèves d'une classe (V_MOYENNE_ELEVE_CLASSE). */
    public function moyennesClasse(Request $request, Classe $classe)
    {
        $lignes = $this->moyennes($classe->code, $request->input('session'));

        return [
            'classe' => $classe->nom,
            'annee' => ContexteScolaire::annee(),
            'session' => $request->input('session'),
            'classement' => $lignes,
            'moyenne_classe' => $lignes->isNotEmpty()
                ? round($lignes->avg('moyenne'), 2)
                : null,
        ];
    }

    /** Utilisé aussi par le bulletin : moyennes d'une classe, année de travail comprise. */
    public function moyennes(?string $codeClasse, ?string $session = null)
    {
        if (! $codeClasse) {
            return collect();
        }

        $requete = DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')
            ->where('CodeClasse', $codeClasse)
            ->when($session, fn ($q) => $q->where('CodeSession', $session));
        ContexteScolaire::appliquer($requete, 'CodeAnnee');

        return $requete->orderBy('Rang')->get()->map(fn ($l) => [
            'code_eleve' => $l->CodeEleve,
            'matricule' => $l->Matricule,
            'nom' => $l->Nom,
            'prenom' => $l->Prenom,
            'moyenne' => $l->Moyenne !== null ? round((float) $l->Moyenne, 2) : null,
            'rang' => $l->Rang,
            'session' => $l->CodeSession,
            'passage' => (bool) $l->Passage,
        ]);
    }

    /** Notes par matière d'un élève, pour le bulletin (V_NOTECLASSE). */
    public function notesParMatiere(?string $matricule, ?string $session = null)
    {
        if (! $matricule) {
            return collect();
        }

        $requete = DB::connection('economat')->table('V_NOTECLASSE')
            ->where('Matricule', $matricule)
            ->when($session, fn ($q) => $q->where('CodeSession', $session));
        ContexteScolaire::appliquer($requete, 'CodeAnnee');

        return $requete->get()
            ->groupBy('CodeMatiere')
            ->map(fn ($notes, $code) => [
                'matiere_code' => $code,
                'matiere' => $notes->first()->LibelleMatiere ?? $code,
                'coefficient' => $notes->first()->Coefficient !== null
                    ? (float) $notes->first()->Coefficient : null,
                'moyenne' => $notes->avg('Note') !== null ? round((float) $notes->avg('Note'), 2) : null,
                'nombre_notes' => $notes->count(),
            ])
            ->values();
    }

    /** Assiduité d'une classe : absences par élève (T_ABSENCEELEVE). */
    public function assiduiteClasse(Request $request, Classe $classe)
    {
        $requete = DB::connection('economat')->table('T_ABSENCEELEVE')
            ->where('CodeClasse', $classe->code)
            ->when($request->filled('session'), fn ($q) => $q->where('CodeSession', $request->input('session')));
        ContexteScolaire::appliquer($requete, 'AnneeCour');

        $absences = $requete->get();

        // Les noms viennent de T_ETUDIANT : T_ABSENCEELEVE ne porte que le matricule.
        $eleves = DB::connection('economat')->table('T_ETUDIANT')
            ->whereIn('Matricule', $absences->pluck('Matricule')->filter()->unique()->all())
            ->get(['Matricule', 'Nom', 'Prenom'])
            ->keyBy('Matricule');

        $lignes = $absences->groupBy('Matricule')
            ->map(function ($lot, $matricule) use ($eleves) {
                $eleve = $eleves[$matricule] ?? null;

                return [
                    'matricule' => $matricule,
                    'nom' => $eleve->Nom ?? null,
                    'prenom' => $eleve->Prenom ?? null,
                    'total_absences' => $lot->count(),
                    'absences_non_justifiees' => $lot->where('Justifier', false)->count(),
                ];
            })
            ->sortByDesc('total_absences')
            ->values();

        return [
            'classe' => $classe->nom,
            'annee' => ContexteScolaire::annee(),
            'eleves' => $lignes,
        ];
    }

    /**
     * « Évaluations » — vue agrégée des devoirs existants, reconstituée en regroupant
     * les notes par classe, matière, session et type. Il n'y a pas de table dédiée
     * dans ECONOMAT.
     */
    public function evaluations(Request $request)
    {
        $requete = DB::connection('economat')->table('V_NOTECLASSE')
            ->when($request->filled('classe_code'), fn ($q) => $q->where('CodeClasse', $request->input('classe_code')))
            ->when($request->filled('matiere_code'), fn ($q) => $q->where('CodeMatiere', $request->input('matiere_code')))
            ->when($request->filled('session'), fn ($q) => $q->where('CodeSession', $request->input('session')));
        ContexteScolaire::appliquer($requete, 'CodeAnnee');

        return $requete->get()
            ->groupBy(fn ($n) => implode('|', [$n->CodeClasse, $n->CodeMatiere, $n->CodeSession, $n->TypeNote]))
            ->map(function ($lot) {
                $premiere = $lot->first();

                return [
                    'classe_code' => $premiere->CodeClasse,
                    'matiere_code' => $premiere->CodeMatiere,
                    'matiere' => $premiere->LibelleMatiere ?? $premiere->CodeMatiere,
                    'session' => $premiere->CodeSession,
                    'type' => $premiere->TypeNote,
                    'coefficient' => $premiere->Coefficient !== null ? (float) $premiere->Coefficient : null,
                    'nombre_notes' => $lot->count(),
                    'moyenne' => $lot->avg('Note') !== null ? round((float) $lot->avg('Note'), 2) : null,
                    'note_min' => $lot->min('Note'),
                    'note_max' => $lot->max('Note'),
                ];
            })
            ->sortBy([['classe_code', 'asc'], ['matiere', 'asc']])
            ->values();
    }
}
