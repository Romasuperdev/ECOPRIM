<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Enseignant;
use App\Models\Note;
use App\Models\Retard;
use App\Models\Sanction;

class DashboardController extends Controller
{
    public function index()
    {
        $anneeActive = AnneeScolaire::where('active', true)->first();
        $totalEleves = Eleve::count();

        $absencesCeMois = Absence::whereMonth('date_absence', now()->month)
            ->whereYear('date_absence', now()->year)
            ->count();

        return [
            'annee_scolaire_active' => $anneeActive?->libelle,
            'effectifs' => [
                'total_eleves' => $totalEleves,
                'total_classes' => Classe::count(),
                'total_enseignants' => Enseignant::count(),
            ],
            'moyenne_generale' => round((float) Note::avg('valeur'), 2),
            'absences_ce_mois' => $absencesCeMois,
            'retards_ce_mois' => Retard::whereMonth('date_retard', now()->month)
                ->whereYear('date_retard', now()->year)
                ->count(),
            'sanctions_ce_mois' => Sanction::whereMonth('date_sanction', now()->month)
                ->whereYear('date_sanction', now()->year)
                ->count(),
            'taux_assiduite' => $totalEleves > 0
                ? round(100 - min(100, ($absencesCeMois / $totalEleves) * 100), 1)
                : null,
            'effectif_par_classe' => Classe::withCount('eleves')
                ->get()
                ->map(fn ($classe) => ['classe' => $classe->nom, 'effectif' => $classe->eleves_count]),
            'moyenne_par_classe' => Classe::with('eleves.notes')
                ->get()
                ->map(function ($classe) {
                    $notes = $classe->eleves->flatMap->notes;

                    return [
                        'classe' => $classe->nom,
                        'moyenne' => $notes->isNotEmpty() ? round($notes->avg('valeur'), 2) : null,
                    ];
                })
                ->filter(fn ($row) => $row['moyenne'] !== null)
                ->values(),
        ];
    }
}
