<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConseilClasse extends Model
{
    protected $table = 'conseils_classe';

    protected $fillable = ['classe_id', 'periode_id', 'date_conseil', 'president', 'secretaire', 'observations_generales'];

    protected $casts = [
        'date_conseil' => 'date',
    ];

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function periode()
    {
        return $this->belongsTo(Periode::class);
    }

    public function deliberations()
    {
        return $this->hasMany(Deliberation::class);
    }
}
