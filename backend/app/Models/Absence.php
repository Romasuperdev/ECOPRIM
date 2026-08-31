<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Absence élève — LECTURE SEULE sur ECONOMAT.T_ABSENCEELEVE. */
class Absence extends Model
{
    protected $connection = 'economat';
    protected $table = 'T_ABSENCEELEVE';
    protected $primaryKey = 'Code';
    protected $keyType = 'int';
    public $incrementing = false;
    public $timestamps = false;

    protected $appends = ['id', 'matricule', 'classe_code', 'date', 'heure', 'motif', 'justifiee', 'annee'];
    protected $hidden = ['Code', 'Matricule', 'CodeClasse', 'Heure', 'Cour', 'Date', 'Cause', 'CodeSession', 'AnneeCour', 'CodeEleve', 'Justifier'];

    public function getIdAttribute() { return $this->attributes['Code'] ?? null; }
    public function getMatriculeAttribute() { return $this->attributes['Matricule'] ?? null; }
    public function getClasseCodeAttribute() { return $this->attributes['CodeClasse'] ?? null; }
    public function getDateAttribute() { return $this->attributes['Date'] ?? null; }
    public function getHeureAttribute() { return $this->attributes['Heure'] ?? null; }
    public function getMotifAttribute() { return $this->attributes['Cause'] ?? null; }
    public function getJustifieeAttribute(): bool { return (bool) ($this->attributes['Justifier'] ?? false); }
    public function getAnneeAttribute() { return $this->attributes['AnneeCour'] ?? null; }

    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class, 'CodeEleve', 'Code');
    }
}
