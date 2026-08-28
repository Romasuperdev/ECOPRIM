<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deliberation extends Model
{
    protected $table = 'deliberations';

    protected $fillable = ['conseil_classe_id', 'eleve_id', 'moyenne_generale', 'decision', 'appreciation', 'mention'];

    public function conseilClasse()
    {
        return $this->belongsTo(ConseilClasse::class);
    }

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }
}
