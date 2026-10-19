<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    /*
    | Origines pour lesquelles l'authentification se fait par SESSION (cookie) et non par
    | jeton. Sanctum compare l'origine de l'appel à cette liste ; si elle n'y figure pas,
    | il n'ouvre pas de session — et le premier contrôleur qui la lit échoue sur
    | « Session store not set on request », message qui ne dit rien de la cause.
    |
    | EN DÉVELOPPEMENT, on ajoute les adresses de réseau privé. Ouvrir l'application depuis
    | un téléphone ou un autre poste du bureau se fait par l'IP de la machine
    | (192.168.x.x), qui change d'un réseau à l'autre et qu'on ne peut pas inscrire à
    | l'avance. Hors développement, rien n'est ajouté : seules les origines déclarées
    | valent, car une page hostile servie sur le même réseau pourrait sinon agir avec la
    | session de l'utilisateur.
    */
    'stateful' => array_merge(
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
        ))),
        env('APP_ENV') === 'local'
            ? array_merge(
                ['192.168.*', '10.*'],
                // 172.16 à 172.31 seulement : la plage privée s'arrête là. Un motif
                // « 172.2* » paraîtrait plus court et attraperait aussi 172.200.x.x,
                // qui est une adresse publique.
                array_map(fn (int $n) => "172.{$n}.*", range(16, 31)),
            )
            : [],
    ),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
