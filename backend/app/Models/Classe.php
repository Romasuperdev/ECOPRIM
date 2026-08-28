<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Classe extends Model
{
    protected $table = 'classes';

    protected $fillable = ['niveau_id', 'annee_scolaire_id', 'nom', 'capacite', 'enseignant_principal_id', 'archivee'];

    protected $casts = [
        'archivee' => 'boolean',
    ];

    public function eleves()
    {
        return $this->hasMany(Eleve::class);
    }

    public function niveau()
    {
        return $this->belongsTo(Niveau::class);
    }

    public function anneeScolaire()
    {
        return $this->belongsTo(AnneeScolaire::class);
    }

    public function enseignantPrincipal()
    {
        return $this->belongsTo(Enseignant::class, 'enseignant_principal_id');
    }

    public function intervenants()
    {
        return $this->hasMany(ClasseMatiereEnseignant::class);
    }

    public function seances()
    {
        return $this->hasMany(Seance::class);
    }
}
