<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Periode extends Model
{
    protected $table = 'periodes';

    protected $fillable = ['annee_scolaire_id', 'libelle', 'ordre'];

    public function anneeScolaire()
    {
        return $this->belongsTo(AnneeScolaire::class);
    }
}
