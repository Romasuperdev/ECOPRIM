<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Devoir donné à une classe — table propre à NEXORA (`ecoprim.devoirs`), pas ECONOMAT :
 * consigne et date de remise n'ont pas de colonne dans le cahier de texte (qui ne
 * consigne que ce qui a été vu, pas un travail à rendre). `classe_code`, `matiere_code`
 * et `enseignant_code` pointent vers ECONOMAT en lecture seule.
 */
class Devoir extends Model
{
    protected $connection = 'ecoprim';

    protected $fillable = [
        'titre', 'consigne', 'classe_code', 'matiere_code', 'enseignant_code', 'date_remise', 'annee',
    ];

    protected $casts = [
        'date_remise' => 'date',
    ];
}
