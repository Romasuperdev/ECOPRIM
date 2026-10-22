<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Version publiée dans « Nouveautés » (notes de version) — base propre `ecoprim`. */
class NouveauteVersion extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'nouveautes_versions';

    protected $fillable = ['numero', 'titre', 'description', 'date_sortie', 'active'];

    protected $casts = ['date_sortie' => 'date', 'active' => 'boolean'];

    public function elements(): HasMany
    {
        return $this->hasMany(NouveauteElement::class, 'version_id')->orderBy('ordre')->orderBy('id');
    }

    public function lectures(): HasMany
    {
        return $this->hasMany(NouveauteLue::class, 'version_id');
    }
}
