<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Barème et coefficient d'une matière, pour un niveau ou une classe — table propre à
 * NEXORA (`ecoprim.coefficients_matiere`), pas ECONOMAT.
 *
 * Pas de relation Eloquent vers Niveau, Classe ou Matiere : celles-ci vivent sur la
 * connexion `economat`, et Eloquent ne sait pas joindre deux connexions. On garde les
 * codes, comme le fait déjà `Evaluation`.
 */
class CoefficientMatiere extends Model
{
    protected $connection = 'ecoprim';

    protected $table = 'coefficients_matiere';

    protected $fillable = [
        'etablissement_code', 'annee', 'niveau_code', 'classe_code',
        'matiere_code', 'coefficient', 'note_max', 'actif',
    ];

    protected $casts = [
        'coefficient' => 'float',
        'note_max' => 'float',
        'actif' => 'boolean',
    ];

    /**
     * Le barème effectif : `note_max` s'il est renseigné, le coefficient sinon.
     *
     * C'est la convention du primaire ivoirien, où la grille donne un total de points par
     * matière — « Éveil au milieu sur 50 » — et où la moyenne est la somme des points
     * rapportée au total. Coefficient et barème y sont le même nombre ; les distinguer
     * reste possible pour une école qui note tout sur 20 et pondère ensuite.
     */
    public function bareme(): ?float
    {
        $bareme = $this->note_max ?? $this->coefficient;

        // Un coefficient à 0 veut dire « comptée pour rien », pas « notée sur 0 ». Rendre 0
        // ici ferait refuser toute note, puisque la saisie interdit de dépasser le barème.
        // Null laisse l'appelant retomber sur sa valeur par défaut, et le dire.
        return $bareme > 0 ? (float) $bareme : null;
    }

    /**
     * À quel point cette ligne est précise, de 0 (grille par défaut, tous établissements,
     * toutes années, par niveau) à 7 (une classe, un établissement, une année).
     *
     * Sert à départager deux lignes qui désignent la même matière : la plus précise gagne.
     * L'ordre des poids traduit la règle énoncée — la classe l'emporte sur le niveau,
     * l'établissement sur le défaut, puis l'année sur « toutes années ».
     */
    public function precision(): int
    {
        return ($this->classe_code !== null ? 4 : 0)
            + ($this->etablissement_code !== null ? 2 : 0)
            + ($this->annee !== null ? 1 : 0);
    }

    /** Une ligne sans aucune restriction : la grille commune, celle qu'on surcharge. */
    public function estDefaut(): bool
    {
        return $this->precision() === 0;
    }
}
