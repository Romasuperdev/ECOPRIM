<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inscription extends Model
{
    protected $table = 'inscriptions';

    protected $fillable = [
        'eleve_id', 'annee_scolaire_id', 'classe_id', 'type', 'date_mouvement',
        'etablissement_origine', 'etablissement_destination', 'observation',
    ];

    protected $casts = [
        'date_mouvement' => 'date',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function anneeScolaire()
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }
}
