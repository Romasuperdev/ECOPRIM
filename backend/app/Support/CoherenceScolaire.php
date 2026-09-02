<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contrôles de cohérence du rattachement scolaire, au-delà des simples formats.
 *
 * Principe : on ne bloque que sur une incohérence CONSTATÉE. Si le référentiel est
 * injoignable ou la valeur absente, on laisse passer — on ne refuse pas une saisie
 * sur une incertitude technique.
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
            $ligne = DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $classe)->first(['CodeClasse', 'LibelleClasse', 'CodN', 'ANNEE']);
        } catch (Throwable $e) {
            return;
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

    /** Le niveau doit appartenir au cycle annoncé. */
    public static function assertNiveau(?string $niveau, ?string $cycle): void
    {
        $niveau = trim((string) $niveau);
        $cycle = trim((string) $cycle);
        if ($niveau === '' || $cycle === '') {
            return;
        }

        try {
            $ligne = DB::connection('economat')->table('T_NIVEAU')
                ->where('CodeNiveau', $niveau)->first(['LibelleNiveau', 'CodeCycle']);
        } catch (Throwable $e) {
            return;
        }

        if (! $ligne) {
            throw ValidationException::withMessages([
                'niveau_code' => ["Le niveau « {$niveau} » n'existe pas dans le référentiel."],
            ]);
        }

        $cycleNiveau = trim((string) ($ligne->CodeCycle ?? ''));
        if ($cycleNiveau !== '' && $cycle !== $cycleNiveau) {
            throw ValidationException::withMessages([
                'niveau_code' => ["Le niveau « {$ligne->LibelleNiveau} » relève du cycle {$cycleNiveau}, pas de {$cycle}."],
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
