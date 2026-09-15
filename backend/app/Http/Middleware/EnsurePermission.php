<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'une permission précise (App\Support\Permissions::CATALOGUE), portée par un
 * rôle du catalogue console_roles — distinct de `role:` (rôles master en lecture seule) et
 * de `console:` (accès à la console elle-même). Usage : permission:saisir_notes.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $code): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);
        abort_unless(Permissions::existe($code), 500, "Permission inconnue : {$code}.");
        abort_unless($user->aLaPermission($code), 403,
            "Vous n'avez pas la permission « {$code} ».");

        return $next($request);
    }
}
