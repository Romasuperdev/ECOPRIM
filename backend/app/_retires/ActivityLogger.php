<?php

namespace App\Support;

use App\Models\JournalActivite;
use Illuminate\Http\Request;

/**
 * Règle de gestion : toute action sensible de la Console Administrative
 * (société, établissement, rôle, affectation, année scolaire...) est
 * journalisée. Le journal est immuable : ce helper ne fait qu'insérer.
 */
class ActivityLogger
{
    public static function log(
        Request $request,
        string $action,
        string $module,
        ?string $objetType = null,
        ?int $objetId = null,
        ?array $donneesAvant = null,
        ?array $donneesApres = null,
    ): void {
        JournalActivite::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'module' => $module,
            'objet_type' => $objetType,
            'objet_id' => $objetId,
            'donnees_avant' => $donneesAvant,
            'donnees_apres' => $donneesApres,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
