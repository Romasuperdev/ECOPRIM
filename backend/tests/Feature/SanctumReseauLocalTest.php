<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * Origines pour lesquelles l'authentification se fait par SESSION.
 *
 * Symptôme qui a motivé ce test : ouvrir l'application depuis un autre appareil du bureau,
 * par l'IP de la machine (http://192.168.1.130:5174), échouait sur « Session store not set
 * on request ». Sanctum ne reconnaissait pas cette origine, n'ouvrait donc pas de session,
 * et le premier contrôleur qui la lisait tombait — sur un message qui ne dit rien de la
 * cause. Les adresses de réseau privé sont désormais admises EN DÉVELOPPEMENT.
 *
 * L'autre moitié du test compte autant : hors développement, la tolérance doit disparaître.
 * Sans quoi une page servie sur le même réseau agirait avec la session de l'utilisateur.
 */
class SanctumReseauLocalTest extends TestCase
{
    /**
     * Le fichier de configuration est relu avec APP_ENV forcé : c'est bien la bascule
     * local/production qu'on éprouve, pas une valeur recopiée dans le test.
     */
    private function domaines(string $appEnv): array
    {
        $precedents = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $appEnv;
        putenv('APP_ENV='.$appEnv);

        try {
            return (require base_path('config/sanctum.php'))['stateful'];
        } finally {
            [$srv, $env, $get] = $precedents;
            $srv === null ? array_key_exists('APP_ENV', $_SERVER) && ($_SERVER['APP_ENV'] = null) : $_SERVER['APP_ENV'] = $srv;
            $env === null ? array_key_exists('APP_ENV', $_ENV) && ($_ENV['APP_ENV'] = null) : $_ENV['APP_ENV'] = $env;
            $get === false ? putenv('APP_ENV') : putenv('APP_ENV='.$get);
        }
    }

    /** Le verdict réel de Sanctum, et non une relecture des motifs à notre façon. */
    private function parSession(array $domaines, string $referer): bool
    {
        config(['sanctum.stateful' => $domaines]);

        $requete = Request::create('/api/v1/auth/login', 'POST');
        $requete->headers->set('referer', $referer);

        return EnsureFrontendRequestsAreStateful::fromFrontend($requete);
    }

    public function test_en_local_une_adresse_de_reseau_prive_ouvre_une_session(): void
    {
        $domaines = $this->domaines('local');

        foreach ([
            'http://192.168.1.130:5174/login',
            'http://192.168.0.42:5174/',
            'http://10.0.0.5:5174/login',
            'http://172.16.3.9:5174/',
            'http://172.31.255.1:5174/',
        ] as $origine) {
            $this->assertTrue($this->parSession($domaines, $origine), "Origine privée refusée : $origine");
        }
    }

    public function test_localhost_fonctionne_toujours(): void
    {
        $domaines = $this->domaines('local');

        // Les origines du front, telles que SANCTUM_STATEFUL_DOMAINS les déclare.
        $this->assertTrue($this->parSession($domaines, 'http://localhost:5174/login'));
        $this->assertTrue($this->parSession($domaines, 'http://127.0.0.1:5174/login'));
    }

    /**
     * La plage privée s'arrête à 172.31. Un motif « 172.2* » paraîtrait plus court et
     * attraperait aussi 172.200.x.x, qui est une adresse publique.
     */
    public function test_une_adresse_publique_voisine_de_la_plage_privee_est_refusee(): void
    {
        $domaines = $this->domaines('local');

        $this->assertFalse($this->parSession($domaines, 'http://172.200.1.1:5174/'));
        $this->assertFalse($this->parSession($domaines, 'http://172.32.0.1:5174/'));
        $this->assertFalse($this->parSession($domaines, 'https://site-hostile.example.com/x'));
    }

    public function test_hors_local_aucune_tolerance_de_reseau_prive(): void
    {
        $domaines = $this->domaines('production');

        foreach ([
            'http://192.168.1.130:5174/login',
            'http://10.0.0.5:5174/',
            'http://172.16.3.9:5174/',
        ] as $origine) {
            $this->assertFalse($this->parSession($domaines, $origine), "Origine privée admise en production : $origine");
        }
    }
}
