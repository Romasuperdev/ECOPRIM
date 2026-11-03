<?php

namespace App\Console\Commands;

use App\Support\TablesPropres;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Crée tout de suite les tables propres de NEXORA qui manquent (Aide, Nouveautés,
 * console…) dans la base `ecoprim`, sans attendre la première requête de l'API, et dit
 * ce qui a été fait. Ne modifie ni ne supprime jamais une table existante.
 */
class InstallerTables extends Command
{
    protected $signature = 'nexora:installer-tables';

    protected $description = 'Crée les tables propres de NEXORA manquantes (Aide, Nouveautés, console) et affiche le bilan';

    public function handle(): int
    {
        try {
            $base = DB::connection('ecoprim')->getDatabaseName();
            $this->line("Base ecoprim : {$base}");

            $avant = TablesPropres::manquantes();
            if ($avant === []) {
                TablesPropres::oublier();
                $this->info('Toutes les tables propres sont déjà présentes.');

                return self::SUCCESS;
            }
            $this->line('Manquantes : '.implode(', ', $avant));

            TablesPropres::installer();
            TablesPropres::oublier();
        } catch (Throwable $e) {
            $this->error('Échec : '.$e->getMessage());

            return self::FAILURE;
        }

        $apres = TablesPropres::manquantes();
        $creees = array_diff($avant, $apres);
        if ($creees !== []) {
            $this->info('Créées : '.implode(', ', $creees));
        }
        if ($apres !== []) {
            $this->warn('Toujours absentes : '.implode(', ', $apres));
            $conflits = TablesPropres::conflits();
            if ($conflits !== []) {
                $this->warn('Laissées de côté car une table du même groupe existe déjà : '.implode(', ', $conflits));
            }

            return self::FAILURE;
        }
        $this->info('Terminé : toutes les tables propres sont présentes.');

        return self::SUCCESS;
    }
}
