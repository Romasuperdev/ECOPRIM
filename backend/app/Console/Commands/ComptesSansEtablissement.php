<?php

namespace App\Console\Commands;

use App\Models\RhUser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Liste les comptes actifs qui ne pourront plus se connecter : ni Super Admin, ni
 * établissement de rattachement (RH_USER.Etab), ni affectation console active.
 * Lecture seule. À lancer avant / après la mise à jour pour les affecter dans la console.
 */
class ComptesSansEtablissement extends Command
{
    protected $signature = 'nexora:comptes-sans-etablissement';

    protected $description = 'Liste les comptes actifs non affectés à un établissement (ils ne peuvent pas se connecter)';

    public function handle(): int
    {
        $lignes = [];
        RhUser::query()->orderBy('Login')->chunk(500, function ($comptes) use (&$lignes) {
            foreach ($comptes as $c) {
                try {
                    if ($c->estSupprime() || $c->estAffecteAUnEtablissement()) {
                        continue;
                    }
                } catch (Throwable $e) {
                    continue;
                }
                $lignes[] = [$c->Id, $c->Login, trim($c->Nom.' '.$c->Prenom), $c->Email];
            }
        });

        if ($lignes === []) {
            $this->info('Aucun : tous les comptes actifs sont affectés à un établissement.');

            return self::SUCCESS;
        }

        $this->warn(count($lignes).' compte(s) actif(s) sans établissement — ils ne peuvent pas se connecter :');
        $this->table(['Id', 'Login', 'Nom', 'Email'], $lignes);
        $this->line("Affectez-les dans la console : écran Affectations (ou Super Admin si c'est voulu).");

        return self::SUCCESS;
    }
}
