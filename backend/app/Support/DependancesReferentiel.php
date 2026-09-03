<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Ce qui dépend, en aval, d'une ligne de référentiel (année, cycle, niveau, classe, matière).
 *
 * Les référentiels d'ECONOMAT ne portent pas de clés étrangères : rien n'empêche
 * techniquement de supprimer une classe qui compte trente élèves, et la base ne dirait rien
 * — les élèves se retrouveraient rattachés à un code qui n'existe plus. On refuse donc le
 * retrait dès qu'une ligne s'y réfère, en nommant précisément ce qui bloque et combien.
 *
 * C'est la même règle que pour les affectations enseignant-classe, étendue à tous les
 * référentiels : supprimer reste possible, mais jamais au prix de données orphelines.
 */
class DependancesReferentiel
{
    /**
     * @param  array<int, array{table:string, colonne:string, libelle:string}>  $liens
     * @param  string|array  $valeur  Valeur, ou plusieurs écritures possibles de la même
     *                                valeur (une année se désigne par son libellé ou son code).
     * @return array<string, int>  Libellé -> nombre de lignes dépendantes.
     */
    public static function compter(array $liens, $valeur): array
    {
        $valeurs = array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), is_array($valeur) ? $valeur : [$valeur]),
            fn ($v) => $v !== ''
        ));

        if ($valeurs === []) {
            return [];
        }

        $comptes = [];
        foreach ($liens as $lien) {
            $n = self::compterUn($lien['table'], $lien['colonne'], $valeurs);
            if ($n > 0) {
                $comptes[$lien['libelle']] = ($comptes[$lien['libelle']] ?? 0) + $n;
            }
        }

        return $comptes;
    }

    public static function assertRetraitPossible(array $liens, $valeur, string $quoi): void
    {
        $comptes = self::compter($liens, $valeur);

        if ($comptes === []) {
            return;
        }

        $causes = [];
        foreach ($comptes as $libelle => $n) {
            $causes[] = $n.' '.$libelle.($n > 1 ? 's' : '');
        }

        throw new HttpException(409,
            $quoi.' ne peut pas être supprimé'.(str_ends_with($quoi, 'e') ? 'e' : '').' : '
            .implode(', ', $causes).' y '.(array_sum($comptes) > 1 ? 'sont rattachés' : 'est rattaché')
            .'. Retirez-les d\'abord, ou laissez cette ligne en place.');
    }

    private static function compterUn(string $table, string $colonne, array $valeurs): int
    {
        try {
            return (int) DB::connection('economat')->table($table)
                ->whereIn($colonne, $valeurs)->count();
        } catch (Throwable $e) {
            // Table ou vue absente sur cette base : on ne bloque pas sur une dépendance
            // qu'on ne peut pas constater, et on ne l'invente pas non plus.
            return 0;
        }
    }
}
