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
 *
 * Plusieurs codes séparés par `|` = l'un OU l'autre suffit (permission:a|b) : la photo
 * d'un élève se téléverse à l'inscription comme à la modification de son dossier.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $code): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);

        $codes = explode('|', $code);
        foreach ($codes as $c) {
            abort_unless(Permissions::existe($c), 500, "Permission inconnue : {$c}.");
        }

        $accorde = false;
        foreach ($codes as $c) {
            if ($user->aLaPermission($c)) {
                $accorde = true;
                break;
            }
        }

        abort_unless($accorde, 403,
            "Vous n'avez pas la permission « ".implode(' » ou « ', $codes).' ».');

        return $next($request);
    }
}
