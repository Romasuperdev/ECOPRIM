<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catégorie de l'Aide (documentation interne) — base propre `ecoprim`. */
class AideCategorie extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'aide_categories';

    protected $fillable = ['titre', 'icone', 'ordre', 'active'];

    protected $casts = ['ordre' => 'integer', 'active' => 'boolean'];

    /** Tous les articles, actifs ou non (la gestion doit pouvoir réactiver). */
    public function articles(): HasMany
    {
        return $this->hasMany(AideArticle::class, 'categorie_id')->orderBy('ordre')->orderBy('id');
    }
}
