<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Écriture contrôlée dans ECONOMAT.dbo.BEtablissements.
 *
 * Règle : CRÉATION et MODIFICATION uniquement — jamais de DELETE. Un établissement
 * retiré du service est désactivé logiquement dans la surcouche ECOPRIM
 * (console_etablissements.actif), pour ne rien casser dans les autres applications
 * de la suite qui lisent cette table.
 */
class BEtablissementEcrivain
{
    /** Largeurs réelles des colonnes (varchar/nvarchar). */
    public const LARGEURS = [
        'code' => 20,        // CodeEtablissement (clé primaire)
        'intitule' => 100,   // Intitule       — NOT NULL
        'adresse' => 150,    // Adresse1       — NOT NULL
        'pays' => 50,        // Pays           — NOT NULL
        'ville' => 50,       // Ville
        'site_web' => 100,   // SiteWeb
        'telephone' => 20,   // Telephone
        'email' => 100,      // Email
        'societe_code' => 20, // CodeSociete   — NOT NULL
    ];

    /** Colonnes NOT NULL côté SQL Server : le formulaire doit les exiger. */
    public const OBLIGATOIRES = ['code', 'intitule', 'adresse', 'pays', 'societe_code'];

    public function existe(string $code): bool
    {
        try {
            return $this->table()->where('CodeEtablissement', $code)->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function tous()
    {
        try {
            return $this->table()->get();
        } catch (Throwable $e) {
            return collect();
        }
    }

    public function trouver(string $code)
    {
        try {
            return $this->table()->where('CodeEtablissement', $code)->first();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function creer(array $d): void
    {
        $this->table()->insert($this->colonnes($d) + [
            'CodeEtablissement' => $d['code'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Modifie une ligne existante. Le code (clé primaire) n'est jamais changé. */
    public function modifier(string $code, array $d): void
    {
        $this->table()->where('CodeEtablissement', $code)
            ->update($this->colonnes($d) + ['updated_at' => now()]);
    }

    private function colonnes(array $d): array
    {
        return [
            'Intitule' => $d['intitule'] ?? '',
            'Adresse1' => $d['adresse'] ?? '',
            'Pays' => $d['pays'] ?? '',
            'Ville' => $d['ville'] ?? null,
            'SiteWeb' => $d['site_web'] ?? null,
            'Telephone' => $d['telephone'] ?? null,
            'Email' => $d['email'] ?? null,
            'CodeSociete' => $d['societe_code'] ?? '',
        ];
    }

    private function table()
    {
        return DB::connection('economat')->table('BEtablissements');
    }
}
