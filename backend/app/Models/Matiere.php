<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Matière — LECTURE SEULE sur ECONOMAT.T_MATIERE. */
class Matiere extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_MATIERE';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'code', 'libelle', 'type', 'cycle_code'];
    protected $hidden = ['Code', 'CodeMatiere', 'LibelleMatiere', 'Type', 'Composition', 'CodeCycle'];

    public function getIdAttribute() { return $this->attributes['Code'] ?? null; }
    public function getCodeAttribute() { return $this->attributes['CodeMatiere'] ?? null; }
    public function getLibelleAttribute() { return $this->attributes['LibelleMatiere'] ?? null; }
    public function getTypeAttribute() { return $this->attributes['Type'] ?? null; }
    public function getCycleCodeAttribute() { return $this->attributes['CodeCycle'] ?? null; }
}
