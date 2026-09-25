<?php

namespace App\Services\Echange;

use App\Models\Evaluation;
use App\Support\ContexteScolaire;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import des évaluations planifiées — table `ecoprim.evaluations`, propre à NEXORA.
 *
 * Le seul des trois jeux importables qui n'écrive pas dans ECONOMAT : c'est notre table,
 * personne d'autre ne s'en sert. D'où des contrôles calqués non pas sur la prudence due à
 * une base partagée, mais simplement sur ceux de l'écran Évaluations — mêmes champs
 * obligatoires, même liste de types, mêmes bornes. Un fichier n'a pas à pouvoir écrire ce
 * que le formulaire refuserait.
 *
 * IDENTITÉ : classe + matière + type + titre. Réimporter un fichier exporté met donc les
 * mêmes lignes à jour au lieu de les dupliquer — c'est la manœuvre la plus courante, on
 * exporte pour corriger dans un tableur et on réimporte.
 */
class ImportEvaluations extends Importateur
{
    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $classes = $this->referentiel('classes', 'CodeClasse');
        $matieres = $this->referentiel('matieres', 'CodeMatiere');
        $existantes = $this->existantes($annee);

        $verdicts = [];
        $vus = [];

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $titre = $this->texte($ligne['Titre'] ?? null);
            $classe = $this->texte($ligne['Classe'] ?? null);
            $matiere = $this->texte($ligne['Matière'] ?? null);
            $type = $this->texte($ligne['Type'] ?? null);
            $apercu = trim(($titre ?? '?').' — '.($classe ?? '?').' / '.($matiere ?? '?'));

            $manque = array_keys(array_filter(
                ['Titre' => $titre, 'Classe' => $classe, 'Matière' => $matiere, 'Type' => $type],
                fn ($v) => $v === null,
            ));
            if ($manque !== []) {
                $verdicts[] = $this->rejet($n, implode(', ', $manque).' : champ obligatoire.', $apercu);

                continue;
            }

            if (! in_array($type, Evaluation::TYPES, true)) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Type « {$type} » inconnu. Attendus : ".implode(', ', Evaluation::TYPES).'.',
                    $apercu,
                );

                continue;
            }

            if ($classes !== null && ! isset($classes[$classe])) {
                $verdicts[] = $this->rejet($n, "Classe « {$classe} » inconnue pour l’année {$annee}.", $apercu);

                continue;
            }

            if ($matieres !== null && ! isset($matieres[$matiere])) {
                $verdicts[] = $this->rejet($n, "Matière « {$matiere} » inconnue.", $apercu);

                continue;
            }

            $date = $this->date($ligne['Date'] ?? null);
            if ($date === null) {
                $verdicts[] = $this->rejet($n, 'Date absente ou illisible. Formats admis : 25/09/2026 ou 2026-09-25.', $apercu);

                continue;
            }

            $coefficient = $this->nombre($ligne['Coefficient'] ?? null) ?? 1.0;
            $noteMax = $this->nombre($ligne['Note maximale'] ?? null) ?? 20.0;

            if ($coefficient < 0.1 || $coefficient > 20) {
                $verdicts[] = $this->rejet($n, 'Coefficient hors bornes (0,1 à 20).', $apercu);

                continue;
            }

            if ($noteMax < 1 || $noteMax > 100) {
                $verdicts[] = $this->rejet($n, 'Note maximale hors bornes (1 à 100).', $apercu);

                continue;
            }

            $cle = mb_strtolower(implode('|', [$classe, $matiere, $type, $titre]));

            if (isset($vus[$cle])) {
                $verdicts[] = $this->rejet($n, "Même évaluation que la ligne {$vus[$cle]} du fichier.", $apercu);

                continue;
            }
            $vus[$cle] = $n;

            $donnees = [
                'titre' => $titre,
                'classe_code' => $classe,
                'matiere_code' => $matiere,
                'enseignant_code' => $this->texte($ligne['Enseignant'] ?? null),
                'type' => $type,
                'date' => $date,
                'heure_debut' => $this->texte($ligne['Heure de début'] ?? null),
                'heure_fin' => $this->texte($ligne['Heure de fin'] ?? null),
                'coefficient' => $coefficient,
                'note_maximale' => $noteMax,
                'annee' => $annee,
            ];

            $verdicts[] = isset($existantes[$cle])
                ? $this->modification($n, $donnees + ['_id' => $existantes[$cle]], $apercu)
                : $this->creation($n, $donnees, $apercu);
        }

        return $verdicts;
    }

    public function appliquer(array $verdicts): array
    {
        $bilan = ['creees' => 0, 'modifiees' => 0, 'echecs' => []];

        foreach ($verdicts as $v) {
            if ($v['action'] === self::REJET) {
                continue;
            }

            try {
                $donnees = $v['donnees'];
                $id = $donnees['_id'] ?? null;
                unset($donnees['_id']);

                if ($id !== null) {
                    Evaluation::whereKey($id)->update($donnees);
                    $bilan['modifiees']++;
                } else {
                    Evaluation::create($donnees);
                    $bilan['creees']++;
                }
            } catch (Throwable $e) {
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $e->getMessage()];
            }
        }

        return $bilan;
    }

    /** Codes valides d'un référentiel du catalogue, ou null s'il est injoignable. */
    private function referentiel(string $jeu, string $colonne): ?array
    {
        try {
            return (new CatalogueDonnees)->requete($jeu)
                ->pluck($colonne)
                ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
                ->all();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<string, int> identité -> id */
    private function existantes(?string $annee): array
    {
        try {
            $index = [];
            DB::connection('ecoprim')->table('evaluations')
                ->select('id', 'titre', 'classe_code', 'matiere_code', 'type')
                ->when($annee, fn ($q) => $q->whereIn('annee', ContexteScolaire::variantesDe($annee)))
                ->get()
                ->each(function ($e) use (&$index) {
                    $cle = mb_strtolower(implode('|', [$e->classe_code, $e->matiere_code, $e->type, $e->titre]));
                    $index[$cle] = (int) $e->id;
                });

            return $index;
        } catch (Throwable $e) {
            return [];
        }
    }
}
