<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;

/**
 * Lecture d'une version par un compte. `rh_user_id` = RH_USER.Id (base maître) : pas de
 * relation Eloquent possible entre les deux connexions, l'identifiant suffit.
 */
class NouveauteLue extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'nouveautes_lues';

    public $timestamps = false;

    protected $fillable = ['rh_user_id', 'version_id', 'lu_le'];

    protected $casts = ['rh_user_id' => 'integer', 'version_id' => 'integer', 'lu_le' => 'datetime'];
}
