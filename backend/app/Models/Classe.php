<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Classe — LECTURE SEULE sur ECONOMAT.T_CLASSE. */
class Classe extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_CLASSE';
    protected $primaryKey = 'CodeClasse';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'code', 'nom', 'niveau_code', 'annee', 'serie'];
    protected $hidden = ['num', 'CodeClasse', 'LibelleClasse', 'CodN', 'CodeF', 'ANNEE', 'BoolClassExam', 'CodeSerie', 'Site', 'CODEETABLISSEMENT', 'CODESOCIETE'];

    public function getIdAttribute() { return $this->attributes['num'] ?? ($this->attributes['CodeClasse'] ?? null); }
    public function getCodeAttribute() { return $this->attributes['CodeClasse'] ?? null; }
    public function getNomAttribute() { return $this->attributes['LibelleClasse'] ?? ($this->attributes['CodeClasse'] ?? null); }
    public function getNiveauCodeAttribute() { return $this->attributes['CodN'] ?? null; }
    public function getAnneeAttribute() { return $this->attributes['ANNEE'] ?? null; }
    public function getSerieAttribute() { return $this->attributes['CodeSerie'] ?? null; }

    public function niveau(): BelongsTo
    {
        return $this->belongsTo(Niveau::class, 'CodN', 'CodeNiveau');
    }
}
