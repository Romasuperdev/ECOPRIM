<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lecture seule sur US_SOCIETE (base de contrôle partagée dbmasterbacou).
 * Utilisé uniquement pour l'import ponctuel des sociétés réelles vers la Console
 * Administrative (voir App\Console\Commands\ImporterSocietesDbmasterbacou) —
 * ECOPRIM ne modifie jamais cette table et ne s'y connecte pas en continu.
 */
class UsSociete extends Model
{
    protected $connection = 'master';

    protected $table = 'US_SOCIETE';

    protected $primaryKey = 'CODESOCIETE';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];
}
