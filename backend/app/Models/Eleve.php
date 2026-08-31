<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Élève — LECTURE SEULE sur ECONOMAT.T_ETUDIANT (financier & technique exclus). */
class Eleve extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_ETUDIANT';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    // Liste blanche stricte : identité, scolarité, parents. Jamais le financier (Scolarite,
    // TotalPaye, Rb_*, Remise...) ni le technique (MDP, MDP2, NUM...).
    protected $visible = [
        'id', 'matricule', 'nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite',
        'classe_code', 'niveau_code', 'cycle_code', 'annee', 'statut', 'redoublant',
        'pere_nom', 'pere_prenom', 'pere_profession', 'pere_telephone', 'pere_email',
        'mere_nom', 'mere_prenom', 'mere_profession', 'mere_telephone', 'mere_email',
        'classe',
    ];
    protected $appends = [
        'id', 'matricule', 'nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite',
        'classe_code', 'niveau_code', 'cycle_code', 'annee', 'statut', 'redoublant',
        'pere_nom', 'pere_prenom', 'pere_profession', 'pere_telephone', 'pere_email',
        'mere_nom', 'mere_prenom', 'mere_profession', 'mere_telephone', 'mere_email',
    ];

    public function getIdAttribute() { return $this->attributes['Code'] ?? null; }
    public function getMatriculeAttribute() { return $this->attributes['Matricule'] ?? null; }
    public function getNomAttribute() { return $this->attributes['Nom'] ?? null; }
    public function getPrenomAttribute() { return $this->attributes['Prenom'] ?? null; }
    public function getSexeAttribute() { return $this->attributes['Sexe'] ?? null; }
    public function getDateNaissanceAttribute() { return $this->attributes['DateNaiss'] ?? null; }
    public function getLieuNaissanceAttribute() { return $this->attributes['LieuNaiss'] ?? null; }
    public function getNationaliteAttribute() { return $this->attributes['Nationalite'] ?? null; }
    public function getClasseCodeAttribute() { return $this->attributes['CodeClasse'] ?? null; }
    public function getNiveauCodeAttribute() { return $this->attributes['CodeNiveau'] ?? null; }
    public function getCycleCodeAttribute() { return $this->attributes['CodeCycle'] ?? null; }
    public function getAnneeAttribute() { return $this->attributes['AnneeAcad'] ?? null; }
    public function getStatutAttribute() { return $this->attributes['Etat'] ?? ($this->attributes['Statut'] ?? null); }
    public function getRedoublantAttribute() { return $this->attributes['Redoublant'] ?? null; }

    public function getPereNomAttribute() { return $this->attributes['NomPereTuteur'] ?? null; }
    public function getPerePrenomAttribute() { return $this->attributes['PrenomPereTuteur'] ?? null; }
    public function getPereProfessionAttribute() { return $this->attributes['ProfessionPereTuteur'] ?? null; }
    public function getPereTelephoneAttribute() { return $this->attributes['TelephonePereTuteur'] ?? null; }
    public function getPereEmailAttribute() { return $this->attributes['EmailPereTuteur'] ?? null; }
    public function getMereNomAttribute() { return $this->attributes['NomMere'] ?? null; }
    public function getMerePrenomAttribute() { return $this->attributes['PrenomMere'] ?? null; }
    public function getMereProfessionAttribute() { return $this->attributes['ProfessionMere'] ?? null; }
    public function getMereTelephoneAttribute() { return $this->attributes['TelephoneMere'] ?? null; }
    public function getMereEmailAttribute() { return $this->attributes['EmailMere'] ?? null; }

    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class, 'CodeClasse', 'CodeClasse');
    }
}
