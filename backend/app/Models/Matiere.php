<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matiere extends Model
{
    protected $table = 'matieres';

    protected $fillable = ['code', 'libelle', 'coefficient_defaut'];

    public function coefficients()
    {
        return $this->hasMany(NiveauMatiereCoefficient::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }
}
