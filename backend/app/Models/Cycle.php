<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cycle extends Model
{
    protected $table = 'cycles';

    protected $fillable = ['code', 'libelle', 'ordre'];

    public function niveaux()
    {
        return $this->hasMany(Niveau::class);
    }
}
