<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Absence extends Model
{
    protected $table = 'absences';

    protected $fillable = ['eleve_id', 'date_absence', 'periode_jour', 'matiere_id', 'motif', 'justifiee'];

    protected $casts = [
        'date_absence' => 'date',
        'justifiee' => 'boolean',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }
}
