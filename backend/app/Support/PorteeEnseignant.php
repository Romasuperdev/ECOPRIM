<?php

namespace App\Support;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Le lien entre un compte enseignant et ses classes, dans les deux sens.
 *
 *   compte → fiche : T_PROFESSEUR.LOGIN = RH_USER.Login (insensible à la casse) ;
 *   fiche → classes : T_CORPROFCLASSE, pour l'année de travail.
 *
 * Même règle que PortailEnseignantController (qui garde sa propre copie, antérieure).
 * Le sens inverse — les comptes des enseignants d'une classe — sert à les prévenir, par
 * exemple quand un parent envoie un justificatif.
 */
final class PorteeEnseignant
{
    public static function professeur(RhUser $compte): ?object
    {
        $login = trim((string) $compte->Login);
        if ($login === '') {
            return null;
        }

        try {
            return DB::connection('economat')->table('T_PROFESSEUR')
                ->whereRaw('LOWER(LTRIM(RTRIM(LOGIN))) = ?', [mb_strtolower($login)])
                ->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return string[] codes des classes enseignées par ce compte, année de travail */
    public static function classes(RhUser $compte): array
    {
        $prof = self::professeur($compte);
        if (! $prof) {
            return [];
        }

        try {
            return ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_CORPROFCLASSE')->where('CodeProfesseur', $prof->Code),
                'ANNEE'
            )->pluck('CodeClasse')
                ->map(fn ($c) => trim((string) $c))
                ->filter()->unique()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return int[] comptes RH_USER des enseignants de cette classe, année de travail */
    public static function comptesDeLaClasse(string $classe): array
    {
        try {
            $codes = ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_CORPROFCLASSE')->where('CodeClasse', $classe),
                'ANNEE'
            )->pluck('CodeProfesseur')->filter()->unique()->all();

            if ($codes === []) {
                return [];
            }

            $logins = DB::connection('economat')->table('T_PROFESSEUR')
                ->whereIn('Code', $codes)
                ->pluck('LOGIN')
                ->map(fn ($l) => mb_strtolower(trim((string) $l)))
                ->filter()->unique()->values()->all();

            if ($logins === []) {
                return [];
            }

            // dbmasterbacou est partagée avec les autres applications : on filtre en SQL.
            $marques = implode(',', array_fill(0, count($logins), '?'));

            return RhUser::on('master')
                ->whereRaw("LOWER(LTRIM(RTRIM(Login))) IN ({$marques})", $logins)
                ->where(fn ($q) => $q->where('Supprimer', false)->orWhereNull('Supprimer'))
                ->pluck('Id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }
}
