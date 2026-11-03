<?php

namespace App\Services\Echange;

use App\Services\GrilleCoefficients;
use App\Services\NoteEcrivain;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Import des notes — ECONOMAT.T_NOTEENTETE + T_NOTEDETAILS, par NoteEcrivain.
 *
 * FERMÉ PAR DÉFAUT, comme la saisie à l'écran : si SchemaNotes n'a pas su rapprocher les
 * colonnes indispensables, l'import est refusé avec le détail de ce qui manque. Écrire à
 * l'aveugle dans une table de production partagée est le seul risque qu'on ne prend pas,
 * et un import le prendrait par centaines de lignes à la fois.
 *
 * UNE ÉVALUATION = classe + matière + session + type. Les lignes du fichier sont
 * regroupées là-dessus, comme l'écran regroupe une feuille de notes ; chaque groupe donne
 * une entête, retrouvée si elle existe déjà. Une note déjà saisie pour un élève est
 * REMPLACÉE, jamais dupliquée — deux lignes pour le même élève seraient toutes deux
 * comptées dans la moyenne calculée par ECONOMAT.
 *
 * L'ÉLÈVE DOIT APPARTENIR À LA CLASSE. Même contrôle qu'à la saisie : une note portée sur
 * un élève d'une autre classe fausse la moyenne de deux classes d'un coup.
 */
class ImportNotes extends Importateur
{
    /** Barème par défaut, celui de l'écran de saisie. */
    public const BAREME_DEFAUT = 20.0;

    /** Barème saisi dans le formulaire d'import, s'il l'a été. */
    private ?float $baremeDeclare = null;

    public function __construct(
        private NoteEcrivain $ecrivain,
        private GrilleCoefficients $grille,
    ) {}

    public function avecBareme(?float $bareme): self
    {
        if ($bareme !== null && $bareme > 0) {
            $this->baremeDeclare = $bareme;
        }

        return $this;
    }

    /**
     * Le barème d'une matière dans une classe : ce qui a été déclaré dans le formulaire,
     * sinon la GRILLE de l'établissement, sinon 20.
     *
     * La grille a sa place ici et pas ailleurs : un fichier d'année entière mélange les
     * matières, et « Éveil au milieu sur 50 » ne peut pas se régler par un champ unique en
     * haut de l'écran. Le barème déclaré reste prioritaire — il est explicite, la grille ne
     * l'est pas.
     */
    private function baremePour(string $classe, string $matiere): float
    {
        return $this->baremeDeclare
            ?? $this->grille->baremeDe($classe, $matiere)
            ?? self::BAREME_DEFAUT;
    }

    public function indisponible(): ?string
    {
        return parent::indisponible()
            ?? ($this->ecrivain->disponible() ? null : $this->ecrivain->raisonIndisponible());
    }

    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $classeDe = $this->classeDesEleves($lignes);

        // Le périmètre se lit dans les classes de l'établissement : illisibles, on ne
        // saurait pas écarter une classe d'une autre école.
        if (PerimetreEtablissement::raison() === 'referentiel_injoignable') {
            $this->referentielInjoignable('classes de l’établissement', new RuntimeException('Classes du périmètre illisibles.'));
        }

        // Premier temps : ce qui est recevable, et à quelle évaluation chaque ligne
        // appartient. Rien n'est encore rapproché de la base.
        $verdicts = [];
        $recevables = [];

        foreach ($lignes as $ligne) {
            if ($this->toutImporter) {
                $r = $this->recevableToutImporter($ligne, $classeDe, $annee);
                if (isset($r['rejet'])) {
                    $verdicts[] = $r['rejet'];
                } else {
                    $recevables[] = $r;
                }

                continue;
            }

            $n = (int) $ligne['_ligne'];
            $matricule = $this->texte($ligne['Matricule'] ?? null);
            $classe = $this->texte($ligne['Classe'] ?? null);
            $matiere = $this->texte($ligne['Matière'] ?? null);
            $session = $this->texte($ligne['Session'] ?? null);
            $type = $this->texte($ligne['Type'] ?? null);
            $apercu = trim(($matricule ?? '?').' — '.($matiere ?? '?').' '.($session ?? ''));

            if ($matricule === null) {
                $verdicts[] = $this->rejet($n, 'Matricule absent.', $apercu);

                continue;
            }

            $manque = array_keys(array_filter(
                ['Classe' => $classe, 'Matière' => $matiere, 'Session' => $session],
                fn ($v) => $v === null,
            ));
            if ($manque !== []) {
                $verdicts[] = $this->rejet(
                    $n,
                    implode(' et ', $manque).' : c’est ce qui identifie l’évaluation.',
                    $apercu,
                );

                continue;
            }

            // Même règle qu'à l'écran : on ne note pas une classe d'un autre établissement.
            // Une classe que la feuille Classes ouvre dans l'établissement de travail en est.
            if (! PerimetreEtablissement::classeVisible($classe) && ! isset($this->attendus['classes'][$classe])) {
                $verdicts[] = $this->rejet($n, "Classe « {$classe} » hors de l’établissement de travail.", $apercu);

                continue;
            }

            $note = $this->nombre($ligne['Note'] ?? null);
            if ($note === null) {
                $verdicts[] = $this->rejet($n, 'Note absente ou illisible.', $apercu);

                continue;
            }

            // Le barème dépend de la MATIÈRE et de la CLASSE : sur une grille de primaire,
            // la dictée est sur 10 et l'éveil au milieu sur 50. Un barème unique aurait
            // écarté l'un ou laissé passer l'autre.
            $bareme = $this->baremePour($classe, $matiere);

            if ($note < 0 || $note > $bareme) {
                $verdicts[] = $this->rejet($n, "Note hors du barème (0 à {$bareme}) pour {$matiere}.", $apercu);

                continue;
            }

            if (! isset($classeDe[$matricule])) {
                $verdicts[] = $this->rejet($n, "Aucun élève ne porte le matricule « {$matricule} ».", $apercu);

                continue;
            }

            if ($classeDe[$matricule] !== $classe) {
                $verdicts[] = $this->rejet(
                    $n,
                    "L’élève « {$matricule} » est en {$classeDe[$matricule]}, pas en {$classe}.",
                    $apercu,
                );

                continue;
            }

            $criteres = array_filter([
                'classe' => $classe,
                'matiere' => $matiere,
                'session' => $session,
                'type' => $type,
                'annee' => $annee,
                'bareme' => $bareme,
                // Le coefficient du fichier fait foi ; à défaut, celui de la grille. C'est
                // lui qu'ECONOMAT lira dans T_NOTEENTETE pour calculer la moyenne.
                'coefficient' => $this->nombre($ligne['Coefficient'] ?? null)
                    ?? $this->grille->coefficientDe($classe, $matiere),
            ], fn ($v) => $v !== null);

            $recevables[] = [
                'ligne' => $n,
                'cle' => implode('|', [$classe, $matiere, $session, (string) $type]),
                'criteres' => $criteres,
                'eleve' => $matricule,
                'note' => $note,
                'apercu' => $apercu,
            ];
        }

        // Second temps : création ou modification. Une requête par évaluation, pas une par
        // ligne — un fichier de classe, c'est 30 lignes pour une seule entête.
        $dejaNotes = [];
        foreach (collect($recevables)->groupBy('cle') as $cle => $groupe) {
            $dejaNotes[$cle] = $this->notesDejaSaisies($groupe->first()['criteres']);
        }

        $dansLeFichier = [];
        foreach ($recevables as $r) {
            $connu = isset($dejaNotes[$r['cle']][$r['eleve']]);
            $donnees = ['criteres' => $r['criteres'], 'eleve' => $r['eleve'], 'note' => $r['note']]
                + (isset($r['plan']) ? ['_classe_a_creer' => $r['plan']] : []);
            $notes = $r['notes'] ?? [];

            // Même élève, même évaluation, deux fois dans le fichier : en mode « tout
            // importer », la dernière note l'emporte et la ligne le dit.
            $avant = $dansLeFichier[$r['cle']][$r['eleve']] ?? null;
            if ($this->toutImporter && $avant !== null) {
                $connu = true;
                $notes[] = $avant['note'] === $r['note']
                    ? "ligne identique à la ligne {$avant['ligne']} : déjà prise en compte"
                    : "même élève et même évaluation que la ligne {$avant['ligne']} : cette note-ci remplace la précédente";
            }
            $dansLeFichier[$r['cle']][$r['eleve']] = ['ligne' => $r['ligne'], 'note' => $r['note']];

            $verdicts[] = $this->ajuste($connu
                ? $this->modification($r['ligne'], $donnees, $r['apercu'])
                : $this->creation($r['ligne'], $donnees, $r['apercu']), $notes);
        }

        // Le fichier se relit dans son ordre, pas dans celui du traitement.
        usort($verdicts, fn ($a, $b) => $a['ligne'] <=> $b['ligne']);

        return $verdicts;
    }

    /**
     * Mode « tout importer » : une ligne de note, rattrapée partout où c'est possible.
     *
     * Rattrapés : la classe absente ou différente de celle de l'élève (c'est la classe de
     * l'élève qui compte, comme à la saisie), la classe inconnue de l'année (créée, voir
     * Importateur::resoudreClasse), un coefficient illisible (celui de la grille).
     *
     * Restent écartées : sans matricule, sans matière ou sans session (rien n'identifie
     * l'évaluation), élève introuvable ni en base ni dans la feuille Élèves (rien à quoi
     * rattacher la note), note illisible ou hors barème (la ramener dans le barème
     * inventerait un résultat), classe d'un établissement interdit à ce compte.
     *
     * @return array  ['rejet' => verdict] ou une ligne recevable
     */
    private function recevableToutImporter(array $ligne, array $classeDe, ?string $annee): array
    {
        $n = (int) $ligne['_ligne'];
        $matricule = $this->texte($ligne['Matricule'] ?? null);
        $classe = $this->texte($ligne['Classe'] ?? null);
        $matiere = $this->texte($ligne['Matière'] ?? null);
        $session = $this->texte($ligne['Session'] ?? null);
        $type = $this->texte($ligne['Type'] ?? null);
        $apercu = trim(($matricule ?? '?').' — '.($matiere ?? '?').' '.($session ?? ''));
        $notes = [];

        if ($matricule === null) {
            return ['rejet' => $this->rejet($n, 'Matricule absent : la note ne peut être rattachée à aucun élève.', $apercu)];
        }

        $manque = array_keys(array_filter(['Matière' => $matiere, 'Session' => $session], fn ($v) => $v === null));
        if ($manque !== []) {
            return ['rejet' => $this->rejet($n, implode(' et ', $manque).' : c’est ce qui identifie l’évaluation.', $apercu)];
        }

        if (! isset($classeDe[$matricule])) {
            return ['rejet' => $this->rejet($n, "Aucun élève ne porte le matricule « {$matricule} » (ni en base, ni dans la feuille Élèves du classeur).", $apercu)];
        }

        $classeEleve = (string) $classeDe[$matricule];
        if ($classeEleve === '') {
            return ['rejet' => $this->rejet($n, "L’élève « {$matricule} » n’a pas de classe : la note n’a pas d’évaluation où se ranger.", $apercu)];
        }
        if ($classe === null) {
            $notes[] = "classe absente : celle de l’élève ({$classeEleve})";
        } elseif ($classe !== $classeEleve) {
            $notes[] = "l’élève est en {$classeEleve}, pas en {$classe} : note rangée dans {$classeEleve}";
        }
        $classe = $classeEleve;

        $plan = null;
        if (! PerimetreEtablissement::classeVisible($classe) && ! isset($this->attendus['classes'][$classe])) {
            // Pas de copie de classe pour une note : la classe est celle de l'élève, une
            // copie ailleurs n'aurait pas cet élève.
            $resolue = $this->resoudreClasse($classe, null, false);
            if ($resolue['motif'] !== null) {
                return ['rejet' => $this->rejet($n, $resolue['motif'], $apercu)];
            }
            $plan = $resolue['plan'];
            $notes[] = $resolue['note'];
            if (! empty($resolue['code'])) {
                $classe = $resolue['code'];
            }
        }

        $note = $this->nombre($ligne['Note'] ?? null);
        if ($note === null) {
            return ['rejet' => $this->rejet($n, 'Note absente ou illisible.', $apercu)];
        }

        $bareme = $this->baremePour($classe, $matiere);
        if ($note < 0 || $note > $bareme) {
            return ['rejet' => $this->rejet($n, "Note hors du barème (0 à {$bareme}) pour {$matiere} : elle n’est pas ramenée d’office dans le barème, ce serait inventer un résultat. Vérifiez la note, ou le barème déclaré.", $apercu)];
        }

        $coefficient = $this->nombre($ligne['Coefficient'] ?? null);
        if ($coefficient === null && $this->texte($ligne['Coefficient'] ?? null) !== null) {
            $notes[] = 'coefficient illisible : celui de la grille';
        }
        if ($coefficient !== null && $coefficient <= 0) {
            $notes[] = 'coefficient nul ou négatif : celui de la grille';
            $coefficient = null;
        }

        $criteres = array_filter([
            'classe' => $classe,
            'matiere' => $matiere,
            'session' => $session,
            'type' => $type,
            'annee' => $annee,
            'bareme' => $bareme,
            'coefficient' => $coefficient ?? $this->grille->coefficientDe($classe, $matiere),
        ], fn ($v) => $v !== null);

        return [
            'ligne' => $n,
            'cle' => implode('|', [$classe, $matiere, $session, (string) $type]),
            'criteres' => $criteres,
            'eleve' => $matricule,
            'note' => $note,
            'apercu' => $apercu,
            'notes' => $notes,
            'plan' => $plan,
        ];
    }

    public function appliquer(array $verdicts): array
    {
        $bilan = ['creees' => 0, 'modifiees' => 0, 'echecs' => []];

        $groupes = collect($verdicts)
            ->reject(fn ($v) => $v['action'] === self::REJET)
            ->groupBy(fn ($v) => implode('|', [
                $v['donnees']['criteres']['classe'],
                $v['donnees']['criteres']['matiere'],
                $v['donnees']['criteres']['session'],
                $v['donnees']['criteres']['type'] ?? '',
            ]));

        foreach ($groupes as $groupe) {
            try {
                $this->assurerClasse($groupe->first()['donnees']['_classe_a_creer'] ?? null);
                $entete = $this->ecrivain->entetePour($groupe->first()['donnees']['criteres']);

                // « Tout importer » : un élève noté deux fois dans le fichier n'a qu'une note
                // écrite, la dernière ; la répétition compte comme la mise à jour annoncée.
                $aEcrire = [];
                foreach ($groupe as $v) {
                    $l = ['eleve' => $v['donnees']['eleve'], 'note' => $v['donnees']['note']];
                    if ($this->toutImporter) {
                        $aEcrire[$v['donnees']['eleve']] = $l;
                    } else {
                        $aEcrire[] = $l;
                    }
                }
                $repetees = $groupe->count() - count($aEcrire);

                $resultat = $this->ecrivain->enregistrer($entete, array_values($aEcrire));

                $bilan['creees'] += $resultat['creees'];
                $bilan['modifiees'] += $resultat['modifiees'] + $repetees;
            } catch (Throwable $e) {
                // L'évaluation entière échoue, pas le fichier : les autres matières du même
                // fichier restent enregistrées, et on dit lesquelles ont manqué.
                $motif = $this->motifEchec($e, $groupe->first()['ligne']);
                foreach ($groupe as $v) {
                    $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $motif];
                }
            }
        }

        return $bilan;
    }

    /**
     * Les élèves déjà notés sur cette évaluation, matricule -> vrai.
     * Tableau vide si l'entête n'existe pas encore : tout y sera donc une création.
     * Lecture en échec : analyse refusée (le rapport annoncerait des créations fausses).
     */
    private function notesDejaSaisies(array $criteres): array
    {
        try {
            $entete = $this->ecrivain->enteteExistante($criteres);
            if ($entete === null) {
                return [];
            }

            $index = [];
            foreach ($this->ecrivain->notesDe($entete) as $note) {
                $index[trim((string) $note['eleve'])] = true;
            }

            return $index;
        } catch (Throwable $e) {
            $this->referentielInjoignable('notes déjà saisies', $e);
        }
    }

    /**
     * Matricule -> code de classe, pour tous les matricules du fichier, en une requête.
     * Élèves illisibles : analyse refusée — sans eux, aucune note ne serait rapprochée de
     * la classe de son élève.
     *
     * @return array<string, string>
     */
    private function classeDesEleves(array $lignes): array
    {
        $matricules = [];
        foreach ($lignes as $ligne) {
            $m = $this->texte($ligne['Matricule'] ?? null);
            if ($m !== null) {
                $matricules[$m] = true;
            }
        }

        if ($matricules === []) {
            return [];
        }

        $variantes = array_map(fn ($v) => trim((string) $v), ContexteScolaire::variantes());

        try {
            $index = [];
            foreach (array_chunk(array_keys($matricules), 500) as $lot) {
                DB::connection('economat')->table('T_ETUDIANT')
                    ->select('Matricule', 'CodeClasse', 'AnneeAcad')
                    ->whereIn('Matricule', $lot)
                    ->get()
                    // Une fiche par année possible : celle de l'année visée l'emporte.
                    ->sortBy(fn ($l) => in_array(trim((string) $l->AnneeAcad), $variantes, true) ? 1 : 0)
                    ->each(function ($l) use (&$index) {
                        $index[trim((string) $l->Matricule)] = trim((string) $l->CodeClasse);
                    });
            }

            // Ce que la feuille « Eleves » s'apprête à écrire l'emporte sur la base : si elle
            // change un élève de classe, c'est la nouvelle classe qui vaut pour ses notes.
            return ($this->attendus['eleves'] ?? []) + $index;
        } catch (Throwable $e) {
            $this->referentielInjoignable('élèves', $e);
        }
    }
}
