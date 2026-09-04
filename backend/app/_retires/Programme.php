<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Programme extends Model
{
    protected $table = 'programmes';

    protected $fillable = ['niveau_id', 'matiere_id', 'annee_scolaire_id', 'titre', 'contenu'];

    public function niveau()
    {
        return $this->belongsTo(Niveau::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function anneeScolaire()
    {
        return $this->belongsTo(AnneeScolaire::class);
    }
}
