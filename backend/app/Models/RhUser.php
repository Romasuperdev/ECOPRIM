<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lecture seule sur RH_USER (base de contrôle partagée dbmasterbacou).
 * Utilisé uniquement pour vérifier les identifiants à la connexion —
 * ECOPRIM ne modifie jamais cette table.
 */
class RhUser extends Model
{
    protected $connection = 'master';

    protected $table = 'RH_USER';

    protected $primaryKey = 'Id';

    public $timestamps = false;

    protected $guarded = ['*'];
}
