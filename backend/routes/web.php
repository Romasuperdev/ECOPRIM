<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Racine du backend
|--------------------------------------------------------------------------
| ECOPRIM est une API : l'interface est servie séparément par le frontend Vite.
| Ce backend n'a donc aucune vue « welcome » à rendre — la route par défaut de
| Laravel la réclamait encore, d'où l'erreur « View [welcome] not found ».
|
| La racine renvoie désormais une simple carte d'identité JSON, utile pour
| vérifier d'un coup d'œil que le serveur répond et sur quelle API pointer.
| L'état de santé applicatif reste exposé sur /up (bootstrap/app.php).
*/

Route::get('/', fn () => response()->json([
    'application'   => config('app.name'),
    'environnement' => config('app.env'),
    'api'           => url('/api/v1'),
    'sante'         => url('/up'),
]));
