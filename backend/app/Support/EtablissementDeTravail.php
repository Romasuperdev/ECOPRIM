<?php

namespace App\Support;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * L'établissement EXPLICITEMENT visé par une écriture qui n'a de sens que pour un
 * établissement : paramètres d'envoi (SMTP, passerelle SMS), communication.
 *
 * Pourquoi pas le simple `etablissement_code` de session « s'il y en a un » : sans
 * établissement choisi, les anciennes requêtes tombaient sur « la dernière configuration
 * enregistrée », celle de n'importe quelle école — et c'est elle qu'on modifiait, ou dont
 * on se servait pour envoyer. Ici, pas d'établissement choisi = refus ; établissement hors
 * du périmètre (société de travail) = refus.
 */
class EtablissementDeTravail
{
    /** Code de l'établissement de travail, choisi et dans le périmètre ; sinon 422 / 403. */
    public static function exiger(): string
    {
        $code = PerimetreEtablissement::code();

        if ($code === null) {
            throw ValidationException::withMessages([
                'etablissement' => ["Choisissez d'abord l'établissement concerné dans l'en-tête."],
            ]);
        }

        $visibles = PerimetreEtablissement::etablissementsVisibles();
        if ($visibles !== null && ! in_array($code, $visibles, true)) {
            throw new HttpException(403, "Cet établissement n'est pas dans votre périmètre.");
        }

        return $code;
    }

    /**
     * Comme exiger(), et le compte doit ADMINISTRER cet établissement : Super Admin, Admin
     * de sa société, ou Admin de cet établissement précis.
     */
    public static function exigerAdministre(?RhUser $user): string
    {
        $code = self::exiger();

        if (! $user || ! $user->peutAdministrerEtablissement($code, self::societeDe($code))) {
            throw new HttpException(403, "Vous n'administrez pas cet établissement.");
        }

        return $code;
    }

    /**
     * Société d'un établissement : ECONOMAT d'abord, puis la surcouche ECOPRIM. Introuvable
     * = null, et seuls le Super Admin et l'Admin de l'établissement passeront alors —
     * jamais un Admin Société sur une appartenance qu'on n'a pas pu établir.
     */
    public static function societeDe(string $code): ?string
    {
        foreach ([
            fn () => DB::connection('economat')->table('BEtablissements')
                ->where('CodeEtablissement', $code)->value('CodeSociete'),
            fn () => DB::connection('ecoprim')->table('console_etablissements')
                ->where('code', $code)->value('societe_code'),
        ] as $lecture) {
            try {
                $societe = trim((string) $lecture());
                if ($societe !== '') {
                    return $societe;
                }
            } catch (Throwable $e) {
                // Source absente : la suivante.
            }
        }

        return null;
    }
}
