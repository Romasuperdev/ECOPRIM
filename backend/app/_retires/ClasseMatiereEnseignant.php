<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Affectation d'un enseignant "intervenant" à une matière pour une classe donnée
 * (distinct de l'enseignant principal de la classe).
 */
class ClasseMatiereEnseignant extends Model
{
    protected $table = 'classe_matiere_enseignant';

    protected $fillable = ['classe_id', 'matiere_id', 'enseignant_id', 'annee_scolaire_id', 'coefficient'];

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }

    public function anneeScolaire()
    {
        return $this->belongsTo(AnneeScolaire::class);
    }
}
