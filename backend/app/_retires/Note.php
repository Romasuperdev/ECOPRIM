<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $table = 'notes';

    protected $fillable = [
        'eleve_id', 'matiere_id', 'periode_id', 'enseignant_id',
        'valeur', 'coefficient', 'type_evaluation', 'date_evaluation', 'appreciation',
    ];

    protected $casts = [
        'valeur' => 'decimal:2',
        'date_evaluation' => 'date',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function periode()
    {
        return $this->belongsTo(Periode::class);
    }

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }
}
