<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Notes en LECTURE SEULE via la vue ECONOMAT V_NOTECLASSE. */
class NoteVue extends Model
{
    protected $connection = 'economat';
    protected $table = 'V_NOTECLASSE';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $visible = ['id', 'matricule', 'nom', 'prenom', 'note', 'matiere_code', 'matiere_libelle', 'type_note', 'classe_code'];
    protected $appends = ['id', 'matricule', 'nom', 'prenom', 'note', 'matiere_code', 'matiere_libelle', 'type_note', 'classe_code'];

    public function getIdAttribute() { return $this->attributes['Code'] ?? null; }
    public function getMatriculeAttribute() { return $this->attributes['Matricule'] ?? null; }
    public function getNomAttribute() { return $this->attributes['Nom'] ?? null; }
    public function getPrenomAttribute() { return $this->attributes['Prenom'] ?? null; }
    public function getNoteAttribute() { return $this->attributes['Note'] ?? null; }
    public function getMatiereCodeAttribute() { return $this->attributes['CodeMatiere'] ?? null; }
    public function getMatiereLibelleAttribute() { return $this->attributes['LibelleMatiere'] ?? null; }
    public function getTypeNoteAttribute() { return $this->attributes['TypeNote'] ?? null; }
    public function getClasseCodeAttribute() { return $this->attributes['CodeClasse'] ?? null; }
}
