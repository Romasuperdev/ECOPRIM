<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Retard extends Model
{
    protected $table = 'retards';

    protected $fillable = ['eleve_id', 'date_retard', 'heure_arrivee', 'duree_minutes', 'motif', 'justifie'];

    protected $casts = [
        'date_retard' => 'date',
        'justifie' => 'boolean',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }
}
