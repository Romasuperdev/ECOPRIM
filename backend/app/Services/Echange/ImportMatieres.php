<?php

namespace App\Services\Echange;

use Throwable;

/**
 * Import des matières — ECONOMAT.T_MATIERE, par le même ReferentielEcrivain que l'écran
 * Matières. Code et libellé, rien d'autre : la colonne Cycle s'exporte pour être lue,
 * mais l'écran ne l'écrit pas, et l'import pas davantage.
 *
 * LA CLÉ EST LE CODE, sans année : une matière traverse les années (cf. MatiereController).
 * Un code connu voit son libellé mis à jour ; un code nouveau est créé.
 */
class ImportMatieres extends ImportReferentiel
{
    public function __construct()
    {
        parent::__construct('T_MATIERE', 'Code', [
            'code' => 'CodeMatiere',
            'libelle' => 'LibelleMatiere',
        ]);
    }

    public function verdicts(array $lignes): array
    {
        $existantes = $this->existantes();
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
                    ? 'Code absent : c’est la clé de la matière, elle ne se devine pas.'
                    : 'Code et libellé sont obligatoires.', $apercu);

                continue;
            }

            if (isset($vus[$code]) && ! $this->toutImporter) {
                $verdicts[] = $this->rejet($n, "Matière « {$code} » déjà présente ligne {$vus[$code]['ligne']} du fichier.", $apercu);

                continue;
            }
            if (! $this->toutImporter) {
                $vus[$code] = ['ligne' => $n, 'donnees' => []];
            }

            $d = ['code' => $code];
            if ($libelle !== null) {
                $d['libelle'] = $libelle;
            } elseif (! isset($existantes[$code]) && ! isset($vus[$code])) {
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

            if ($this->toutImporter && isset($vus[$code])) {
                $verdicts[] = $this->ajuste($this->repetition($n, $d, $vus[$code], $existantes[$code] ?? null, $apercu), $notes);

                continue;
            }
            $vus[$code] = ['ligne' => $n, 'donnees' => $d];

            $verdicts[] = $this->ajuste(isset($existantes[$code])
                ? $this->modification($n, $d + ['_id' => $existantes[$code]], $apercu)
                : $this->creation($n, $d + ($this->toutImporter ? ['_cle' => $code] : []), $apercu), $notes);
        }

        return $verdicts;
    }

    /** @return array<string, int> code -> Code (clé primaire) */
    private function existantes(): array
    {
        try {
            return $this->ecrivain->requete()
                ->select('Code', 'CodeMatiere')
                ->get()
                ->mapWithKeys(fn ($l) => [trim((string) $l->CodeMatiere) => $l->Code])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable('matières existantes', $e);
        }
    }
}
