<?php

namespace App\Console\Commands;

use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\Console\Societe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Peuple les tables propres de la Console ECOPRIM (base `ecoprim`) à partir des
 * bases partagées, en LECTURE SEULE :
 *   - console_societes      <- dbmasterbacou.US_SOCIETE
 *   - console_etablissements <- ECONOMAT.dbo.BEtablissements (CodeSociete -> societe_code)
 *   - console_roles         <- catalogue par défaut
 * Idempotent : upsert par `code`, ré-exécutable sans doublon. ECOPRIM ne modifie
 * jamais US_SOCIETE ni BEtablissements.
 */
class ConsoleImporter extends Command
{
    protected $signature = 'console:importer {--roles-seulement : Ne (re)crée que le catalogue de rôles}';

    protected $description = 'Importe sociétés (US_SOCIETE) et établissements (BEtablissements) dans les tables propres de la Console, et sème les rôles par défaut.';

    public function handle(): int
    {
        $this->semerRoles();

        if ($this->option('roles-seulement')) {
            $this->info('Rôles par défaut vérifiés.');

            return self::SUCCESS;
        }

        $this->importerSocietes();
        $this->importerEtablissements();

        return self::SUCCESS;
    }

    private function semerRoles(): void
    {
        $defauts = [
            'super-admin' => 'Super Admin',
            'admin-societe' => 'Admin Société',
            'admin-etablissement' => 'Admin Établissement',
            'direction' => 'Direction',
            'enseignant' => 'Enseignant',
            'secretaire' => 'Secrétaire',
            'surveillant' => 'Surveillant',
            'comptable' => 'Comptable',
        ];

        $n = 0;
        foreach ($defauts as $code => $nom) {
            $role = Role::firstOrCreate(['code' => $code], ['nom' => $nom]);
            if ($role->wasRecentlyCreated) {
                $n++;
            }
        }
        $this->info("Rôles : {$n} créé(s) (".count($defauts).' au catalogue).');
    }

    private function importerSocietes(): void
    {
        $lignes = DB::connection('master')->table('US_SOCIETE')->get();
        $crees = $maj = 0;

        foreach ($lignes as $l) {
            $code = trim((string) ($l->CODESOCIETE ?? ''));
            if ($code === '') {
                continue;
            }

            $donnees = [
                'nom' => trim((string) ($l->NOMSOCIETE ?? '')) ?: $code,
                'ville' => $l->VILLESOCIETE ?? null,
                'adresse' => $l->AD1SOCIETE ?? ($l->ADRESSE ?? null),
                'telephone' => $l->TELSOCIETE ?? null,
                'email' => $l->EMAILSOCIETE ?? null,
                'representant' => $l->NOMPRENOMREPRESENTANT ?? ($l->REPRESENTANT ?? null),
            ];

            $societe = Societe::where('code', $code)->first();
            if ($societe) {
                $societe->update($donnees);
                $maj++;
            } else {
                Societe::create(['code' => $code, 'actif' => true] + $donnees);
                $crees++;
            }
        }
        $this->info("Sociétés : {$crees} créée(s), {$maj} mise(s) à jour.");
    }

    private function importerEtablissements(): void
    {
        $lignes = DB::connection('economat')->table('BEtablissements')->get();
        $codesSocietes = Societe::pluck('code')->all();
        $crees = $maj = $orphelins = 0;

        foreach ($lignes as $l) {
            $code = trim((string) ($l->CodeEtablissement ?? ''));
            if ($code === '') {
                continue;
            }

            $societeCode = trim((string) ($l->CodeSociete ?? ''));
            if ($societeCode === '' || ! in_array($societeCode, $codesSocietes, true)) {
                $orphelins++;

                continue; // pas de société connue -> on n'importe pas (intégrité)
            }

            $donnees = [
                'intitule' => trim((string) ($l->Intitule ?? '')) ?: $code,
                'adresse' => $l->Adresse1 ?? null,
                'ville' => $l->Ville ?? null,
                'pays' => $l->Pays ?? null,
                'telephone' => $l->Telephone ?? null,
                'email' => $l->Email ?? null,
                'site_web' => $l->SiteWeb ?? null,
                'societe_code' => $societeCode,
            ];

            $etab = Etablissement::where('code', $code)->first();
            if ($etab) {
                $etab->update($donnees);
                $maj++;
            } else {
                Etablissement::create(['code' => $code, 'actif' => true] + $donnees);
                $crees++;
            }
        }
        $msg = "Établissements : {$crees} créé(s), {$maj} mis à jour.";
        if ($orphelins > 0) {
            $msg .= " {$orphelins} ignoré(s) (société inconnue).";
        }
        $this->info($msg);
    }
}
