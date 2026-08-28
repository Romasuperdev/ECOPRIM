<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classe;
use App\Models\Note;
use Illuminate\Http\Request;

class RapportController extends Controller
{
    /**
     * Moyennes pondérées (coefficient) et classement des élèves d'une classe,
     * pour une période donnée (ou toutes périodes confondues si non précisée).
     */
    public function moyennesClasse(Request $request, Classe $classe)
    {
        $rapport = $this->calculerMoyennes($classe, $request->input('periode_id'));

        return [
            'classe' => $classe->nom,
            ...$rapport,
        ];
    }

    public function calculerMoyennes(Classe $classe, $periodeId = null): array
    {
        $eleves = $classe->eleves()
            ->with(['notes' => function ($query) use ($periodeId) {
                $query->when($periodeId, fn ($q) => $q->where('periode_id', $periodeId))
                    ->with('matiere');
            }])
            ->get();

        $resultats = $eleves->map(function ($eleve) {
            $sommePonderee = 0;
            $sommeCoefficients = 0;
            $parMatiere = [];

            foreach ($eleve->notes as $note) {
                $coefficient = $note->coefficient ?: 1;
                $sommePonderee += $note->valeur * $coefficient;
                $sommeCoefficients += $coefficient;

                $matiereId = $note->matiere_id;
                $parMatiere[$matiereId] ??= [
                    'matiere' => $note->matiere?->libelle,
                    'somme' => 0,
                    'coefficients' => 0,
                ];
                $parMatiere[$matiereId]['somme'] += $note->valeur * $coefficient;
                $parMatiere[$matiereId]['coefficients'] += $coefficient;
            }

            $moyenne = $sommeCoefficients > 0 ? round($sommePonderee / $sommeCoefficients, 2) : null;

            return [
                'eleve_id' => $eleve->id,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'matricule' => $eleve->matricule,
                'moyenne' => $moyenne,
                'nombre_notes' => $eleve->notes->count(),
                'moyennes_par_matiere' => collect($parMatiere)->map(fn ($m) => [
                    'matiere' => $m['matiere'],
                    'moyenne' => $m['coefficients'] > 0 ? round($m['somme'] / $m['coefficients'], 2) : null,
                ])->values(),
            ];
        });

        $classes = $resultats->whereNotNull('moyenne')->sortByDesc('moyenne')->values();
        $sansNote = $resultats->whereNull('moyenne')->values();

        $rang = 1;
        $classes = $classes->map(function ($item) use (&$rang) {
            $item['rang'] = $rang++;

            return $item;
        });

        return [
            'moyenne_classe' => $classes->isNotEmpty() ? round($classes->avg('moyenne'), 2) : null,
            'classement' => $classes->values(),
            'sans_note' => $sansNote,
        ];
    }

    /**
     * Assiduité : absences et retards par élève sur une classe (toutes périodes).
     */
    public function assiduiteClasse(Classe $classe)
    {
        $eleves = $classe->eleves()->with('absences', 'retards')->get();

        $lignes = $eleves->map(fn ($eleve) => [
            'eleve_id' => $eleve->id,
            'nom' => $eleve->nom,
            'prenom' => $eleve->prenom,
            'total_absences' => $eleve->absences->count(),
            'absences_non_justifiees' => $eleve->absences->where('justifiee', false)->count(),
            'total_retards' => $eleve->retards->count(),
        ])->sortByDesc('total_absences')->values();

        return [
            'classe' => $classe->nom,
            'eleves' => $lignes,
        ];
    }

    /**
     * "Évaluations" — vue agrégée des sessions de notation existantes
     * (regroupement des notes par matière + date + type), sans table dédiée.
     */
    public function evaluations(Request $request)
    {
        $notes = Note::with('matiere', 'eleve.classe')
            ->when($request->filled('classe_id'), fn ($q) => $q->whereHas(
                'eleve',
                fn ($q) => $q->where('classe_id', $request->input('classe_id'))
            ))
            ->get();

        $groupes = $notes->groupBy(fn ($note) => implode('|', [
            $note->matiere_id,
            $note->eleve->classe_id,
            $note->date_evaluation->format('Y-m-d'),
            $note->type_evaluation,
        ]));

        return $groupes->map(function ($groupe) {
            $premiere = $groupe->first();

            return [
                'matiere' => $premiere->matiere?->libelle,
                'classe' => $premiere->eleve->classe?->nom,
                'date_evaluation' => $premiere->date_evaluation->format('Y-m-d'),
                'type_evaluation' => $premiere->type_evaluation,
                'nombre_notes' => $groupe->count(),
                'moyenne' => round($groupe->avg('valeur'), 2),
            ];
        })->sortByDesc('date_evaluation')->values();
    }
}
