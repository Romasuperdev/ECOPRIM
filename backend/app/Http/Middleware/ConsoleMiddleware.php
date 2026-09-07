<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Porte d'entrée de la Console Administrative, en trois niveaux :
 *
 *   console:generale     — la console générale : sociétés, catalogue de rôles global,
 *                          journal. Super Admin seul.
 *   console:societe       — la console d'une société : ses établissements, ses
 *                          utilisateurs, ses rôles propres. Ouverte au Super Admin (sur
 *                          n'importe quelle société) et à l'Admin Société (sur la sienne
 *                          seulement).
 *   console:etablissement — le minimum commun : lire le catalogue de rôles, lister les
 *                          utilisateurs, poser/retirer une affectation. Ouvert en plus à
 *                          l'Admin Établissement, borné à son ou ses établissements.
 *
 * Le cloisonnement fin (société, établissement) est appliqué dans les contrôleurs via
 * PerimetreConsole : ce middleware ne décide que du droit d'entrer.
 */
class ConsoleMiddleware
{
    public function handle(Request $request, Closure $next, string $niveau = 'generale'): Response
    {
        $user = $request->user();

        abort_if(! $user, 401);

        if ($niveau === 'etablissement') {
            abort_unless($user->peutAccederConsole(), 403,
                "Vous n'avez pas accès à la console d'administration.");

            return $next($request);
        }

        if ($niveau === 'societe') {
            abort_unless($user->peutAccederNiveauSociete(), 403,
                "Vous n'avez pas accès à la console d'une société.");

            return $next($request);
        }

        abort_unless($user->isSuperAdmin(), 403,
            'La console générale est réservée au Super Administrateur.');

        return $next($request);
    }
}
