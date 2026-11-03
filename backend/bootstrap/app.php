<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CompteActif;
use App\Http\Middleware\ConsoleMiddleware;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\InstallerTablesPropres;
use App\Http\Middleware\PortailMiddleware;
use App\Http\Middleware\RhRoleMiddleware;
use App\Support\ErreursApi;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        // Tables propres à NEXORA créées d'elles-mêmes si elles manquent (mise en ligne
        // par simple copie de fichiers) : voir App\Support\TablesPropres.
        $middleware->api(prepend: [InstallerTablesPropres::class]);
        $middleware->alias([
            'role' => RhRoleMiddleware::class,
            'console' => ConsoleMiddleware::class,
            'portail' => PortailMiddleware::class,
            'permission' => EnsurePermission::class,
            'compte.actif' => CompteActif::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Aucune erreur technique (SQL, chemin, trace) n'atteint l'écran : voir ErreursApi.
        ErreursApi::enregistrer($exceptions);
    })->create();
