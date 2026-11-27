<?php

namespace App\Console\Commands;

use App\Services\Notifications\WebPush;
use Illuminate\Console\Command;

/**
 * Affiche les clés des notifications push, ou en génère de nouvelles.
 *
 * Inutile en temps normal : WebPush les crée tout seul au premier besoin
 * (storage/app/push-vapid.json). Sert à les fixer dans .env, par exemple quand plusieurs
 * serveurs doivent partager les mêmes abonnements.
 */
class ClesPush extends Command
{
    protected $signature = 'nexora:cles-push {--nouvelles : Générer une nouvelle paire (rend caducs les abonnements existants)}';

    protected $description = 'Affiche (ou génère) les clés VAPID des notifications push';

    public function handle(WebPush $push): int
    {
        if ($this->option('nouvelles')) {
            $cles = $push->generer();
            $this->warn('Nouvelle paire générée. Les téléphones déjà abonnés devront se réabonner.');
            $this->line('');
            $this->line('NEXORA_PUSH_CLE_PUBLIQUE='.$cles['publique']);
            $this->line('NEXORA_PUSH_CLE_PRIVEE='.base64_encode($cles['privee']));
            $this->line('');
            $this->info('Recopiez ces deux lignes dans .env, puis : php artisan config:cache');

            return self::SUCCESS;
        }

        $publique = $push->clePublique();
        if ($publique === null) {
            $this->error('Notifications push indisponibles : voir storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info('Notifications push prêtes.');
        $this->line('Clé publique : '.$publique);
        $this->line(config('nexora.push.cle_publique')
            ? 'Source : .env'
            : 'Source : '.$push->fichierCles().' (à ne pas supprimer)');

        return self::SUCCESS;
    }
}
