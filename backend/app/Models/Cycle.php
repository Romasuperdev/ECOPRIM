<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cycle — LECTURE SEULE sur ECONOMAT.T_CYCLE. */
class Cycle extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_CYCLE';
    protected $primaryKey = 'CodeCycle';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'code', 'libelle', 'primaire'];
    protected $hidden = ['Num', 'CodeCycle', 'LibelleCycle', 'CodeEtab', 'Primaire'];

    public function getIdAttribute() { return $this->attributes['Num'] ?? ($this->attributes['CodeCycle'] ?? null); }
    public function getCodeAttribute() { return $this->attributes['CodeCycle'] ?? null; }
    public function getLibelleAttribute() { return $this->attributes['LibelleCycle'] ?? null; }
    public function getPrimaireAttribute(): bool { return (bool) ($this->attributes['Primaire'] ?? false); }
}
