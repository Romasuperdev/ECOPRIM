<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Origines acceptées par CORS.
 *
 * Symptôme qui a motivé ce test : Vite ne garde pas toujours le port 5173. S'il est occupé,
 * il passe au suivant sans prévenir ; l'origine ne figure plus dans FRONTEND_URLS, le
 * navigateur bloque l'appel, et l'écran de connexion affiche « une erreur est survenue » —
 * alors que les identifiants sont bons. On accepte donc tout port local EN LOCAL seulement.
 *
 * L'autre moitié du test compte autant : en production, la tolérance doit disparaître.
 */
class CorsLocalTest extends TestCase
{
    /**
     * Le fichier de configuration est relu avec APP_ENV forcé : c'est bien la bascule
     * local/production qu'on éprouve, pas une valeur recopiée dans le test.
     */
    private function motifs(string $appEnv): array
    {
        $precedents = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $appEnv;
        putenv('APP_ENV='.$appEnv);

        try {
            return (require base_path('config/cors.php'))['allowed_origins_patterns'];
        } finally {
            [$srv, $env, $get] = $precedents;
            $srv === null ? array_key_exists('APP_ENV', $_SERVER) && ($_SERVER['APP_ENV'] = null) : $_SERVER['APP_ENV'] = $srv;
            $env === null ? array_key_exists('APP_ENV', $_ENV) && ($_ENV['APP_ENV'] = null) : $_ENV['APP_ENV'] = $env;
            $get === false ? putenv('APP_ENV') : putenv('APP_ENV='.$get);
        }
    }

    private function accepte(array $motifs, string $origine): bool
    {
        foreach ($motifs as $motif) {
            if (preg_match($motif, $origine)) {
                return true;
            }
        }

        return false;
    }

    public function test_en_local_n_importe_quel_port_de_la_machine_est_accepte(): void
    {
        $motifs = $this->motifs('local');

        foreach (['http://localhost:5173', 'http://localhost:5175', 'http://127.0.0.1:3000', 'http://localhost'] as $origine) {
            $this->assertTrue($this->accepte($motifs, $origine), "Origine locale refusée : $origine");
        }
    }

    public function test_en_local_une_origine_exterieure_reste_refusee(): void
    {
        $motifs = $this->motifs('local');

        // Le piège du motif trop permissif : un domaine qui CONTIENT « localhost ».
        foreach ([
            'http://localhost.attaquant.ci',
            'https://localhost:5173',
            'http://evil.com',
            'http://127.0.0.1.attaquant.ci',
        ] as $origine) {
            $this->assertFalse($this->accepte($motifs, $origine), "Origine étrangère acceptée : $origine");
        }
    }

    public function test_hors_local_aucune_tolerance(): void
    {
        $this->assertSame([], $this->motifs('production'));
    }
}
