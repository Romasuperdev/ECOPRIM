<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Pour l'AFFICHAGE (indicateur « année clôturée » des écrans) : un verdict impossible
     * vaut « non clôturée » — l'écriture, elle, passe par assertModifiable(), qui refuse.
     */
    public static function estCloturee(?string $annee): bool
    {
        return self::verdict($annee) === true;
    }

    /**
     * Vrai = clôturée, faux = ouverte (ou inconnue du référentiel), null = impossible à
     * dire (référentiel illisible, colonne ClotureDefinitive absente…).
     */
    public static function verdict(?string $annee): ?bool
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
     *
     * Et si on ne PEUT PAS savoir, on refuse aussi (503) : l'ancienne règle « base
     * injoignable = on laisse passer » rendait le verrou inopérant sur toute installation
     * où la colonne manquait — c'est-à-dire précisément là où personne ne s'en apercevait.
     */
    public static function assertModifiable(?string $annee, string $quoi = 'Cette opération'): void
    {
        $verdict = self::verdict($annee);

        if ($verdict === null) {
            LectureControle::refuser(
                "Impossible de vérifier si l'année scolaire « ".trim((string) $annee)." » est clôturée : {$quoi} est refusé(e) par prudence. Réessayez dans un instant.",
                null,
                ['annee' => $annee],
            );
        }

        if ($verdict) {
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

    private static function lire(string $reference): ?bool
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
            // Référentiel illisible ou colonne absente : verdict impossible, et le journal
            // dit pourquoi. C'est assertModifiable() qui en tire la conséquence.
            Log::warning("Clôture de l'année illisible.", ['annee' => $reference, 'exception' => $e]);

            return null;
        }

        // Année inconnue du référentiel : pas de verrou (elle n'est pas clôturée).
        if (! $ligne) {
            return false;
        }

        // Ligne lue mais sans la colonne (pilote qui ne lève pas d'erreur) : même incertitude.
        if (! property_exists($ligne, 'ClotureDefinitive')) {
            Log::warning("Colonne ClotureDefinitive absente : clôture de l'année invérifiable.", ['annee' => $reference]);

            return null;
        }

        return (bool) $ligne->ClotureDefinitive;
    }
}
