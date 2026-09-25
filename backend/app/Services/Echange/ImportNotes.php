<?php

namespace App\Services\Echange;

use App\Services\NoteEcrivain;
use App\Support\ContexteScolaire;
use Illuminate\Support\Facades\DB;
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

    private float $bareme = self::BAREME_DEFAUT;

    /** @var array<string, string> matricule -> classe, pour des élèves pas encore en base */
    private array $elevesAttendus = [];

    public function __construct(private NoteEcrivain $ecrivain) {}

    public function avecBareme(?float $bareme): self
    {
        if ($bareme !== null && $bareme > 0) {
            $this->bareme = $bareme;
        }

        return $this;
    }

    /**
     * Élèves que la feuille « Eleves » du même classeur va créer ou déplacer.
     *
     * Sans cela, l'import d'une année entière serait inutilisable : les notes sont analysées
     * avant que les élèves n'existent, et TOUTES seraient écartées pour « matricule inconnu »
     * — alors que la feuille d'à côté les crée. Le rapport doit dire ce qui va se passer, pas
     * ce qui se passerait si on n'importait que les notes.
     *
     * @param  array<string, string>  $index  matricule -> classe
     */
    public function avecEleves(array $index): self
    {
        $this->elevesAttendus = $index;

        return $this;
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

        // Premier temps : ce qui est recevable, et à quelle évaluation chaque ligne
        // appartient. Rien n'est encore rapproché de la base.
        $verdicts = [];
        $recevables = [];

        foreach ($lignes as $ligne) {
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

            $note = $this->nombre($ligne['Note'] ?? null);
            if ($note === null) {
                $verdicts[] = $this->rejet($n, 'Note absente ou illisible.', $apercu);

                continue;
            }

            if ($note < 0 || $note > $this->bareme) {
                $verdicts[] = $this->rejet($n, "Note hors du barème (0 à {$this->bareme}).", $apercu);

                continue;
            }

            if ($classeDe !== null && ! isset($classeDe[$matricule])) {
                $verdicts[] = $this->rejet($n, "Aucun élève ne porte le matricule « {$matricule} ».", $apercu);

                continue;
            }

            if ($classeDe !== null && $classeDe[$matricule] !== $classe) {
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
                'bareme' => $this->bareme,
                'coefficient' => $this->nombre($ligne['Coefficient'] ?? null),
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

        foreach ($recevables as $r) {
            $connu = isset($dejaNotes[$r['cle']][$r['eleve']]);
            $donnees = ['criteres' => $r['criteres'], 'eleve' => $r['eleve'], 'note' => $r['note']];

            $verdicts[] = $connu
                ? $this->modification($r['ligne'], $donnees, $r['apercu'])
                : $this->creation($r['ligne'], $donnees, $r['apercu']);
        }

        // Le fichier se relit dans son ordre, pas dans celui du traitement.
        usort($verdicts, fn ($a, $b) => $a['ligne'] <=> $b['ligne']);

        return $verdicts;
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
                $entete = $this->ecrivain->entetePour($groupe->first()['donnees']['criteres']);
                $resultat = $this->ecrivain->enregistrer($entete, $groupe->map(fn ($v) => [
                    'eleve' => $v['donnees']['eleve'],
                    'note' => $v['donnees']['note'],
                ])->all());

                $bilan['creees'] += $resultat['creees'];
                $bilan['modifiees'] += $resultat['modifiees'];
            } catch (Throwable $e) {
                // L'évaluation entière échoue, pas le fichier : les autres matières du même
                // fichier restent enregistrées, et on dit lesquelles ont manqué.
                foreach ($groupe as $v) {
                    $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $e->getMessage()];
                }
            }
        }

        return $bilan;
    }

    /**
     * Les élèves déjà notés sur cette évaluation, matricule -> vrai.
     * Tableau vide si l'entête n'existe pas encore : tout y sera donc une création.
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
            return [];
        }
    }

    /**
     * Matricule -> code de classe, pour tous les matricules du fichier, en une requête.
     * `null` = T_ETUDIANT injoignable ; on n'invente alors aucun rejet.
     *
     * @return array<string, string>|null
     */
    private function classeDesEleves(array $lignes): ?array
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

        try {
            $index = [];
            foreach (array_chunk(array_keys($matricules), 500) as $lot) {
                DB::connection('economat')->table('T_ETUDIANT')
                    ->select('Matricule', 'CodeClasse')
                    ->whereIn('Matricule', $lot)
                    ->get()
                    ->each(function ($l) use (&$index) {
                        $index[trim((string) $l->Matricule)] = trim((string) $l->CodeClasse);
                    });
            }

            // Ce que la feuille « Eleves » s'apprête à écrire l'emporte sur la base : si elle
            // change un élève de classe, c'est la nouvelle classe qui vaut pour ses notes.
            return $this->elevesAttendus + $index;
        } catch (Throwable $e) {
            return null;
        }
    }
}
