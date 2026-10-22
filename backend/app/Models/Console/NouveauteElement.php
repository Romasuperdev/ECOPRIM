<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;

/** Élément d'une version : une nouveauté, une amélioration ou une correction. */
class NouveauteElement extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'nouveautes_elements';

    public const TYPES = ['nouveaute', 'amelioration', 'correction'];

    protected $fillable = ['version_id', 'titre', 'description', 'type', 'ordre'];

    protected $casts = ['version_id' => 'integer', 'ordre' => 'integer'];
}
