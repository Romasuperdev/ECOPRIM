<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Évaluation planifiée (devoir, composition, interrogation...) — table propre à NEXORA
 * (`ecoprim.evaluations`), pas ECONOMAT : titre, coefficient, note maximale, créneau
 * horaire et enseignant n'ont pas de colonne dans T_NOTEENTETE. `classe_code`,
 * `matiere_code` et `enseignant_code` pointent vers ECONOMAT en lecture seule.
 *
 * Distincte de RapportController::evaluations(), qui restitue les évaluations DÉJÀ
 * notées (agrégat de V_NOTECLASSE) : celle-ci sert à en PLANIFIER une, avant toute note.
 */
class Evaluation extends Model
{
    protected $connection = 'ecoprim';

    /**
     * Les quatre épreuves officielles de l'établissement. Elles remplacent l'ancienne
     * liste (Devoir, Devoir surveillé, Interrogations), qui n'était de toute façon pas
     * appliquée : `type` était validé comme une chaîne libre, n'importe quelle valeur
     * passait. Elle l'est désormais.
     *
     * Les évaluations déjà enregistrées sous un ancien type gardent leur valeur en base —
     * rien n'est réécrit — mais devront recevoir un type valide à la prochaine
     * modification. L'écran affiche l'ancien libellé pour que la ligne reste lisible.
     */
    public const TYPES = ['Composition', 'Composition de passage', 'Examen blanc', 'Examen final'];

    protected $fillable = [
        'titre', 'classe_code', 'matiere_code', 'enseignant_code', 'type',
        'date', 'heure_debut', 'heure_fin', 'coefficient', 'note_maximale', 'annee',
    ];

    protected $casts = [
        'date' => 'date',
        'coefficient' => 'float',
        'note_maximale' => 'float',
    ];
}
