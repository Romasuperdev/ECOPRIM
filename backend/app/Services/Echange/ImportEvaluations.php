<?php

namespace App\Services\Echange;

use App\Models\Evaluation;
use App\Services\ReferentielEcrivain;
use App\Support\ContexteScolaire;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import des évaluations planifiées — table `ecoprim.evaluations`, propre à NEXORA.
 *
 * Le seul des jeux importables qui n'écrive pas dans ECONOMAT : c'est notre table,
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
        // Ce que les feuilles Classes et Matières du même classeur créent compte comme connu.
        $classes = $this->avecAttendusDe('classes', $this->referentiel('classes', 'CodeClasse'));
        $matieres = $this->avecAttendusDe('matieres', $this->referentiel('matieres', 'CodeMatiere'));
        $existantes = $this->existantes($annee);

        $verdicts = [];
        $vus = [];

        foreach ($lignes as $ligne) {
            if ($this->toutImporter) {
                $verdicts[] = $this->verdictToutImporter($ligne, $annee, $classes, $matieres, $existantes, $vus);

                continue;
            }

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

            if (! isset($classes[$classe])) {
                $verdicts[] = $this->rejet($n, "Classe « {$classe} » inconnue pour l’année {$annee}.", $apercu);

                continue;
            }

            if (! isset($matieres[$matiere])) {
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
        // Identité -> id des évaluations créées par CE fichier : une ligne répétée plus bas
        // (« tout importer ») met à jour celle que la précédente vient de créer.
        $creees = [];

        foreach ($verdicts as $v) {
            if ($v['action'] === self::REJET) {
                continue;
            }

            try {
                $donnees = $v['donnees'];
                $this->assurerClasse($donnees['_classe_a_creer'] ?? null);
                $this->assurerMatiere($donnees['_matiere_a_creer'] ?? null);
                $cle = $donnees['_cle'] ?? null;
                $id = $donnees['_id'] ?? ($cle !== null ? ($creees[$cle] ?? null) : null);
                unset($donnees['_id'], $donnees['_cle'], $donnees['_classe_a_creer'], $donnees['_matiere_a_creer']);

                if ($id !== null) {
                    Evaluation::whereKey($id)->update($donnees);
                    $bilan['modifiees']++;
                } else {
                    $nouvelle = Evaluation::create($donnees);
                    if ($cle !== null) {
                        $creees[$cle] = $nouvelle->getKey();
                    }
                    $bilan['creees']++;
                }
            } catch (Throwable $e) {
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $this->motifEchec($e, $v['ligne'])];
            }
        }

        return $bilan;
    }

    /** Largeurs des colonnes de `evaluations` (migration console). */
    private const LARGEURS = [
        'titre' => 150, 'classe_code' => 50, 'matiere_code' => 50, 'type' => 50,
        'heure_debut' => 10, 'heure_fin' => 10,
    ];

    private const LIBELLES = [
        'titre' => 'Titre', 'classe_code' => 'Classe', 'matiere_code' => 'Matière', 'type' => 'Type',
        'heure_debut' => 'Heure de début', 'heure_fin' => 'Heure de fin',
    ];

    /** Type retenu quand celui du fichier manque ou n'est pas reconnu. */
    private const TYPE_PAR_DEFAUT = 'Composition';

    /** @var array<string, true> */
    private array $matieresAssurees = [];

    /**
     * Mode « tout importer » : une ligne d'évaluation, rattrapée partout où c'est possible.
     *
     * Rattrapés : type absent ou inconnu (reconnu sans casse ni accents, sinon
     * « Composition »), titre absent (composé du type, de la matière et de la date), classe
     * inconnue (créée, voir Importateur::resoudreClasse), matière inconnue (créée),
     * coefficient et note maximale hors bornes (ramenés dans les bornes) ou illisibles
     * (valeur par défaut), enseignant non numérique (laissé vide), texte trop long (coupé),
     * évaluation répétée dans le fichier (mise à jour de la précédente).
     *
     * Restent écartées : sans classe ou sans matière, sans date lisible (une évaluation se
     * place dans le calendrier, sa date ne s'invente pas), classe d'un établissement
     * interdit à ce compte.
     */
    private function verdictToutImporter(array $ligne, ?string $annee, array $classes, array $matieres, array $existantes, array &$vus): array
    {
        $n = (int) $ligne['_ligne'];
        $titre = $this->texte($ligne['Titre'] ?? null);
        $classe = $this->texte($ligne['Classe'] ?? null);
        $matiere = $this->texte($ligne['Matière'] ?? null);
        $type = $this->texte($ligne['Type'] ?? null);
        $apercu = trim(($titre ?? '?').' — '.($classe ?? '?').' / '.($matiere ?? '?'));
        $notes = [];
        $donnees = [];

        $manque = array_keys(array_filter(['Classe' => $classe, 'Matière' => $matiere], fn ($v) => $v === null));
        if ($manque !== []) {
            return $this->rejet($n, implode(', ', $manque).' : champ obligatoire, rien ne permet de le deviner.', $apercu);
        }

        $date = $this->date($ligne['Date'] ?? null);
        if ($date === null) {
            return $this->rejet($n, 'Date absente ou illisible : une évaluation se place dans le calendrier, sa date ne s’invente pas. Formats admis : 25/09/2026 ou 2026-09-25.', $apercu);
        }

        if ($type === null) {
            $type = self::TYPE_PAR_DEFAUT;
            $notes[] = "type absent : « {$type} » retenu";
        } elseif (! in_array($type, Evaluation::TYPES, true)) {
            $reconnu = collect(Evaluation::TYPES)->first(fn ($t) => $this->normaliser($t) === $this->normaliser($type));
            $notes[] = $reconnu !== null
                ? "type « {$type} » lu comme « {$reconnu} »"
                : "type « {$type} » inconnu : « ".self::TYPE_PAR_DEFAUT.' » retenu';
            $type = $reconnu ?? self::TYPE_PAR_DEFAUT;
        }

        if (! isset($classes[$classe])) {
            $resolue = $this->resoudreClasse($classe);
            if ($resolue['motif'] !== null) {
                return $this->rejet($n, $resolue['motif'], $apercu);
            }
            if ($resolue['plan'] !== null) {
                $donnees['_classe_a_creer'] = $resolue['plan'];
            }
            if (! empty($resolue['code']) && $resolue['code'] !== $classe) {
                $classe = $resolue['code'];
                $donnees['classe_code'] = $classe;
            }
            $notes[] = $resolue['note'];
        }

        if (! isset($matieres[$matiere])) {
            $donnees['_matiere_a_creer'] = mb_substr($matiere, 0, 20);
            $notes[] = "matière « {$matiere} » inconnue : créée à l’import";
        }

        if ($titre === null) {
            $titre = "{$type} {$matiere} du ".CarbonImmutable::parse($date)->format('d/m/Y');
            $notes[] = "titre absent : « {$titre} »";
            $apercu = trim($titre.' — '.$classe.' / '.$matiere);
        }

        $coefficient = $this->borne($ligne['Coefficient'] ?? null, 1.0, 0.1, 20, 'coefficient', $notes);
        $noteMax = $this->borne($ligne['Note maximale'] ?? null, 20.0, 1, 100, 'note maximale', $notes);

        $enseignant = $this->texte($ligne['Enseignant'] ?? null);
        if ($enseignant !== null && ! ctype_digit($enseignant)) {
            $notes[] = "enseignant « {$enseignant} » : un code numérique est attendu, laissé vide";
            $enseignant = null;
        }

        $donnees += $this->tronquer([
            'titre' => $titre,
            'classe_code' => $classe,
            'matiere_code' => mb_substr($matiere, 0, 20),
            'enseignant_code' => $enseignant,
            'type' => $type,
            'date' => $date,
            'heure_debut' => $this->texte($ligne['Heure de début'] ?? null),
            'heure_fin' => $this->texte($ligne['Heure de fin'] ?? null),
            'coefficient' => $coefficient,
            'note_maximale' => $noteMax,
            'annee' => $annee,
        ], self::LARGEURS, $notes, self::LIBELLES);

        $cle = mb_strtolower(implode('|', [$classe, $donnees['matiere_code'], $type, $donnees['titre']]));

        if (isset($vus[$cle])) {
            $notes[] = "même évaluation que la ligne {$vus[$cle]} : cette ligne la met à jour";

            return $this->ajuste($this->modification(
                $n,
                $donnees + ['_cle' => $cle] + (isset($existantes[$cle]) ? ['_id' => $existantes[$cle]] : []),
                $apercu,
            ), $notes);
        }
        $vus[$cle] = $n;

        return $this->ajuste(isset($existantes[$cle])
            ? $this->modification($n, $donnees + ['_id' => $existantes[$cle]], $apercu)
            : $this->creation($n, $donnees + ['_cle' => $cle], $apercu), $notes);
    }

    /** Un nombre ramené dans ses bornes (ou sa valeur par défaut s'il est illisible), et dit. */
    private function borne($brut, float $defaut, float $min, float $max, string $libelle, array &$notes): float
    {
        $valeur = $this->nombre($brut);
        $fr = fn (float $x) => str_replace('.', ',', (string) $x);

        if ($valeur === null) {
            if (($texte = $this->texte($brut)) !== null) {
                $notes[] = "{$libelle} « {$texte} » illisible : {$fr($defaut)} retenu";
            }

            return $defaut;
        }

        if ($valeur < $min || $valeur > $max) {
            $ramene = max($min, min($max, $valeur));
            $notes[] = "{$libelle} {$fr($valeur)} hors bornes ({$fr($min)} à {$fr($max)}) : ramené à {$fr($ramene)}";

            return $ramene;
        }

        return $valeur;
    }

    /** Crée, au moment d'écrire, la matière inconnue — par le même écrivain que l'écran Matières. */
    private function assurerMatiere(?string $code): void
    {
        if ($code === null || isset($this->matieresAssurees[$code])) {
            return;
        }

        $ecrivain = new ReferentielEcrivain('T_MATIERE', 'Code', [
            'code' => 'CodeMatiere',
            'libelle' => 'LibelleMatiere',
        ]);
        if (! $ecrivain->codeExiste('code', $code)) {
            $ecrivain->creer(['code' => $code, 'libelle' => $code]);
        }

        $this->matieresAssurees[$code] = true;
    }

    /** Codes valides d'un référentiel du catalogue. Injoignable : analyse refusée. */
    private function referentiel(string $jeu, string $colonne): array
    {
        try {
            return (new CatalogueDonnees)->requete($jeu)
                ->pluck($colonne)
                ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable($jeu, $e);
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
            // Vide, tout passerait en création : le fichier réimporté se dupliquerait.
            $this->referentielInjoignable('évaluations existantes', $e);
        }
    }
}
