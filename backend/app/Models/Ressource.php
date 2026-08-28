<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ressource extends Model
{
    protected $table = 'ressources';

    protected $fillable = ['matiere_id', 'niveau_id', 'enseignant_id', 'titre', 'url', 'description'];

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function niveau()
    {
        return $this->belongsTo(Niveau::class);
    }

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }
}
