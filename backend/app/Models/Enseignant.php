<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Enseignant — LECTURE SEULE sur ECONOMAT.T_PROFESSEUR (financier exclu). */
class Enseignant extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_PROFESSEUR';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    // Liste blanche : on n'expose que le pédagogique/état civil, jamais le salaire ni le mot de passe.
    protected $visible = ['id', 'matricule', 'nom', 'prenom', 'nom_complet', 'sexe', 'email', 'telephone', 'statut', 'grade', 'matiere', 'date_embauche'];
    protected $appends = ['id', 'matricule', 'nom', 'prenom', 'nom_complet', 'sexe', 'email', 'telephone', 'statut', 'grade', 'matiere', 'date_embauche'];

    public function getIdAttribute() { return $this->attributes['Code'] ?? null; }
    public function getMatriculeAttribute() { return $this->attributes['MatriculeProfesseur'] ?? null; }
    public function getNomAttribute() { return $this->attributes['NomProfesseur'] ?? null; }
    public function getPrenomAttribute() { return $this->attributes['PrenomProfesseur'] ?? null; }
    public function getNomCompletAttribute() { return $this->attributes['NomComplet'] ?? null; }
    public function getSexeAttribute() { return $this->attributes['Sexe'] ?? null; }
    public function getEmailAttribute() { return $this->attributes['EmailProfesseur'] ?? null; }
    public function getTelephoneAttribute() { return $this->attributes['ContactProfesseur'] ?? ($this->attributes['Cellulaire'] ?? null); }
    public function getStatutAttribute() { return $this->attributes['TypeProfesseur'] ?? null; }
    public function getGradeAttribute() { return $this->attributes['GradeProfesseur'] ?? null; }
    public function getMatiereAttribute() { return $this->attributes['Matiere'] ?? null; }
    public function getDateEmbaucheAttribute() { return $this->attributes['DateEmbauche'] ?? null; }
}
