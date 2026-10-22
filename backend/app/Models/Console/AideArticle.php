<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article de l'Aide. `contenu` est du texte brut : il est rendu tel quel côté lecteur
 * (sauts de ligne conservés), jamais interprété comme du HTML.
 */
class AideArticle extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'aide_articles';

    protected $fillable = ['categorie_id', 'titre', 'contenu', 'module', 'ordre', 'active'];

    protected $casts = ['categorie_id' => 'integer', 'ordre' => 'integer', 'active' => 'boolean'];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(AideCategorie::class, 'categorie_id');
    }

    /**
     * Ce qu'un lecteur a le droit de voir : l'article actif, DANS une catégorie active.
     * Un article actif rangé sous une catégorie désactivée reste caché — sinon il
     * resurgirait par la recherche ou par son adresse alors qu'il a quitté le sommaire.
     */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('active', true)
            ->whereHas('categorie', fn ($c) => $c->where('active', true));
    }
}
