<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Établissement — LECTURE SEULE sur dbmasterbacou.T_ETABLISSEMENT. */
class Etablissement extends Model
{
    protected $connection = 'master';
    protected $table = 'T_ETABLISSEMENT';
    protected $primaryKey = 'Num';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $visible = ['id', 'code', 'nom', 'type', 'adresse', 'telephone', 'email', 'statut', 'responsable', 'dren', 'iep'];
    protected $appends = ['id', 'code', 'nom', 'type', 'adresse', 'telephone', 'email', 'statut', 'responsable', 'dren', 'iep'];

    public function getIdAttribute() { return $this->attributes['Num'] ?? null; }
    public function getCodeAttribute() { return $this->attributes['CODE'] ?? null; }
    public function getNomAttribute() { return $this->attributes['RAISONSOCIALE'] ?? null; }
    public function getTypeAttribute() { return $this->attributes['TYPE'] ?? null; }
    public function getAdresseAttribute() { return $this->attributes['ADRESSE'] ?? null; }
    public function getTelephoneAttribute() { return $this->attributes['TELEPHONE'] ?? null; }
    public function getEmailAttribute() { return $this->attributes['EMAIL'] ?? null; }
    public function getStatutAttribute() { return $this->attributes['STATUT'] ?? null; }
    public function getResponsableAttribute() { return trim(($this->attributes['PRENOMRESP'] ?? '').' '.($this->attributes['NOMRESP'] ?? '')) ?: null; }
    public function getDrenAttribute() { return $this->attributes['INTITULEDREN'] ?? null; }
    public function getIepAttribute() { return $this->attributes['INTITULEIEP'] ?? null; }
}
