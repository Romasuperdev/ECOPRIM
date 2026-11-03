<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compare la base ECONOMAT du serveur (connexion « economat », ATECH en production) à la
 * structure que l'application attend, et ajoute ce qui manque.
 *
 * Sans option : AUCUNE écriture. La commande liste les tables et colonnes absentes.
 * Avec --appliquer : elle les crée, après confirmation.
 *
 * Elle ne fait QUE des ajouts : jamais de suppression, jamais de modification d'une
 * colonne existante, jamais de données. Les colonnes ajoutées à une table existante sont
 * toujours facultatives (NULL), pour ne gêner aucune autre application qui l'utilise.
 * Les vues (V_*) ne sont que signalées : leur définition ne peut pas être devinée.
 *
 * Structure attendue : database/schema/economat-attendu.json (relevé de la base ECONOMAT
 * de référence par « php artisan schema:introspect »).
 */
class AlignerEconomat extends Command
{
    protected $signature = 'nexora:aligner-economat
        {--toutes : Toute la base ECONOMAT (economat-complet.json), pas seulement les tables de l\'application}
        {--appliquer : Crée les tables et colonnes manquantes}
        {--exporter-vues : (sur le poste de référence) Enregistre la définition des vues ECONOMAT dans database/schema/economat-vues.json}';

    protected $description = "Compare la base ECONOMAT du serveur à la structure attendue et ajoute ce qui manque.";

    public function handle(): int
    {
        if ($this->option('exporter-vues')) {
            return $this->exporterVues();
        }

        // Relevé brut de « schema:introspect » ({base, tables:{…}}) ou liste déjà extraite.
        $fichier = $this->option('toutes') ? 'economat-complet.json' : 'economat-attendu.json';
        $attendu = json_decode((string) @file_get_contents(database_path('schema/'.$fichier)), true);
        if (is_array($attendu) && isset($attendu['tables'])) {
            $attendu = $attendu['tables'];
        }
        if (! is_array($attendu)) {
            $this->error("Fichier database/schema/{$fichier} introuvable ou illisible.");

            return self::FAILURE;
        }

        $db = DB::connection('economat');
        $base = $db->selectOne('SELECT DB_NAME() AS b')->b;
        $this->line("Base contrôlée : <options=bold>{$base}</> (connexion economat)");
        $this->newLine();

        $existantes = [];
        foreach ($db->select("SELECT TABLE_NAME n, TABLE_TYPE y FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo'") as $r) {
            $existantes[strtoupper($r->n)] = $r->y;
        }

        $ordres = [];
        $vuesManquantes = [];

        foreach ($attendu as $table => $def) {
            $estVue = ($def['type'] ?? '') !== 'BASE TABLE';

            if (! isset($existantes[strtoupper($table)])) {
                if ($estVue) {
                    $vuesManquantes[] = $table;
                    $this->line("  <fg=yellow>VUE MANQUANTE</>   {$table}");
                    continue;
                }
                $this->line("  <fg=red>TABLE MANQUANTE</> {$table} (".count($def['colonnes']).' colonnes)');
                $ordres[] = $this->creerTable($table, $def);
                continue;
            }

            if ($estVue) {
                continue;
            }

            $colonnes = array_map('strtoupper', array_column(
                $db->select("SELECT COLUMN_NAME c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = ?", [$table]),
                'c'
            ));

            foreach ($def['colonnes'] as $col) {
                if (! in_array(strtoupper($col['nom']), $colonnes, true)) {
                    $this->line("  <fg=red>COLONNE MANQUANTE</> {$table}.{$col['nom']} ({$this->type($col)})");
                    $ordres[] = "ALTER TABLE [dbo].[{$table}] ADD [{$col['nom']}] {$this->type($col)} NULL".$this->defaut($col);
                }
            }
        }

        $this->newLine();
        if ($ordres === [] && $vuesManquantes === []) {
            $this->info('La base est conforme : rien à ajouter.');

            return self::SUCCESS;
        }

        $definitions = $this->definitionsVues();
        $this->line(count($ordres).' table(s)/colonne(s) à ajouter, '.count($vuesManquantes).' vue(s) manquante(s)'
            .($vuesManquantes === [] ? '.' : ($definitions === []
                ? ' : définitions absentes (lancez --exporter-vues sur le poste de référence).'
                : ' : elles seront créées depuis economat-vues.json.')));

        if (! $this->option('appliquer')) {
            $this->newLine();
            $this->line('Rien n\'a été modifié. Pour appliquer les ajouts :');
            $this->line('  php artisan nexora:aligner-economat'.($this->option('toutes') ? ' --toutes' : '').' --appliquer');

            return self::SUCCESS;
        }

        if ($ordres === [] && $vuesManquantes === []) {
            return self::SUCCESS;
        }

        if (! $this->confirm("Ajouter ces éléments à la base {$base} ? (ajouts seulement, rien n'est supprimé)", false)) {
            $this->line('Annulé : rien n\'a été modifié.');

            return self::SUCCESS;
        }

        $echecs = 0;
        foreach ($ordres as $sql) {
            try {
                $db->statement($sql);
                $this->line('  <fg=green>OK</>    '.strtok($sql, '('));
            } catch (Throwable $e) {
                $echecs++;
                $this->line('  <fg=red>ÉCHEC</> '.strtok($sql, '(').' : '.substr($e->getMessage(), 0, 200));
            }
        }

        $echecs += $this->creerVues($vuesManquantes);

        $this->newLine();
        $echecs === 0
            ? $this->info('Terminé. Relancez la commande sans option pour vérifier.')
            : $this->warn("{$echecs} ajout(s) en échec : voir ci-dessus.");

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, string> nom de vue => « CREATE VIEW … » */
    private function definitionsVues(): array
    {
        $v = json_decode((string) @file_get_contents(database_path('schema/economat-vues.json')), true);

        return is_array($v) ? $v : [];
    }

    /**
     * Une vue peut en lire une autre : on fait plusieurs passes, chacune créant celles dont
     * les dépendances existent déjà. S'arrête quand une passe ne crée plus rien.
     */
    private function creerVues(array $manquantes): int
    {
        $definitions = $this->definitionsVues();
        $restantes = array_values(array_filter($manquantes, fn ($n) => isset($definitions[$n])));
        foreach (array_diff($manquantes, $restantes) as $n) {
            $this->line("  <fg=yellow>VUE IGNORÉE</> {$n} : définition absente d'economat-vues.json");
        }

        $erreurs = [];
        while ($restantes !== []) {
            $creees = 0;
            foreach ($restantes as $i => $nom) {
                try {
                    DB::connection('economat')->unprepared($definitions[$nom]);
                    $this->line("  <fg=green>OK</>    CREATE VIEW {$nom}");
                    unset($restantes[$i], $erreurs[$nom]);
                    $creees++;
                } catch (Throwable $e) {
                    $erreurs[$nom] = substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
                }
            }
            if ($creees === 0) {
                break;
            }
        }

        foreach ($erreurs as $nom => $message) {
            $this->line("  <fg=red>ÉCHEC</> CREATE VIEW {$nom} : {$message}");
        }

        return count($erreurs);
    }

    /** Sur le poste de référence : relève la définition SQL de chaque vue ECONOMAT. */
    private function exporterVues(): int
    {
        $lignes = DB::connection('economat')->select(
            "SELECT v.name AS nom, OBJECT_DEFINITION(v.object_id) AS def FROM sys.views v WHERE SCHEMA_NAME(v.schema_id) = 'dbo' ORDER BY v.name"
        );
        // Une vue qui nomme sa base (« ECONOMAT.dbo.T_X ») ne marcherait pas sur le serveur,
        // où la base s'appelle autrement : on retire ce nom, la vue lira la base courante.
        $base = preg_quote(DB::connection('economat')->selectOne('SELECT DB_NAME() AS b')->b, '/');
        $vues = [];
        foreach ($lignes as $l) {
            if ($l->def !== null) {
                $vues[$l->nom] = preg_replace('/(\\['.$base.'\\]|\\b'.$base.')\\.(?=\\[?dbo\\]?\\.)/i', '', trim($l->def));
            }
        }

        $chemin = database_path('schema/economat-vues.json');
        @mkdir(dirname($chemin), 0775, true);
        file_put_contents($chemin, json_encode($vues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(count($vues)." vue(s) exportée(s) dans {$chemin}");
        $this->line('Copiez ce fichier sur le serveur, au même endroit (backend\database\schema\).');

        return self::SUCCESS;
    }

    private function creerTable(string $table, array $def): string
    {
        $lignes = [];
        foreach ($def['colonnes'] as $col) {
            $lignes[] = "[{$col['nom']}] {$this->type($col)} ".($col['nullable'] ? 'NULL' : 'NOT NULL').$this->defaut($col);
        }
        if (! empty($def['cle_primaire'])) {
            $cles = implode(', ', array_map(fn ($c) => "[{$c}]", $def['cle_primaire']));
            $lignes[] = "CONSTRAINT [PK_{$table}] PRIMARY KEY ({$cles})";
        }

        return "CREATE TABLE [dbo].[{$table}] (\n    ".implode(",\n    ", $lignes)."\n)";
    }

    private function type(array $col): string
    {
        $t = strtolower($col['type']);
        $l = $col['longueur'];

        return match (true) {
            in_array($t, ['varchar', 'nvarchar', 'char', 'nchar', 'varbinary', 'binary'], true) => $t.'('.((int) $l === -1 || $l === null ? 'MAX' : (int) $l).')',
            in_array($t, ['decimal', 'numeric'], true) => $t.'(18, 2)',
            default => $t,
        };
    }

    private function defaut(array $col): string
    {
        return ! empty($col['defaut']) ? ' DEFAULT '.$col['defaut'] : '';
    }
}
