<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Niveau — LECTURE SEULE sur ECONOMAT.T_NIVEAU. */
class Niveau extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_NIVEAU';
    protected $primaryKey = 'Num';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'code', 'libelle', 'ordre', 'annee'];
    protected $hidden = ['Num', 'CodeNiveau', 'LibelleNiveau', 'CodeCycle', 'CodeFiliere', 'NiveauExamen', 'ANNEE', 'Ordre', 'CODEETABLISSEMENT', 'CODESOCIETE'];

    public function getIdAttribute() { return $this->attributes['Num'] ?? null; }
    public function getCodeAttribute() { return $this->attributes['CodeNiveau'] ?? null; }
    public function getLibelleAttribute() { return $this->attributes['LibelleNiveau'] ?? null; }
    public function getOrdreAttribute() { return $this->attributes['Ordre'] ?? null; }
    public function getAnneeAttribute() { return $this->attributes['ANNEE'] ?? null; }

}
