<?php

namespace App\Support;

use App\Models\AnneeScolaire;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Règle de gestion : une année scolaire clôturée devient en lecture seule
 * (notes, absences, retards, sanctions, effectifs de classe). Seul un
 * Super Admin peut passer outre, explicitement (via ?force=1).
 */
class AnneeScolaireGuard
{
    public static function assertModifiable(?int $anneeScolaireId, Request $request): void
    {
        if (! $anneeScolaireId) {
            return;
        }

        $annee = AnneeScolaire::find($anneeScolaireId);

        if (! $annee || ! $annee->cloturee) {
            return;
        }

        $peutForcer = $request->user()?->hasRole('Super Admin') && $request->boolean('force');

        if ($peutForcer) {
            return;
        }

        throw new HttpException(423, "L'année scolaire « {$annee->libelle} » est clôturée : modification impossible.");
    }
}
