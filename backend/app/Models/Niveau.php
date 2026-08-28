<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Niveau extends Model
{
    use SoftDeletes;

    protected $table = 'niveaux';

    protected $fillable = ['code', 'libelle', 'ordre', 'cycle_id'];

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function cycle()
    {
        return $this->belongsTo(Cycle::class);
    }

    public function coefficients()
    {
        return $this->hasMany(NiveauMatiereCoefficient::class);
    }
}
