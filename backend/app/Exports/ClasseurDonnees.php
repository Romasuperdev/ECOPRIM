<?php

namespace App\Exports;

use App\Services\Echange\CatalogueDonnees;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Le classeur exporté : une feuille par jeu demandé.
 *
 * Un seul fichier pour toute l'année plutôt qu'un fichier par jeu — c'est ce qu'on veut
 * archiver en fin d'année, et ce qu'on veut rouvrir des mois plus tard sans avoir à
 * retrouver huit pièces jointes.
 *
 * Avec `$vierge`, le même classeur ne contient que les en-têtes : c'est le modèle à
 * remplir pour l'import.
 */
class ClasseurDonnees implements WithMultipleSheets
{
    /** @param  array<int, string>  $jeux */
    public function __construct(
        private CatalogueDonnees $catalogue,
        private array $jeux,
        private bool $vierge = false,
    ) {}

    public function sheets(): array
    {
        return array_map(fn (string $code) => new FeuilleDonnees(
            CatalogueDonnees::jeu($code)['feuille'],
            CatalogueDonnees::entetes($code),
            $this->vierge ? [] : $this->catalogue->lignes($code),
        ), $this->jeux);
    }
}
