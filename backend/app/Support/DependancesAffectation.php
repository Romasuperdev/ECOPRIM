<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Ce qui dépend, en aval, d'une affectation enseignant ↔ classe ↔ matière.
 *
 * L'emploi du temps ne stocke pas l'enseignant : il le DÉDUIT de T_CORPROFCLASSE. Retirer
 * une affectation laisserait donc des créneaux sans enseignant, et des notes sans origine
 * identifiable. On refuse le retrait dans ces cas, en nommant précisément ce qui bloque —
 * plutôt que de casser silencieusement des données que l'utilisateur ne voit pas d'ici.
 */
class DependancesAffectation
{
    /**
     * ECONOMAT désigne l'année tantôt par son libellé (« 2025-2026 »), tantôt par son code
     * (« 2025 »), et pas de la même façon d'une table à l'autre. On filtre donc sur toutes
     * les écritures possibles plutôt que de parier sur une convention.
     */
    private static function variantes(?string $annee): array
    {
        $valeurs = array_merge([$annee], ContexteScolaire::variantes());

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) $v), $valeurs),
            fn ($v) => $v !== ''
        )));
    }

    /** @return array{creneaux:int, notes:int} */
    public static function compter(?string $classe, ?string $matiere, ?string $annee): array
    {
        return [
            'creneaux' => self::creneaux($classe, $matiere, $annee),
            'notes' => self::notes($classe, $matiere, $annee),
        ];
    }

    public static function assertRetraitPossible(?string $classe, ?string $matiere, ?string $annee): void
    {
        $d = self::compter($classe, $matiere, $annee);

        if ($d['creneaux'] === 0 && $d['notes'] === 0) {
            return;
        }

        $causes = [];
        if ($d['creneaux'] > 0) {
            $causes[] = $d['creneaux'].' créneau'.($d['creneaux'] > 1 ? 'x' : '')." d'emploi du temps";
        }
        if ($d['notes'] > 0) {
            $causes[] = $d['notes'].' note'.($d['notes'] > 1 ? 's' : '').' déjà saisie'.($d['notes'] > 1 ? 's' : '');
        }

        throw new HttpException(409,
            'Cette affectation ne peut pas être retirée : '.implode(' et ', $causes).
            " en dépendent. Videz d'abord les créneaux concernés, ou remplacez l'enseignant".
            ' au lieu de retirer l\'affectation.');
    }

    private static function borner($query, string $colonne, ?string $annee): void
    {
        $variantes = self::variantes($annee);
        if ($variantes !== []) {
            $query->whereIn($colonne, $variantes);
        }
    }

    private static function creneaux(?string $classe, ?string $matiere, ?string $annee): int
    {
        try {
            return (int) DB::connection('economat')->table('T_EMPLOIDUTEMPS')
                ->where('CODECLASSE', $classe)
                ->where('CODEMATIERE', $matiere)
                ->tap(fn ($q) => self::borner($q, 'ANNEE', $annee))
                ->count();
        } catch (Throwable $e) {
            // Table injoignable : on ne bloque pas sur une dépendance qu'on ne peut pas
            // constater, mais on ne l'invente pas non plus.
            return 0;
        }
    }

    private static function notes(?string $classe, ?string $matiere, ?string $annee): int
    {
        try {
            return (int) DB::connection('economat')->table('V_NOTECLASSE')
                ->where('CodeClasse', $classe)
                ->where('CodeMatiere', $matiere)
                ->tap(fn ($q) => self::borner($q, 'CodeAnnee', $annee))
                ->count();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
