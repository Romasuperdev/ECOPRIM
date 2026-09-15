<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Événement du calendrier scolaire (congé/vacances, réunion parents-professeurs, sortie
 * ou activité pédagogique) — table propre à NEXORA (`ecoprim.evenements`), pas ECONOMAT.
 * `classe_code` pointe vers ECONOMAT en lecture seule ; nul pour un événement qui
 * concerne tout l'établissement (ex. des vacances).
 */
class Evenement extends Model
{
    protected $connection = 'ecoprim';

    public const TYPES = ['vacances', 'reunion', 'sortie', 'activite'];

    protected $fillable = [
        'titre', 'type', 'description', 'date_debut', 'date_fin', 'lieu', 'classe_code', 'annee',
    ];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
    ];
}
