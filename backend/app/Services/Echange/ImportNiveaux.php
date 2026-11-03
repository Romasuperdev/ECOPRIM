<?php

namespace App\Services\Echange;

use App\Support\ContexteScolaire;
use Throwable;

/**
 * Import des niveaux — ECONOMAT.T_NIVEAU, par le même ReferentielEcrivain que l'écran
 * Niveaux, avec les mêmes contrôles : code et libellé obligatoires, mêmes longueurs, ordre
 * entre 0 et 99.
 *
 * LA CLÉ EST LE CODE, DANS L'ANNÉE. Un niveau appartient à une année et se recrée la
 * suivante : c'est la première feuille d'un classeur d'année neuve, celle sans laquelle
 * aucune classe ne s'ouvre. La colonne Année du fichier est ignorée — l'année est celle
 * de l'import, comme pour les élèves : c'est ce qui permet de reprendre le classeur
 * exporté l'an dernier pour ouvrir l'année qui vient.
 */
class ImportNiveaux extends ImportReferentiel
{
    public function __construct()
    {
        parent::__construct('T_NIVEAU', 'Num', [
            'code' => 'CodeNiveau',
            'libelle' => 'LibelleNiveau',
            'ordre' => 'Ordre',
            'annee' => 'ANNEE',
        ]);
    }

    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $existants = $this->existants();
        $vus = [];
        $verdicts = [];

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $code = $this->texte($ligne['Code'] ?? null);
            $libelle = $this->texte($ligne['Libellé'] ?? null);
            $apercu = trim(($code ?? '?').' — '.($libelle ?? ''));
            $notes = [];

            if ($code === null || ($libelle === null && ! $this->toutImporter)) {
                $verdicts[] = $this->rejet($n, $this->toutImporter
                    ? 'Code absent : c’est la clé du niveau, elle ne se devine pas.'
                    : 'Code et libellé sont obligatoires.', $apercu);

                continue;
            }

            if (isset($vus[$code]) && ! $this->toutImporter) {
                $verdicts[] = $this->rejet($n, "Niveau « {$code} » déjà présent ligne {$vus[$code]['ligne']} du fichier.", $apercu);

                continue;
            }
            if (! $this->toutImporter) {
                $vus[$code] = ['ligne' => $n, 'donnees' => []];
            }

            $d = ['code' => $code];
            if ($libelle !== null) {
                $d['libelle'] = $libelle;
            } elseif (! isset($existants[$code]) && ! isset($vus[$code])) {
                $d['libelle'] = $code;
                $notes[] = 'libellé absent : le code en tient lieu';
            }

            if ($this->toutImporter) {
                $d = $this->tronquer($d, ['code' => 20, 'libelle' => 60], $notes, ['code' => 'Code', 'libelle' => 'Libellé']);
                $code = $d['code'];
            } elseif (($trop = $this->depassement($d, ['code' => 20, 'libelle' => 60], ['code' => 'Code', 'libelle' => 'Libellé'])) !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            if (array_key_exists('Ordre', $ligne) && $this->texte($ligne['Ordre']) !== null) {
                $ordre = $this->nombre($ligne['Ordre']);
                if ($ordre === null || $ordre < 0 || $ordre > 99 || floor($ordre) !== $ordre) {
                    if (! $this->toutImporter) {
                        $verdicts[] = $this->rejet($n, 'Ordre : un nombre entier de 0 à 99.', $apercu);

                        continue;
                    }
                    $notes[] = "ordre « {$this->texte($ligne['Ordre'])} » illisible (entier de 0 à 99) : laissé de côté";
                } else {
                    $d['ordre'] = (int) $ordre;
                }
            }

            if ($this->toutImporter && isset($vus[$code])) {
                $verdicts[] = $this->ajuste($this->repetition($n, $d, $vus[$code], $existants[$code] ?? null, $apercu), $notes);

                continue;
            }
            $vus[$code] = ['ligne' => $n, 'donnees' => $d];

            $verdicts[] = $this->ajuste(isset($existants[$code])
                ? $this->modification($n, $d + ['_id' => $existants[$code]], $apercu)
                : $this->creation($n, $d + ['annee' => $annee] + ($this->toutImporter ? ['_cle' => $code] : []), $apercu), $notes);
        }

        return $verdicts;
    }

    /** @return array<string, int> code -> Num, pour l'année de l'import */
    private function existants(): array
    {
        try {
            return $this->ecrivain->requete()
                ->select('Num', 'CodeNiveau')
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
                ->get()
                ->mapWithKeys(fn ($l) => [trim((string) $l->CodeNiveau) => $l->Num])
                ->all();
        } catch (Throwable $e) {
            // Vide, tout passerait en création : chaque niveau de l'année serait dupliqué.
            $this->referentielInjoignable('niveaux existants', $e);
        }
    }
}
