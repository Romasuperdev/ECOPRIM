<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Une feuille du classeur : un titre, une ligne d'en-têtes, des lignes.
 *
 * Générique à dessein — le contenu vient de CatalogueDonnees, qui est le seul endroit où
 * l'on décide ce qu'un jeu contient. Une classe par jeu aurait multiplié par huit le même
 * squelette sans rien décider de plus.
 *
 * Avec un tableau de lignes vide, la même classe produit le MODÈLE VIERGE de l'import :
 * les en-têtes attendus, et rien d'autre. C'est voulu — un modèle qui n'est pas dérivé de
 * l'export finit toujours par annoncer une colonne que l'export n'écrit plus.
 */
class FeuilleDonnees implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<int, array<string, mixed>>  $lignes  lignes en-tête -> valeur
     * @param  array<int, string>  $entetes
     */
    public function __construct(
        private string $titre,
        private array $entetes,
        private array $lignes,
    ) {}

    public function title(): string
    {
        // Excel refuse un nom d'onglet au-delà de 31 caractères, et le fichier entier
        // devient illisible : on tronque plutôt que de produire un classeur cassé.
        return mb_substr($this->titre, 0, 31);
    }

    public function headings(): array
    {
        return $this->entetes;
    }

    public function array(): array
    {
        // Réordonne chaque ligne selon les en-têtes : une clé absente devient une cellule
        // vide, jamais un décalage de colonne.
        return array_map(
            fn (array $ligne) => array_map(fn ($e) => $ligne[$e] ?? null, $this->entetes),
            $this->lignes,
        );
    }

    /** L'en-tête en gras et figé : sur 800 lignes, on ne se souvient plus des colonnes. */
    public function styles(Worksheet $feuille): array
    {
        $feuille->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}
