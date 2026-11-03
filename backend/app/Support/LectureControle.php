<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Lectures DE CONTRÔLE : celles qui décident si une écriture peut avoir lieu (année
 * clôturée, dépendances avant suppression, conflits d'emploi du temps, doublons…).
 *
 * Règle : une lecture de contrôle qui échoue FERME, elle n'ouvre pas. Longtemps, une
 * erreur y valait « rien trouvé » — zéro dépendance, aucun doublon, année ouverte — et
 * l'écriture passait en silence sur une vérification qui n'avait pas eu lieu. Désormais
 * l'opération est refusée avec un message clair, et la cause réelle part au journal.
 *
 * Seule tolérance gardée : la colonne (ou la table) qui n'existe VRAIMENT pas sur cette
 * installation d'ECONOMAT — constaté par le schéma, pas supposé d'après l'erreur. Ce qui
 * n'existe pas ne peut rien contenir.
 */
class LectureControle
{
    /**
     * La colonne est-elle réellement absente (table absente comprise) ? Vrai seulement si
     * le schéma a pu être lu et ne la contient pas. Schéma illisible : on ne conclut rien,
     * donc faux — l'appelant refusera.
     */
    public static function colonneAbsente(string $connexion, string $table, string $colonne): bool
    {
        try {
            return ! Schema::connection($connexion)->hasColumn($table, $colonne);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Refuse l'opération faute d'avoir pu faire le contrôle. 503 : rien n'est faux dans la
     * demande, c'est la vérification qui n'a pas pu se faire — réessayer peut suffire.
     */
    public static function refuser(string $message, ?Throwable $cause = null, array $contexte = []): never
    {
        Log::warning($message, $contexte + ($cause ? ['exception' => $cause] : []));

        throw new HttpException(503, $message);
    }
}
