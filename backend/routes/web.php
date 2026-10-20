<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Racine du backend, et service de l'interface
|--------------------------------------------------------------------------
|
| EN PRODUCTION, une seule origine sert tout : Apache rend l'API sous /api, et
| l'interface React sous toutes les autres adresses. C'est ce qui évite CORS, les
| cookies inter-domaines et une URL d'API à réécrire selon le serveur — le proxy de
| Vite joue déjà ce rôle en développement.
|
| La SPA est construite dans public/spa (voir vite.config.js). Ses fichiers sont
| servis directement par Apache ; seule la PAGE passe par ici, et par une route
| attrape-tout : sans elle, ouvrir /admin/utilisateurs ou simplement rafraîchir la
| page donnerait un 404 d'Apache, puisqu'aucun fichier ne porte ce nom.
|
| EN DÉVELOPPEMENT, public/spa n'existe pas : la racine rend alors une carte
| d'identité JSON, utile pour vérifier d'un coup d'œil que le serveur répond et sur
| quelle API pointer. L'état de santé reste sur /up (bootstrap/app.php).
*/

$interface = public_path('spa/index.html');

Route::get('/', function () use ($interface) {
    if (File::exists($interface)) {
        return response()->file($interface);
    }

    return response()->json([
        'application' => config('app.name'),
        'environnement' => config('app.env'),
        'api' => url('/api/v1'),
        'sante' => url('/up'),
        'interface' => "Non construite. Lancez « npm run build » dans frontend/.",
    ]);
});

if (File::exists($interface)) {
    /*
     | Tout le reste appartient au routeur React.
     |
     | PAS `Route::fallback()`, et la raison mérite d'être écrite. Le repli est une route
     | GET dont l'URI accepte n'importe quoi : une requête DELETE vers une adresse
     | inexistante trouvait alors une URI correspondante mais un verbe différent, et Laravel
     | répondait 405 « méthode non autorisée » là où il répondait 404. Toute l'API perdait
     | la distinction entre « cette adresse n'existe pas » et « pas avec ce verbe-là ».
     |
     | Ici le MOTIF D'URI exclut lui-même les préfixes servis par le serveur : une adresse
     | d'API ne correspond jamais à cette route, quel que soit le verbe, et retrouve son 404.
     |
     | `.*` dans la contrainte — et non le `[^/]+` par défaut — pour que /admin/utilisateurs
     | soit bien pris : sans cela, rafraîchir une page profonde donnerait un 404 d'Apache.
     */
    Route::get('/{chemin}', fn () => response()->file($interface))
        ->where('chemin', '(?!(api|sanctum|spa|storage|up)(/|$)).*');
}
