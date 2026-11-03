<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Les sociétés auxquelles un compte est lié (dbmasterbacou.societe_utilisateur, écran
 * « Société - Utilisateurs » de la console).
 *
 * Règle : un compte ne s'affecte qu'à des établissements de la (des) société(s) à
 * laquelle il est lié. Un compte encore lié à aucune société n'est pas bloqué ici : il
 * prend la société de son premier établissement (règle « une seule société » de
 * l'AffectationController).
 */
final class SocietesDuCompte
{
    /** @return string[] codes société */
    public static function de(int $rhUserId): array
    {
        try {
            return DB::connection('master')->table('societe_utilisateur')
                ->where('user_id', $rhUserId)->pluck('societe_id')
                ->map(fn ($c) => trim((string) $c))->filter()->unique()->values()->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function exigerEtablissementDeSaSociete(int $rhUserId, ?string $societeEtablissement): void
    {
        $societes = self::de($rhUserId);
        if ($societes === []) {
            return;
        }

        $societe = mb_strtoupper(trim((string) $societeEtablissement));
        foreach ($societes as $s) {
            if (mb_strtoupper($s) === $societe) {
                return;
            }
        }

        throw ValidationException::withMessages(['etablissement_code' => [
            'Cet établissement n’appartient pas à la société de ce compte ('.implode(', ', $societes).') : '
            .'choisissez un établissement de sa société.',
        ]]);
    }
}
