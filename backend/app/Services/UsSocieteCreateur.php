<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Écriture MINIMALE et CONTRÔLÉE dans dbmasterbacou.US_SOCIETE.
 *
 * Règle absolue : INSERT uniquement. Cette classe ne fait jamais d'UPDATE ni de DELETE —
 * US_SOCIETE est partagée avec les autres applications, les sociétés existantes ne doivent
 * jamais être modifiées par ECOPRIM. Les compléments/éditions restent dans la surcouche
 * ECOPRIM (console_societes).
 *
 * NUMAUTO est NOT NULL sans valeur par défaut : selon les installations c'est soit une
 * colonne IDENTITY, soit un compteur manuel. Le cas est détecté à l'exécution.
 */
class UsSocieteCreateur
{
    /** Largeurs réelles des colonnes US_SOCIETE (varchar) — au-delà, SQL Server refuse. */
    public const LARGEURS = [
        'code' => 17,          // CODESOCIETE
        'nom' => 50,           // NOMSOCIETE
        'adresse' => 50,       // AD1SOCIETE
        'ville' => 50,         // VILLESOCIETE
        'pays' => 50,          // PAYSSOCIETE
        'telephone' => 20,     // TELSOCIETE
        'email' => 255,        // EMAILSOCIETE
        'representant' => 150, // NOMPRENOMREPRESENTANT
        'nombase' => 50,       // NOMBASE
    ];

    public function existe(string $code): bool
    {
        try {
            return DB::connection('master')->table('US_SOCIETE')->where('CODESOCIETE', $code)->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Insère une nouvelle société dans US_SOCIETE. Ne touche aucune ligne existante.
     *
     * @param  array{code:string,nom:string,ville?:?string,adresse?:?string,pays?:?string,telephone?:?string,email?:?string,representant?:?string,nombase?:?string}  $d
     */
    public function creer(array $d): void
    {
        $ligne = [
            'CODESOCIETE' => $d['code'],
            'NOMSOCIETE' => $d['nom'] ?? null,
            'AD1SOCIETE' => $d['adresse'] ?? null,
            'VILLESOCIETE' => $d['ville'] ?? null,
            'PAYSSOCIETE' => $d['pays'] ?? null,
            'TELSOCIETE' => $d['telephone'] ?? null,
            'EMAILSOCIETE' => $d['email'] ?? null,
            'NOMPRENOMREPRESENTANT' => $d['representant'] ?? null,
            'NOMBASE' => $d['nombase'] ?? null,
        ];

        // NUMAUTO : ne l'alimenter que si ce n'est PAS une colonne IDENTITY.
        if (! $this->numautoEstIdentity()) {
            $ligne['NUMAUTO'] = $this->prochainNumauto();
        }

        DB::connection('master')->table('US_SOCIETE')->insert($ligne);
    }

    /** Vrai si US_SOCIETE.NUMAUTO est auto-incrémentée par SQL Server. */
    private function numautoEstIdentity(): bool
    {
        try {
            $v = DB::connection('master')->selectOne(
                "SELECT COLUMNPROPERTY(OBJECT_ID('dbo.US_SOCIETE'),'NUMAUTO','IsIdentity') AS est_identity"
            );

            return (bool) ($v->est_identity ?? false);
        } catch (Throwable $e) {
            // Moteur qui ne connaît pas COLUMNPROPERTY (SQLite des tests) : compteur manuel.
            return false;
        }
    }

    private function prochainNumauto(): int
    {
        try {
            return ((int) DB::connection('master')->table('US_SOCIETE')->max('NUMAUTO')) + 1;
        } catch (Throwable $e) {
            return 1;
        }
    }
}
