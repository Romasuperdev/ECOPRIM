<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rôle d'un utilisateur (RH_USER.Id) dans un établissement.
 * Plusieurs lignes = plusieurs rôles et/ou plusieurs établissements pour un même utilisateur.
 */
class Affectation extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'console_affectations';

    protected $fillable = ['rh_user_id', 'societe_code', 'etablissement_code', 'role_id', 'actif', 'date_debut', 'date_fin'];

    protected $casts = ['actif' => 'boolean', 'date_debut' => 'date', 'date_fin' => 'date'];

    public function etablissement(): BelongsTo
    {
        return $this->belongsTo(Etablissement::class, 'etablissement_code', 'code');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
