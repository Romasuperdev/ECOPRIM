<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Scope global de la Console Administrative : un Super Admin voit tout, un Admin Société
 * ne voit que ce qui appartient à ses sociétés, un Admin Établissement que ses
 * établissements. Fail closed : aucune affectation résolue = aucune ligne visible
 * (jamais de repli "si vide, tout montrer"). Le modèle doit implémenter
 * `scopeForPerimetre($query, array $societeIds, array $etablissementIds)`.
 *
 * Échappatoire explicite et traçable pour les vues transverses légitimes : withoutPerimetre().
 */
trait BelongsToPerimetre
{
    protected static function bootBelongsToPerimetre(): void
    {
        static::addGlobalScope('perimetre', function (Builder $query) {
            $user = auth()->user();

            if (! $user || $user->isSuperAdmin()) {
                return;
            }

            $query->forPerimetre($user->allowedSocieteIds(), $user->allowedEtablissementIds());
        });
    }

    public static function withoutPerimetre(): Builder
    {
        return static::withoutGlobalScope('perimetre');
    }
}
