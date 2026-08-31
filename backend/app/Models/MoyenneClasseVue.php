<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Moyennes/classement en LECTURE SEULE via la vue ECONOMAT V_MOYENNE_ELEVE_CLASSE. */
class MoyenneClasseVue extends Model
{
    protected $connection = 'economat';
    protected $table = 'V_MOYENNE_ELEVE_CLASSE';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;
}
