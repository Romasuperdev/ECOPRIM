<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Établissement géré par ECOPRIM, rattaché à une seule société (societe_code). */
class Etablissement extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'etablissements';

    protected $fillable = ['code', 'intitule', 'type', 'adresse', 'ville', 'pays', 'telephone', 'email', 'site_web', 'societe_code', 'actif'];

    protected $casts = ['actif' => 'boolean'];

    public function societe(): BelongsTo
    {
        return $this->belongsTo(Societe::class, 'societe_code', 'code');
    }

    public function affectations(): HasMany
    {
        return $this->hasMany(Affectation::class, 'etablissement_code', 'code');
    }
}
