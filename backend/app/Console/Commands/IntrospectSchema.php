<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Introspecte le schéma réel des bases SQL Server branchées (ECONOMAT en lecture seule
 * pour l'application, dbmasterbacou pour la Console) et écrit tables + colonnes + clés
 * primaires dans storage/app/schema/*.json et un récapitulatif *.md.
 *
 * À lancer sur la machine où tournent réellement ces bases (SQL Server local) :
 *   php artisan schema:introspect
 * Les fichiers produits servent ensuite à câbler précisément les modèles Eloquent.
 */
class IntrospectSchema extends Command
{
    protected $signature = 'schema:introspect {--connection=* : Connexions à introspecter (défaut : economat, master)}';

    protected $description = 'Exporte le schéma réel (tables/colonnes/clés) des bases ECONOMAT et dbmasterbacou';

    public function handle(): int
    {
        $connexions = $this->option('connection') ?: ['economat', 'master'];
        $dossier = storage_path('app/schema');
        File::ensureDirectoryExists($dossier);

        foreach ($connexions as $connexion) {
            $this->info("→ Introspection de la connexion « {$connexion} »...");

            try {
                $base = DB::connection($connexion)->getDatabaseName();
                $colonnes = DB::connection($connexion)->select(
                    'SELECT TABLE_TYPE, c.TABLE_NAME, c.COLUMN_NAME, c.ORDINAL_POSITION, c.DATA_TYPE,
                            c.CHARACTER_MAXIMUM_LENGTH, c.IS_NULLABLE, c.COLUMN_DEFAULT
                     FROM INFORMATION_SCHEMA.COLUMNS c
                     JOIN INFORMATION_SCHEMA.TABLES t
                       ON t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
                     ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION'
                );

                $pks = DB::connection($connexion)->select(
                    "SELECT ku.TABLE_NAME, ku.COLUMN_NAME
                     FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                     JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku
                       ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                     WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'"
                );
            } catch (\Throwable $e) {
                $this->error("   ✗ Échec ({$connexion}) : ".$e->getMessage());

                continue;
            }

            $tables = [];
            foreach ($colonnes as $c) {
                $tables[$c->TABLE_NAME]['type'] = $c->TABLE_TYPE;
                $tables[$c->TABLE_NAME]['colonnes'][] = [
                    'nom' => $c->COLUMN_NAME,
                    'type' => $c->DATA_TYPE,
                    'longueur' => $c->CHARACTER_MAXIMUM_LENGTH,
                    'nullable' => $c->IS_NULLABLE === 'YES',
                    'defaut' => $c->COLUMN_DEFAULT,
                ];
            }
            foreach ($pks as $pk) {
                $tables[$pk->TABLE_NAME]['cle_primaire'][] = $pk->COLUMN_NAME;
            }
            ksort($tables);

            $slug = preg_replace('/[^A-Za-z0-9_-]/', '_', strtolower($base ?: $connexion));
            $jsonPath = "{$dossier}/{$slug}.json";
            File::put($jsonPath, json_encode([
                'connexion' => $connexion,
                'base' => $base,
                'nb_tables' => count($tables),
                'tables' => $tables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Récapitulatif lisible
            $md = "# Schéma {$base} (connexion {$connexion})\n\n".count($tables)." objets.\n\n";
            foreach ($tables as $nom => $t) {
                $pk = isset($t['cle_primaire']) ? ' — PK: '.implode(', ', $t['cle_primaire']) : '';
                $md .= "## {$nom} ({$t['type']}){$pk}\n";
                foreach ($t['colonnes'] as $col) {
                    $len = $col['longueur'] ? "({$col['longueur']})" : '';
                    $n = $col['nullable'] ? 'NULL' : 'NOT NULL';
                    $md .= "- {$col['nom']} : {$col['type']}{$len} {$n}\n";
                }
                $md .= "\n";
            }
            File::put("{$dossier}/{$slug}.md", $md);

            $this->info("   ✓ {$base} : ".count($tables)." objets → storage/app/schema/{$slug}.json + .md");
        }

        $this->newLine();
        $this->info('Terminé. Fichiers dans backend/storage/app/schema/.');

        return self::SUCCESS;
    }
}
