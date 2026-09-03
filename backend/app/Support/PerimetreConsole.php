<?php

namespace App\Support;

use App\Models\Console\Societe;
use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Périmètre de la Console Administrative.
 *
 * Deux sortes d'administrateur, et une seule règle pour les distinguer :
 *   - le Super Admin administre la console générale ET la console de chaque société ;
 *   - l'Admin Société n'administre que la ou les sociétés auxquelles il est affecté.
 *
 * La « société courante » est portée par la session, comme l'année de travail : le Super
 * Admin la choisit dans l'en-tête (aucun choix = console générale, toutes sociétés
 * confondues), l'Admin Société la reçoit figée sur la sienne.
 *
 * Fail closed : tout ce qui n'est pas explicitement autorisé est refusé. On ne se replie
 * jamais sur « périmètre vide, donc tout montrer ».
 */
class PerimetreConsole
{
    public const CLE_SESSION = 'console_societe';

    /** Codes des sociétés que l'utilisateur peut administrer, ou null s'il les a toutes. */
    public static function codesAutorises(?RhUser $user = null): ?array
    {
        $user = $user ?: auth()->user();

        if (! $user) {
            return [];
        }

        return $user->isSuperAdmin() ? null : $user->societesAdministrees();
    }

    /**
     * Société de travail courante : son code, ou null pour la console générale.
     *
     * Un Admin Société n'a jamais null — s'il n'a qu'une société elle est implicite, et
     * s'il en a plusieurs sans en avoir choisi une, on prend la première dans l'ordre
     * alphabétique plutôt que de lui ouvrir une vue transverse.
     */
    public static function codeCourant(?RhUser $user = null): ?string
    {
        $user = $user ?: auth()->user();
        if (! $user) {
            return null;
        }

        $choisi = trim((string) session(self::CLE_SESSION, ''));

        if ($user->isSuperAdmin()) {
            return $choisi !== '' ? $choisi : null;
        }

        $autorises = $user->societesAdministrees();
        if ($autorises === []) {
            return null;
        }

        if ($choisi !== '' && in_array($choisi, $autorises, true)) {
            return $choisi;
        }

        sort($autorises);

        return $autorises[0];
    }

    /** Refuse l'accès à une société hors périmètre. */
    public static function assertAutorisee(?string $code): void
    {
        $user = auth()->user();

        if (! $user || ! $user->peutAdministrerSociete($code)) {
            throw new HttpException(403, "Cette société n'est pas dans votre périmètre d'administration.");
        }
    }

    /**
     * Applique le périmètre à une requête sur une colonne de code société.
     *
     * Trois cas : le Super Admin sans société choisie ne filtre rien ; avec une société
     * choisie il s'y limite ; l'Admin Société est borné à la société courante.
     */
    public static function appliquer($query, string $colonne = 'societe_code')
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $courant = self::codeCourant($user);

        if ($user->isSuperAdmin()) {
            return $courant === null ? $query : $query->where($colonne, $courant);
        }

        // Fail closed : un non-Super Admin sans société administrée ne voit rien.
        return $courant === null
            ? $query->whereRaw('1 = 0')
            : $query->where($colonne, $courant);
    }

    /**
     * Sociétés proposées dans le sélecteur de l'en-tête, libellé compris.
     *
     * La source est la même que la page Sociétés : la surcouche console_societes complétée
     * par US_SOCIETE, pour qu'une société existante apparaisse même sans reprise.
     */
    public static function disponibles(?RhUser $user = null): array
    {
        $user = $user ?: auth()->user();
        if (! $user) {
            return [];
        }

        $autorises = self::codesAutorises($user);
        $noms = [];

        try {
            foreach (Societe::orderBy('nom')->get() as $s) {
                $code = trim((string) $s->code);
                if ($code !== '') {
                    $noms[$code] = $s->nom ?: $code;
                }
            }
        } catch (Throwable $e) {
            // Surcouche absente : on se contente d'US_SOCIETE.
        }

        try {
            foreach (DB::connection('master')->table('US_SOCIETE')->get() as $l) {
                $code = trim((string) ($l->CODESOCIETE ?? ''));
                if ($code !== '' && ! isset($noms[$code])) {
                    $noms[$code] = trim((string) ($l->NOMSOCIETE ?? '')) ?: $code;
                }
            }
        } catch (Throwable $e) {
            // dbmasterbacou injoignable : on se contente de la surcouche.
        }

        if ($autorises !== null) {
            $noms = array_intersect_key($noms, array_flip($autorises));
            // Une société administrée mais inconnue des deux sources reste proposée,
            // sinon l'admin se retrouverait sans aucune société sélectionnable.
            foreach ($autorises as $code) {
                $noms[$code] ??= $code;
            }
        }

        ksort($noms);

        return collect($noms)->map(fn ($nom, $code) => ['code' => $code, 'nom' => $nom])
            ->values()->all();
    }
}
