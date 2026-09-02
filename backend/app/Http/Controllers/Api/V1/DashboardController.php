<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AnneeScolaire;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Enseignant;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tableau de bord — indicateurs lus dans ECONOMAT.
 *
 * Chaque indicateur est calculé isolément : une vue absente ou une table injoignable
 * renvoie null pour ce seul chiffre au lieu de faire tomber toute la page.
 * Les anciens indicateurs « retards » et « sanctions » ont été retirés : ils
 * s'appuyaient sur des tables locales qui n'existent pas dans ECONOMAT.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $anneeActive = $this->sansErreur(fn () => AnneeScolaire::where('Activer', true)->first()?->libelle);
        $totalEleves = $this->sansErreur(fn () => Eleve::count()) ?? 0;
        $absencesCeMois = $this->sansErreur(fn () => $this->absencesCeMois());

        return [
            'annee_scolaire_active' => $anneeActive,
            'effectifs' => [
                'total_eleves' => $totalEleves,
                'total_classes' => $this->sansErreur(fn () => Classe::count()) ?? 0,
                'total_enseignants' => $this->sansErreur(fn () => Enseignant::count()) ?? 0,
            ],
            'moyenne_generale' => $this->sansErreur(fn () => $this->moyenneGenerale()),
            'absences_ce_mois' => $absencesCeMois,
            'taux_assiduite' => ($totalEleves > 0 && $absencesCeMois !== null)
                ? round(100 - min(100, ($absencesCeMois / $totalEleves) * 100), 1)
                : null,
            'effectif_par_classe' => $this->sansErreur(fn () => $this->effectifParClasse()) ?? [],
            'moyenne_par_classe' => $this->sansErreur(fn () => $this->moyenneParClasse()) ?? [],
        ];
    }

    private function absencesCeMois(): int
    {
        return (int) DB::connection('economat')->table('T_ABSENCEELEVE')
            ->whereMonth('Date', now()->month)
            ->whereYear('Date', now()->year)
            ->count();
    }

    /** Moyenne des moyennes élèves (V_MOYENNE_ELEVE_CLASSE). */
    private function moyenneGenerale(): ?float
    {
        $moyenne = DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')->avg('Moyenne');

        return $moyenne !== null ? round((float) $moyenne, 2) : null;
    }

    private function effectifParClasse(): array
    {
        $libelles = DB::connection('economat')->table('T_CLASSE')
            ->pluck('LibelleClasse', 'CodeClasse');

        return DB::connection('economat')->table('T_ETUDIANT')
            ->select('CodeClasse', DB::raw('COUNT(*) as effectif'))
            ->whereNotNull('CodeClasse')
            ->groupBy('CodeClasse')
            ->orderBy('CodeClasse')
            ->get()
            ->map(fn ($l) => [
                'classe' => $libelles[$l->CodeClasse] ?? $l->CodeClasse,
                'effectif' => (int) $l->effectif,
            ])
            ->all();
    }

    private function moyenneParClasse(): array
    {
        $libelles = DB::connection('economat')->table('T_CLASSE')
            ->pluck('LibelleClasse', 'CodeClasse');

        return DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')
            ->select('CodeClasse', DB::raw('AVG(Moyenne) as moyenne'))
            ->whereNotNull('CodeClasse')
            ->groupBy('CodeClasse')
            ->orderBy('CodeClasse')
            ->get()
            ->map(fn ($l) => [
                'classe' => $libelles[$l->CodeClasse] ?? $l->CodeClasse,
                'moyenne' => $l->moyenne !== null ? round((float) $l->moyenne, 2) : null,
            ])
            ->filter(fn ($r) => $r['moyenne'] !== null)
            ->values()
            ->all();
    }

    /** Isole chaque indicateur : une source manquante ne fait pas tomber la page. */
    private function sansErreur(callable $calcul)
    {
        try {
            return $calcul();
        } catch (Throwable $e) {
            return null;
        }
    }
}
