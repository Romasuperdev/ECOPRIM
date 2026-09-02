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
    protected $visible = ['id', 'matricule', 'nom', 'prenom', 'nom_complet', 'sexe', 'email', 'telephone', 'statut', 'grade', 'matiere', 'date_embauche', 'annee_code', 'date_naissance', 'lieu_naissance', 'situation_matrimoniale', 'adresse', 'ville', 'cellulaire', 'corps', 'echelon', 'diplome', 'formation', 'volume_horaire', 'fonction', 'emploi', 'dren', 'dden', 'service', 'date_premiere_prise_service', 'ecole_prise_service', 'annees_service', 'date_arrivee_poste', 'date_depart', 'motif_depart', 'etab_accueil'];
    protected $appends = ['id', 'matricule', 'nom', 'prenom', 'nom_complet', 'sexe', 'email', 'telephone', 'statut', 'grade', 'matiere', 'date_embauche', 'annee_code', 'date_naissance', 'lieu_naissance', 'situation_matrimoniale', 'adresse', 'ville', 'cellulaire', 'corps', 'echelon', 'diplome', 'formation', 'volume_horaire', 'fonction', 'emploi', 'dren', 'dden', 'service', 'date_premiere_prise_service', 'ecole_prise_service', 'annees_service', 'date_arrivee_poste', 'date_depart', 'motif_depart', 'etab_accueil'];

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
    public function getAnneeCodeAttribute() { return $this->attributes['CodeAnnee'] ?? null; }
    public function getDateNaissanceAttribute() { return $this->attributes['DateNaiss'] ?? null; }
    public function getLieuNaissanceAttribute() { return $this->attributes['LieuNaiss'] ?? null; }
    public function getSituationMatrimonialeAttribute() { return $this->attributes['SituationMatrimoniale'] ?? null; }
    public function getAdresseAttribute() { return $this->attributes['AdresseProfesseur'] ?? null; }
    public function getVilleAttribute() { return $this->attributes['Ville'] ?? null; }
    public function getCellulaireAttribute() { return $this->attributes['Cellulaire'] ?? null; }
    public function getCorpsAttribute() { return $this->attributes['Corps'] ?? null; }
    public function getEchelonAttribute() { return $this->attributes['Echelon'] ?? null; }
    public function getDiplomeAttribute() { return $this->attributes['DiplTitrUniv'] ?? null; }
    public function getFormationAttribute() { return $this->attributes['FormaProf'] ?? null; }
    public function getVolumeHoraireAttribute() { return $this->attributes['VHORAIRE'] ?? null; }
    public function getFonctionAttribute() { return $this->attributes['fonction'] ?? null; }
    public function getEmploiAttribute() { return $this->attributes['Emploi'] ?? null; }
    public function getDrenAttribute() { return $this->attributes['DREN'] ?? null; }
    public function getDdenAttribute() { return $this->attributes['DDEN'] ?? null; }
    public function getServiceAttribute() { return $this->attributes['Service'] ?? null; }
    public function getDatePremierePriseServiceAttribute() { return $this->attributes['DatePremPriseService'] ?? null; }
    public function getEcolePriseServiceAttribute() { return $this->attributes['EcolePriseService'] ?? null; }
    public function getAnneesServiceAttribute() { return $this->attributes['NbrAnneeService'] ?? null; }
    public function getDateArriveePosteAttribute() { return $this->attributes['DateArriveePoste'] ?? null; }
    public function getDateDepartAttribute() { return $this->attributes['DateDepart'] ?? null; }
    public function getMotifDepartAttribute() { return $this->attributes['Motif'] ?? null; }
    public function getEtabAccueilAttribute() { return $this->attributes['EtabAccueil'] ?? null; }
}
