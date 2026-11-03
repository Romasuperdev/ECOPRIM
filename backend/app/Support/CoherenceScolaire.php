<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contrôles de cohérence du rattachement scolaire, au-delà des simples formats.
 *
 * Principe : on bloque sur une incohérence CONSTATÉE. Une valeur absente passe.
 *
 * Référentiel illisible : la classe est un contrôle INDISPENSABLE (un élève rattaché à
 * une classe inexistante disparaît de tous les écrans) — la saisie est refusée. La
 * période d'inscription ne l'est pas : on laisse passer, mais on le journalise.
 */
class CoherenceScolaire
{
    /** La classe doit exister, appartenir au niveau annoncé et à la bonne année. */
    public static function assertClasse(?string $classe, ?string $niveau, ?string $annee): void
    {
        $classe = trim((string) $classe);
        if ($classe === '') {
            return;
        }

        try {
            // Toutes les colonnes : CodN ou ANNEE peuvent manquer selon l'installation, et
            // les nommer ferait échouer la lecture entière. Elles se lisent avec `?? ''`.
            $ligne = DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $classe)->first();
        } catch (Throwable $e) {
            LectureControle::refuser(
                "La classe « {$classe} » n'a pas pu être vérifiée : enregistrement refusé par prudence. Réessayez dans un instant.",
                $e,
            );
        }

        if (! $ligne) {
            throw ValidationException::withMessages([
                'classe_code' => ["La classe « {$classe} » n'existe pas dans le référentiel."],
            ]);
        }

        $niveau = trim((string) $niveau);
        $niveauClasse = trim((string) ($ligne->CodN ?? ''));
        if ($niveau !== '' && $niveauClasse !== '' && $niveau !== $niveauClasse) {
            throw ValidationException::withMessages([
                'classe_code' => ["La classe « {$ligne->LibelleClasse} » relève du niveau {$niveauClasse}, pas de {$niveau}."],
            ]);
        }

        $annee = trim((string) $annee);
        $anneeClasse = trim((string) ($ligne->ANNEE ?? ''));
        if ($annee !== '' && $anneeClasse !== '' && $annee !== $anneeClasse) {
            throw ValidationException::withMessages([
                'classe_code' => ["La classe « {$ligne->LibelleClasse} » appartient à l'année {$anneeClasse}, pas à {$annee}."],
            ]);
        }
    }


    /** La date d'inscription doit tomber dans la période de l'année scolaire. */
    public static function assertDateInscription(?string $date, ?string $annee): void
    {
        $date = trim((string) $date);
        $annee = trim((string) $annee);
        if ($date === '' || $annee === '') {
            return;
        }

        try {
            $ligne = DB::connection('economat')->table('T_ANNEEACADEMIQUE')
                ->where(fn ($q) => $q->where('LibelleAnnee', $annee)->orWhere('CodeAnnee', $annee))
                ->first(['DEBUT', 'FIN', 'LibelleAnnee']);
        } catch (Throwable $e) {
            // Contrôle de confort : on ne bloque pas l'inscription, mais on le dit au journal.
            Log::warning("Période de l'année illisible : date d'inscription non contrôlée.", ['annee' => $annee, 'exception' => $e]);

            return;
        }

        if (! $ligne || ! $ligne->DEBUT || ! $ligne->FIN) {
            return;
        }

        $jour = substr($date, 0, 10);
        $debut = substr((string) $ligne->DEBUT, 0, 10);
        $fin = substr((string) $ligne->FIN, 0, 10);

        if ($jour < $debut || $jour > $fin) {
            throw ValidationException::withMessages([
                'date_inscription' => [
                    "La date d'inscription doit tomber entre le {$debut} et le {$fin}, période de l'année {$annee}.",
                ],
            ]);
        }
    }
}
