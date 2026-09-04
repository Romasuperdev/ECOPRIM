<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NiveauMatiereCoefficient extends Model
{
    protected $table = 'niveau_matiere_coefficients';

    protected $fillable = ['niveau_id', 'matiere_id', 'coefficient'];

    public function niveau()
    {
        return $this->belongsTo(Niveau::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }
}
