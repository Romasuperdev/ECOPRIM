<?php

namespace App\Http\Middleware;

use App\Support\TablesPropres;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avant toute requête de l'API : s'assure que les tables propres à NEXORA existent, et les
 * crée sinon (voir TablesPropres). Une fois l'installation constatée, ce n'est plus qu'une
 * lecture de cache.
 */
class InstallerTablesPropres
{
    public function handle(Request $request, Closure $next): Response
    {
        TablesPropres::assurer();

        return $next($request);
    }
}
