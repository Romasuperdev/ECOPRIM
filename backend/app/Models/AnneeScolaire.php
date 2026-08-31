<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Année académique — LECTURE SEULE sur ECONOMAT.dbo.T_ANNEEACADEMIQUE.
 * Clé primaire réelle : CODE (int). On expose des attributs mappés (id, libelle,
 * date_debut, date_fin, active, cloturee, cloture_partielle, code_annee, societe_code)
 * pour garder l'API stable côté front. Toutes les valeurs sont lues en brut (aucune écriture).
 */
class AnneeScolaire extends Model
{
    protected $connection = 'economat';

    protected $table = 'T_ANNEEACADEMIQUE';

    protected $primaryKey = 'CODE';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = false;

    protected $appends = [
        'id', 'code_annee', 'libelle', 'date_debut', 'date_fin',
        'active', 'cloturee', 'cloture_partielle', 'societe_code',
    ];

    protected $hidden = [
        'CODE', 'CodeAnnee', 'LibelleAnnee', 'Activer', 'ClotureDefinitive',
        'CloturePartielle', 'DEBUT', 'FIN', 'CODESOCIETE',
    ];

    public function getIdAttribute()
    {
        return $this->attributes['CODE'] ?? null;
    }

    public function getCodeAnneeAttribute()
    {
        return $this->attributes['CodeAnnee'] ?? null;
    }

    public function getLibelleAttribute()
    {
        return $this->attributes['LibelleAnnee'] ?? null;
    }

    public function getDateDebutAttribute()
    {
        return $this->attributes['DEBUT'] ?? null;
    }

    public function getDateFinAttribute()
    {
        return $this->attributes['FIN'] ?? null;
    }

    public function getActiveAttribute(): bool
    {
        return (bool) ($this->attributes['Activer'] ?? false);
    }

    public function getClotureeAttribute(): bool
    {
        return (bool) ($this->attributes['ClotureDefinitive'] ?? false);
    }

    public function getCloturePartielleAttribute(): bool
    {
        return (bool) ($this->attributes['CloturePartielle'] ?? false);
    }

    public function getSocieteCodeAttribute()
    {
        return $this->attributes['CODESOCIETE'] ?? null;
    }
}
