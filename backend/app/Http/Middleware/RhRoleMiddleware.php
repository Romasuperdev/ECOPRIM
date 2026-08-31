<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle de rôle en lecture seule, basé sur RhUser::hasRole (rôles lus dans
 * dbmasterbacou). Remplace le middleware spatie. Les rôles multiples se listent
 * avec « | » : role:Super Admin|Admin Société.
 */
class RhRoleMiddleware
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_unless(method_exists($user, 'hasRole') && $user->hasRole($roles), 403,
            "Vous n'avez pas le rôle requis pour cette action.");

        return $next($request);
    }
}
