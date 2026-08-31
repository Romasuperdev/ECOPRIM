<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Niveau — LECTURE SEULE sur ECONOMAT.T_NIVEAU. */
class Niveau extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_NIVEAU';
    protected $primaryKey = 'Num';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'code', 'libelle', 'ordre', 'cycle_code', 'annee'];
    protected $hidden = ['Num', 'CodeNiveau', 'LibelleNiveau', 'CodeCycle', 'CodeFiliere', 'NiveauExamen', 'ANNEE', 'Ordre', 'CODEETABLISSEMENT', 'CODESOCIETE'];

    public function getIdAttribute() { return $this->attributes['Num'] ?? null; }
    public function getCodeAttribute() { return $this->attributes['CodeNiveau'] ?? null; }
    public function getLibelleAttribute() { return $this->attributes['LibelleNiveau'] ?? null; }
    public function getOrdreAttribute() { return $this->attributes['Ordre'] ?? null; }
    public function getCycleCodeAttribute() { return $this->attributes['CodeCycle'] ?? null; }
    public function getAnneeAttribute() { return $this->attributes['ANNEE'] ?? null; }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class, 'CodeCycle', 'CodeCycle');
    }
}
