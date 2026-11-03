<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->limiterLaConnexion();
        $this->datesSansAmbiguite();
    }

    /**
     * SQL Server lit une date selon la LANGUE du compte SQL. En français, « 2026-10-28 »
     * se lit année-JOUR-mois : le 28e mois n'existe pas et l'enregistrement échoue —
     * seulement du 13 au 31 de chaque mois, et seulement sur un serveur dont le compte est
     * en français. C'est ce qui faisait échouer en ligne, et jamais en local, les devoirs,
     * barèmes, évaluations ou absences (toute colonne datetime, dont created_at).
     *
     * SET DATEFORMAT ymd, posé à chaque ouverture de connexion, fige la lecture
     * année-mois-jour quelle que soit la langue du compte.
     */
    private function datesSansAmbiguite(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $e) {
            if ($e->connection->getDriverName() === 'sqlsrv') {
                try {
                    $e->connection->unprepared('SET DATEFORMAT ymd;');
                } catch (\Throwable $erreur) {
                    report($erreur);
                }
            }
        });
    }

    /**
     * Limiteur `login` — POST /login et POST /etablissement-du-compte.
     *
     * Un compte RH_USER ouvre TOUTE la suite : sans limite, /login se prêtait à l'essai
     * de mots de passe en rafale. Deux plafonds cumulés :
     *   - 5 par minute pour un même couple identifiant + adresse IP — l'essai de mots de
     *     passe sur un compte ;
     *   - un plafond par adresse IP, toutes saisies confondues — l'essai d'un même mot de
     *     passe sur beaucoup de comptes, ou l'énumération d'identifiants. Assez large pour
     *     une salle des maîtres qui sort par une seule adresse (NAT).
     * Les deux routes ont chacune leurs compteurs : afficher l'établissement d'un
     * identifiant ne consomme pas les essais de mot de passe.
     */
    private function limiterLaConnexion(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $route = $request->is('*etablissement-du-compte') ? 'etablissement' : 'login';
            $saisie = $request->input($route === 'login' ? 'email' : 'identifiant');
            $identifiant = is_string($saisie) ? mb_strtolower(trim($saisie)) : '';
            $ip = (string) $request->ip();

            $reponse = fn (Request $request, array $headers) => response()->json([
                'message' => 'Trop de tentatives. Réessayez dans '.($headers['Retry-After'] ?? 60).' secondes.',
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by("{$route}|{$identifiant}|{$ip}")->response($reponse),
                Limit::perMinute($route === 'login' ? 30 : 10)->by("{$route}|ip|{$ip}")->response($reponse),
            ];
        });
    }
}
