<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:5173')))
    )),

    // En développement, Vite ne garde pas toujours le port 5173 : si celui-ci est occupé,
    // il passe au suivant sans prévenir, l'origine n'est plus dans la liste ci-dessus, le
    // navigateur bloque l'appel et l'écran de connexion affiche une erreur qui n'a rien à
    // voir avec les identifiants. On accepte donc n'importe quel port local — et seulement
    // en local : en production, seules les origines déclarées sont admises.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://(localhost|127\\.0\\.0\\.1)(:\\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
