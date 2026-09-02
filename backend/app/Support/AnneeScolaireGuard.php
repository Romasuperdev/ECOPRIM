<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Règle de gestion : une année scolaire CLÔTURÉE est en consultation seule.
 * Aucun ajout, aucune modification, aucune suppression sur les données qui s'y
 * rattachent — sans exception, y compris pour un Super Admin.
 *
 * Les écritures NEXORA désignent l'année par son libellé ou son code
 * (T_ETUDIANT.AnneeAcad, T_PREREQUIS.ANNEE, T_PROFESSEUR.CodeAnnee), pas par la clé
 * numérique de T_ANNEEACADEMIQUE : la correspondance est donc cherchée sur
 * CodeAnnee, LibelleAnnee et, si la valeur est numérique, sur CODE.
 */
class AnneeScolaireGuard
{
    /** Cache par requête : la même année est contrôlée plusieurs fois par appel. */
    private static array $cache = [];

    public static function estCloturee(?string $annee): bool
    {
        $reference = trim((string) $annee);
        if ($reference === '') {
            return false;
        }

        if (array_key_exists($reference, self::$cache)) {
            return self::$cache[$reference];
        }

        return self::$cache[$reference] = self::lire($reference);
    }

    /**
     * Bloque l'opération si l'année est clôturée.
     * 423 Locked : la ressource existe mais est verrouillée — pas une erreur de saisie.
     */
    public static function assertModifiable(?string $annee, string $quoi = 'Cette opération'): void
    {
        if (self::estCloturee($annee)) {
            throw new HttpException(
                423,
                "L'année scolaire « ".trim((string) $annee)." » est clôturée : elle est en consultation seule. {$quoi} est impossible."
            );
        }
    }

    /** Vide le cache — utile entre deux cas de test. */
    public static function oublier(): void
    {
        self::$cache = [];
    }

    private static function lire(string $reference): bool
    {
        try {
            $ligne = DB::connection('economat')->table('T_ANNEEACADEMIQUE')
                ->where(function ($q) use ($reference) {
                    $q->where('CodeAnnee', $reference)->orWhere('LibelleAnnee', $reference);
                    if (ctype_digit($reference)) {
                        $q->orWhere('CODE', (int) $reference);
                    }
                })
                ->first(['ClotureDefinitive']);
        } catch (Throwable $e) {
            // Base injoignable : on ne bloque pas une saisie sur une incertitude technique.
            return false;
        }

        // Année inconnue du référentiel : pas de verrou (elle n'est pas clôturée).
        return $ligne ? (bool) $ligne->ClotureDefinitive : false;
    }
}
