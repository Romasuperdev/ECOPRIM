<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Société gérée par ECOPRIM (base propre `ecoprim`, écrivable). */
class Societe extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'societes';

    protected $fillable = ['code', 'nom', 'ville', 'adresse', 'telephone', 'email', 'representant', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    public function etablissements(): HasMany
    {
        return $this->hasMany(Etablissement::class, 'societe_code', 'code');
    }
}
