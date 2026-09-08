<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porte d'entrée par type de portail — `portail:staff`, `portail:enseignant` ou
 * `portail:parent` (ou une combinaison `portail:enseignant|parent`).
 *
 * `RhUser::typePortail()` décide : 'staff' pour l'application complète (comportement
 * historique, comptes sans rôle restreint), 'enseignant'/'parent' pour un compte dont
 * TOUS les rôles actifs sont exactement ce rôle. Ce middleware ne fait qu'appliquer ce
 * verdict — le cloisonnement fin (mes classes, mes enfants) est fait dans les
 * contrôleurs des portails.
 */
class PortailMiddleware
{
    public function handle(Request $request, Closure $next, string $types): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);

        abort_unless(in_array($user->typePortail(), explode('|', $types), true), 403,
            "Cette section n'est pas accessible avec ce type de compte.");

        return $next($request);
    }
}
