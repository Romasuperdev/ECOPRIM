<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seance extends Model
{
    protected $table = 'seances';

    protected $fillable = ['classe_id', 'matiere_id', 'programme_id', 'enseignant_id', 'date_seance', 'contenu', 'devoirs'];

    protected $casts = [
        'date_seance' => 'date',
    ];

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class);
    }

    public function enseignant()
    {
        return $this->belongsTo(Enseignant::class);
    }
}
