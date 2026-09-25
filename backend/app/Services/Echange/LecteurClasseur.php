<?php

namespace App\Services\Echange;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Lit un fichier déposé et en tire des lignes « en-tête -> valeur ».
 *
 * Lecture faite avec PhpSpreadsheet plutôt qu'avec la façade Excel : celle-ci sert à
 * ÉCRIRE (elle fournit la réponse de téléchargement et les concerns des feuilles), mais
 * en lecture elle attend une classe d'import par fichier, là où il n'y a ici qu'un tableau
 * de lignes à récupérer. IOFactory reconnaît xlsx, xls et csv sur la seule foi du fichier.
 *
 * EN-TÊTES TOLÉRANTS. « Prénom », « PRENOM » et « prenom  » désignent la même colonne : un
 * fichier repassé par un tableur, un export tiers ou une saisie manuelle ne doit pas être
 * refusé pour un accent. La tolérance s'arrête là — une colonne que le catalogue ne connaît
 * pas est signalée, jamais devinée.
 */
class LecteurClasseur
{
    /** Au-delà, ce n'est plus une correction de masse mais une reprise de base. */
    public const LIGNES_MAX = 5000;

    /**
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function lire(string $chemin): array
    {
        try {
            $lecteur = IOFactory::createReaderForFile($chemin);
            $feuille = $lecteur->load($chemin)->getActiveSheet();
            // On lit les valeurs FORMATÉES (3e argument), pas les valeurs brutes. Sans quoi
            // une date d'un classeur Excel arrive en numéro de série — 46 275 pour le
            // 25/09/2026 — et il n'y a plus moyen de la distinguer d'un nombre. Lire le
            // format demande de charger la mise en forme, d'où l'absence de
            // setReadDataOnly() : c'est le prix d'une date juste.
            $brut = $feuille->toArray(null, true, true, false);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Ce fichier n'a pas pu être ouvert. Attendus : .xlsx, .xls ou .csv."
            );
        }

        $brut = array_values(array_filter($brut, fn ($ligne) => $this->nonVide($ligne)));

        if ($brut === []) {
            throw new RuntimeException('Le fichier est vide.');
        }

        $entetes = array_map(fn ($c) => trim((string) $c), array_shift($brut));

        if (count($brut) > self::LIGNES_MAX) {
            throw new RuntimeException(
                'Le fichier compte '.count($brut).' lignes, au-delà des '.self::LIGNES_MAX
                .' admises en une fois. Découpez-le : un import qu’on ne peut plus relire n’est plus vérifiable.'
            );
        }

        $lignes = [];
        foreach ($brut as $index => $ligne) {
            $valeurs = [];
            foreach ($entetes as $colonne => $entete) {
                if ($entete === '') {
                    continue;
                }
                $valeurs[$entete] = $this->propre($ligne[$colonne] ?? null);
            }
            // Le numéro affiché est celui du tableur, en-tête comprise : c'est la ligne que
            // l'utilisateur ira corriger, pas un indice interne.
            $valeurs['_ligne'] = $index + 2;
            $lignes[] = $valeurs;
        }

        return ['entetes' => array_values(array_filter($entetes, fn ($e) => $e !== '')), 'lignes' => $lignes];
    }

    /**
     * Réindexe des lignes sur les en-têtes attendus par le catalogue, en rapprochant les
     * variantes d'écriture. Renvoie aussi ce qui n'a pas été reconnu, pour le dire.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @param  array<int, string>  $attendus
     * @return array{lignes: array<int, array<string, mixed>>, reconnues: array<int, string>, inconnues: array<int, string>, absentes: array<int, string>}
     */
    public function rapprocher(array $entetes, array $lignes, array $attendus): array
    {
        $index = [];
        foreach ($attendus as $attendu) {
            $index[$this->normaliser($attendu)] = $attendu;
        }

        $correspondance = [];
        $inconnues = [];
        foreach ($entetes as $entete) {
            $cle = $this->normaliser($entete);
            if (isset($index[$cle])) {
                $correspondance[$entete] = $index[$cle];
            } else {
                $inconnues[] = $entete;
            }
        }

        $reconnues = array_values($correspondance);
        $absentes = array_values(array_diff($attendus, $reconnues));

        $sortie = [];
        foreach ($lignes as $ligne) {
            $traduite = ['_ligne' => $ligne['_ligne']];
            foreach ($correspondance as $source => $cible) {
                $traduite[$cible] = $ligne[$source] ?? null;
            }
            $sortie[] = $traduite;
        }

        return [
            'lignes' => $sortie,
            'reconnues' => $reconnues,
            'inconnues' => $inconnues,
            'absentes' => $absentes,
        ];
    }

    private function nonVide(array $ligne): bool
    {
        foreach ($ligne as $cellule) {
            if (trim((string) $cellule) !== '') {
                return true;
            }
        }

        return false;
    }

    private function propre($valeur)
    {
        if (is_string($valeur)) {
            $valeur = trim($valeur);

            return $valeur === '' ? null : $valeur;
        }

        return $valeur;
    }

    /** « Prénom du pere ou tuteur » -> « prenomdupereoututeur ». */
    private function normaliser(string $entete): string
    {
        $sans = strtr(mb_strtolower(trim($entete)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $sans) ?? '';
    }
}
