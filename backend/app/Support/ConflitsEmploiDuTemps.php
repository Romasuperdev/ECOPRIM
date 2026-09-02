<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contraintes d'un emploi du temps. Les trois sont bloquantes :
 *
 *  1. une CLASSE ne peut avoir qu'un cours à un créneau donné ;
 *  2. une SALLE ne peut accueillir qu'une classe à la fois ;
 *  3. un ENSEIGNANT ne peut être dans deux classes à la fois.
 *
 * T_EMPLOIDUTEMPS ne porte pas l'enseignant : un créneau est (jour, heure, classe,
 * matière, salle, année) et le professeur est DÉDUIT de T_CORPROFCLASSE, qui dit qui
 * enseigne telle matière dans telle classe pour une année. La détection du conflit
 * enseignant passe donc par cette affectation.
 */
class ConflitsEmploiDuTemps
{
    /**
     * @param  int|null  $sauf  CODE du créneau en cours de modification, à ignorer
     */
    public static function assertLibre(array $d, ?int $sauf = null): void
    {
        self::assertClasseLibre($d, $sauf);
        self::assertSalleLibre($d, $sauf);
        self::assertEnseignantLibre($d, $sauf);
    }

    private static function creneaux(array $d, ?int $sauf)
    {
        return DB::connection('economat')->table('T_EMPLOIDUTEMPS')
            ->where('ANNEE', $d['annee'])
            ->where('CODEJOUR', $d['jour'])
            ->where('CODEHEURE', $d['heure'])
            ->when($sauf, fn ($q) => $q->where('CODE', '!=', $sauf));
    }

    private static function assertClasseLibre(array $d, ?int $sauf): void
    {
        try {
            $occupe = self::creneaux($d, $sauf)->where('CODECLASSE', $d['classe'])->first();
        } catch (Throwable $e) {
            return;
        }

        if ($occupe) {
            throw ValidationException::withMessages([
                'classe' => [
                    'Cette classe a déjà un cours à ce créneau ('
                    .self::libelleMatiere($occupe->CODEMATIERE).').',
                ],
            ]);
        }
    }

    private static function assertSalleLibre(array $d, ?int $sauf): void
    {
        $salle = trim((string) ($d['salle'] ?? ''));
        if ($salle === '') {
            return;
        }

        try {
            $occupe = self::creneaux($d, $sauf)
                ->where('CODESALLE', $salle)
                ->where('CODECLASSE', '!=', $d['classe'])
                ->first();
        } catch (Throwable $e) {
            return;
        }

        if ($occupe) {
            throw ValidationException::withMessages([
                'salle' => [
                    'La salle '.self::libelleSalle($salle).' est déjà occupée à ce créneau par la classe '
                    .self::libelleClasse($occupe->CODECLASSE).'.',
                ],
            ]);
        }
    }

    private static function assertEnseignantLibre(array $d, ?int $sauf): void
    {
        $profs = self::professeurs($d['classe'], $d['matiere'], $d['annee']);
        if ($profs === []) {
            return; // Matière sans professeur affecté : rien à vérifier.
        }

        try {
            $autres = self::creneaux($d, $sauf)
                ->where('CODECLASSE', '!=', $d['classe'])
                ->get(['CODECLASSE', 'CODEMATIERE']);
        } catch (Throwable $e) {
            return;
        }

        foreach ($autres as $creneau) {
            $ailleurs = self::professeurs($creneau->CODECLASSE, $creneau->CODEMATIERE, $d['annee']);
            $communs = array_intersect($profs, $ailleurs);

            if ($communs !== []) {
                throw ValidationException::withMessages([
                    'matiere' => [
                        self::nomProfesseur(reset($communs))
                        .' est déjà en cours à ce créneau avec la classe '
                        .self::libelleClasse($creneau->CODECLASSE).'.',
                    ],
                ]);
            }
        }
    }

    /** Codes des professeurs affectés à (classe, matière, année). */
    private static function professeurs(?string $classe, ?string $matiere, ?string $annee): array
    {
        if (! $classe || ! $matiere) {
            return [];
        }

        try {
            return DB::connection('economat')->table('T_CORPROFCLASSE')
                ->where('CodeClasse', $classe)
                ->where('CodeMatiere', $matiere)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->pluck('CodeProfesseur')
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function nomProfesseur($code): string
    {
        try {
            $p = DB::connection('economat')->table('T_PROFESSEUR')
                ->where('Code', $code)->first(['NomProfesseur', 'PrenomProfesseur']);
        } catch (Throwable $e) {
            $p = null;
        }

        $nom = trim(($p->PrenomProfesseur ?? '').' '.($p->NomProfesseur ?? ''));

        return $nom !== '' ? $nom : "L'enseignant de cette matière";
    }

    private static function libelleMatiere(?string $code): string
    {
        return self::libelle('T_MATIERE', 'CodeMatiere', 'LibelleMatiere', $code);
    }

    private static function libelleClasse(?string $code): string
    {
        return self::libelle('T_CLASSE', 'CodeClasse', 'LibelleClasse', $code);
    }

    private static function libelleSalle(?string $code): string
    {
        return self::libelle('T_SALLESCLASSE', 'CODESALLE', 'LIBELLESALLE', $code);
    }

    private static function libelle(string $table, string $cle, string $colonne, ?string $code): string
    {
        if (! $code) {
            return '—';
        }
        try {
            return (string) (DB::connection('economat')->table($table)
                ->where($cle, $code)->value($colonne) ?: $code);
        } catch (Throwable $e) {
            return (string) $code;
        }
    }
}
