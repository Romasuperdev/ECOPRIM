<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Les tables PROPRES à NEXORA (connexion `ecoprim`) : les créer d'elles-mêmes si elles
 * manquent, pour qu'une mise en ligne par simple copie de fichiers fonctionne sans qu'on
 * ait à lancer `php artisan migrate` sur le serveur.
 *
 * POURQUOI. En ligne, chaque écran qui touche une de ces tables tombait sur « Nom d'objet
 * 'console_affectations' non valide » : la migration n'avait jamais été lancée.
 *
 * GARDE-FOUS — la base `ecoprim` du serveur est ATECH, PARTAGÉE avec Hôtel et Comptabilité :
 *   - refus si `ecoprim` désigne la même base que dbmasterbacou ou ECONOMAT : NEXORA ne crée
 *     jamais de table dans ces deux bases ;
 *   - refus si une table qu'une migration EN ATTENTE doit créer existe déjà : elle appartient
 *     alors à une autre application, et NEXORA ne doit ni la lire ni l'écrire ;
 *   - un seul essai à la fois (verrou), et pas plus d'un essai toutes les 10 minutes après un
 *     échec, pour ne pas ralentir chaque requête ;
 *   - désactivable : NEXORA_INSTALLATION_AUTO=false dans .env.
 * Le compte-rendu de chaque essai est écrit dans storage/logs/laravel.log.
 */
final class TablesPropres
{
    /** Migration => tables qu'elle CRÉE (les migrations qui modifient seulement n'en ont pas). */
    public const MIGRATIONS = [
        '2026_10_01_000001_create_console_societes_table' => ['console_societes'],
        '2026_10_01_000002_create_console_etablissements_table' => ['console_etablissements'],
        '2026_10_01_000003_create_console_roles_table' => ['console_roles'],
        '2026_10_01_000004_create_console_affectations_table' => ['console_affectations'],
        '2026_10_02_000001_add_societe_code_to_console_roles_table' => [],
        '2026_10_03_000001_create_console_affectation_eleves_table' => ['console_affectation_eleves'],
        '2026_10_04_000001_create_evaluations_table' => ['evaluations'],
        '2026_10_05_000001_create_devoirs_table' => ['devoirs'],
        '2026_10_05_000002_create_evenements_table' => ['evenements'],
        '2026_10_06_000001_create_console_role_permissions_table' => ['console_role_permissions'],
        '2026_10_07_000001_create_coefficients_matiere_table' => ['coefficients_matiere'],
        '2026_10_22_000001_create_aide_tables' => ['aide_categories', 'aide_articles'],
        '2026_10_22_000002_create_nouveautes_tables' => ['nouveautes_versions', 'nouveautes_elements', 'nouveautes_lues'],
        '2026_11_27_000001_create_portail_notifications_tables' => ['portail_notifications', 'portail_abonnements_push'],
        '2026_11_27_000002_create_appel_tables' => ['appels', 'appel_lignes', 'absence_justificatifs'],
        '2026_11_27_000003_create_notes_evaluations_table' => ['notes_evaluations'],
    ];

    /**
     * Le constat « tout est installé » est mémorisé pour la liste de migrations CI-DESSUS.
     * Sans cette empreinte, un serveur déjà installé ne voyait jamais une table ajoutée
     * par une mise à jour : le cache disait « ok » pour toujours.
     */
    private const CLE_OK_PREFIXE = 'nexora.tables_propres.ok';
    private const CLE_ECHEC = 'nexora.tables_propres.echec';

    private static function cleOk(): string
    {
        return self::CLE_OK_PREFIXE.'.'.md5(implode('|', array_keys(self::MIGRATIONS)));
    }

    /** Toutes les tables propres à NEXORA. */
    public static function liste(): array
    {
        return array_merge(...array_values(self::MIGRATIONS));
    }

    /** Appelé à chaque requête de l'API : ne coûte qu'une lecture de cache une fois installé. */
    public static function assurer(): void
    {
        if (! config('nexora.installation_auto', true)
            || Cache::get(self::cleOk()) === true
            || Cache::get(self::CLE_ECHEC) === true) {
            return;
        }

        try {
            if (self::manquantes() === []) {
                Cache::forever(self::cleOk(), true);

                return;
            }

            Cache::lock('nexora.tables_propres.verrou', 120)->block(60, function () {
                if (self::manquantes() !== []) { // un autre essai a pu passer entre-temps
                    self::installer();
                }
            });

            if (self::manquantes() === []) {
                Cache::forever(self::cleOk(), true);
            } else {
                Cache::put(self::CLE_ECHEC, true, now()->addMinutes(10));
            }
        } catch (Throwable $e) {
            Log::error('[NEXORA] Installation des tables propres impossible : '.$e->getMessage());
            Cache::put(self::CLE_ECHEC, true, now()->addMinutes(10));
        }
    }

    /** @return string[] les tables propres absentes de la base `ecoprim` */
    public static function manquantes(): array
    {
        $schema = Schema::connection('ecoprim');

        return array_values(array_filter(self::liste(), fn ($t) => ! $schema->hasTable($t)));
    }

    /**
     * Crée les tables manquantes, une migration à la fois, sans jamais toucher une table
     * qui existe déjà.
     *
     * On n'appelle plus `artisan migrate` : sur le serveur, l'historique des migrations
     * (EP_Migrations) ne correspondait plus aux tables réellement présentes, et migrate
     * refusait tout. Ici, chaque migration n'est jouée que si TOUTES ses tables manquent ;
     * si une partie seulement existe (table d'une autre application, ou installation
     * interrompue), elle est laissée de côté et signalée — jamais complétée à l'aveugle.
     *
     * Garde-fou maintenu : jamais dans dbmasterbacou. ECOPRIM et ECONOMAT peuvent en
     * revanche partager la même base (ATECH en ligne : c'est l'architecture voulue).
     */
    public static function installer(): bool
    {
        $base = (string) DB::connection('ecoprim')->getDatabaseName();
        if (strcasecmp($base, (string) config('database.connections.master.database')) === 0) {
            Log::error("[NEXORA] Tables propres NON créées : la connexion ecoprim désigne dbmasterbacou ({$base}). "
                .'Mettez DB_ECOPRIM_DATABASE=ATECH dans .env, puis php artisan config:cache.');

            return false;
        }

        $schema = Schema::connection('ecoprim');
        $creees = [];

        foreach (self::MIGRATIONS as $migration => $tables) {
            $presentes = array_values(array_filter($tables, fn ($t) => $schema->hasTable($t)));

            if ($tables === []) {
                // Migration qui MODIFIE une table : jouée seulement si sa modification manque.
                if ($migration === '2026_10_02_000001_add_societe_code_to_console_roles_table'
                    && $schema->hasTable('console_roles') && ! $schema->hasColumn('console_roles', 'societe_code')) {
                    self::jouer($migration);
                    $creees[] = 'console_roles.societe_code';
                }
                continue;
            }
            if (count($presentes) === count($tables)) {
                continue; // déjà là
            }
            if ($presentes !== []) {
                continue; // partielle : voir conflits()
            }

            self::jouer($migration);
            array_push($creees, ...$tables);
        }

        $conflits = self::conflits();
        if ($conflits !== []) {
            Log::warning("[NEXORA] Tables propres laissées de côté dans {$base} (une partie existe déjà) : "
                .implode(', ', $conflits).'.');
        }
        if ($creees !== []) {
            Log::info("[NEXORA] Tables propres créées dans {$base} : ".implode(', ', $creees).'.');
        }

        return $conflits === [];
    }

    /** Joue le `up()` d'une migration et l'inscrit dans l'historique, s'il existe. */
    private static function jouer(string $migration): void
    {
        $instance = require database_path("migrations/console/{$migration}.php");
        $instance->up();

        try {
            $depot = app('migrator')->getRepository();
            $depot->setSource('ecoprim');
            if (! $depot->repositoryExists()) {
                $depot->createRepository();
            }
            if (! in_array($migration, $depot->getRan(), true)) {
                $depot->log($migration, $depot->getNextBatchNumber());
            }
        } catch (Throwable $e) {
            // L'historique n'est qu'un repère : la table est créée, c'est ce qui compte.
            Log::warning("[NEXORA] Migration {$migration} jouée mais non inscrite : ".$e->getMessage());
        }
    }

    /**
     * Tables d'une migration dont une PARTIE seulement existe : on ne complète pas, car
     * la table présente peut appartenir à une autre application.
     */
    public static function conflits(): array
    {
        $schema = Schema::connection('ecoprim');
        $conflits = [];
        foreach (self::MIGRATIONS as $tables) {
            $presentes = array_values(array_filter($tables, fn ($t) => $schema->hasTable($t)));
            if ($presentes !== [] && count($presentes) < count($tables)) {
                array_push($conflits, ...$presentes);
            }
        }

        return $conflits;
    }

    /** Pour les tests, ou après une correction du .env : oublier le résultat mémorisé. */
    public static function oublier(): void
    {
        Cache::forget(self::cleOk());
        Cache::forget(self::CLE_ECHEC);
    }
}
