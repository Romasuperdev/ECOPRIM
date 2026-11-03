<?php

namespace App\Services\Echange;

use App\Services\AbsenceEcrivain;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Import des absences — ECONOMAT.dbo.T_ABSENCEELEVE, par AbsenceEcrivain, sous les règles
 * de l'écran Absences :
 *   - l'élève doit exister dans l'année et dans l'établissement (ou être créé par la
 *     feuille Élèves du même classeur) ;
 *   - LA CLASSE SUIT L'ÉLÈVE : elle n'est pas prise dans le fichier. Une colonne Classe
 *     qui contredit la classe de l'élève fait écarter la ligne — c'est le signe d'une
 *     ligne décalée, pas d'une absence dans une autre classe ;
 *   - pas d'absence dans le futur ;
 *   - même élève, même jour, même heure : c'est la même absence. Déjà saisie, elle est
 *     mise à jour (motif, justification) au lieu d'être dupliquée.
 */
class ImportAbsences extends Importateur
{
    public function __construct(private AbsenceEcrivain $ecrivain) {}

    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $eleves = $this->elevesDuFichier($lignes);
        $existantes = $this->existantes();
        $aujourdhui = CarbonImmutable::today()->toDateString();
        $vus = [];
        $verdicts = [];

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $matricule = $this->texte($ligne['Matricule'] ?? null);
            $brute = $this->texte($ligne['Date'] ?? null);
            $heure = $this->texte($ligne['Heure'] ?? null);
            $apercu = trim(($matricule ?? '?').' — '.($brute ?? '?').' '.($heure ?? ''));

            if ($matricule === null || $brute === null) {
                $verdicts[] = $this->rejet($n, 'Matricule et date sont obligatoires.', $apercu);

                continue;
            }

            $date = $this->date($brute);
            if ($date === null) {
                $verdicts[] = $this->rejet($n, 'Date illisible. Formats admis : 25/09/2026 ou 2026-09-25.', $apercu);

                continue;
            }

            if ($date > $aujourdhui) {
                $verdicts[] = $this->rejet($n, 'Une absence ne se saisit pas dans le futur.', $apercu);

                continue;
            }

            $classe = $eleves[$matricule] ?? null;
            if ($classe === null) {
                $verdicts[] = $this->rejet($n, "Aucun élève de l’année {$annee} ne porte le matricule « {$matricule} » dans cet établissement.", $apercu);

                continue;
            }

            $notes = [];
            $classeFichier = $this->texte($ligne['Classe'] ?? null);
            if ($classeFichier !== null && $classeFichier !== $classe) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, "L’élève « {$matricule} » est en {$classe}, pas en {$classeFichier}.", $apercu);

                    continue;
                }
                // C'est la classe de l'élève qui compte, comme à l'écran Absences.
                $notes[] = "l’élève est en {$classe}, pas en {$classeFichier} : absence rangée dans {$classe}";
            }

            $d = [
                'matricule' => $matricule,
                'classe' => $classe,
                'date' => $date,
                'heure' => $heure,
            ];
            if (array_key_exists('Motif', $ligne)) {
                $d['motif'] = $this->texte($ligne['Motif']);
            }
            if (array_key_exists('Justifiée', $ligne)) {
                $d['justifiee'] = $this->booleen($ligne['Justifiée']);
            }

            if ($this->toutImporter) {
                $d = $this->tronquer($d, ['heure' => 20, 'motif' => 200], $notes, ['heure' => 'Heure', 'motif' => 'Motif']);
                $heure = $d['heure'];
            } elseif (($trop = $this->depassement($d, ['heure' => 20, 'motif' => 200], ['heure' => 'Heure', 'motif' => 'Motif'])) !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            $cle = $this->cle($matricule, $date, $heure);
            if (isset($vus[$cle])) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, "Même absence que la ligne {$vus[$cle]} du fichier.", $apercu);

                    continue;
                }

                // La même absence deux fois : la seconde ligne met à jour la première.
                $notes[] = "même absence que la ligne {$vus[$cle]} : cette ligne la met à jour (motif, justification)";
                $verdicts[] = $this->ajuste($this->modification(
                    $n,
                    array_intersect_key($d, array_flip(['motif', 'justifiee'])) + ['_cle' => $cle]
                        + (isset($existantes[$cle]) ? ['_code' => $existantes[$cle]] : []),
                    $apercu,
                ), $notes);

                continue;
            }
            $vus[$cle] = $n;

            $verdicts[] = $this->ajuste(isset($existantes[$cle])
                ? $this->modification($n, array_intersect_key($d, array_flip(['motif', 'justifiee'])) + ['_code' => $existantes[$cle]], $apercu)
                : $this->creation($n, $d + ['annee' => $annee, 'justifiee' => $d['justifiee'] ?? false]
                    + ($this->toutImporter ? ['_cle' => $cle] : []), $apercu), $notes);
        }

        return $verdicts;
    }

    public function appliquer(array $verdicts): array
    {
        $bilan = ['creees' => 0, 'modifiees' => 0, 'echecs' => []];
        $creees = [];
        $codes = $this->codesEleves(array_column(array_column(
            array_filter($verdicts, fn ($v) => $v['action'] === self::CREATION), 'donnees'), 'matricule'));

        foreach ($verdicts as $v) {
            if ($v['action'] === self::REJET) {
                continue;
            }

            try {
                $d = $v['donnees'];
                $cle = $d['_cle'] ?? null;
                unset($d['_cle']);
                // Ligne répétée : l'absence que la précédente vient de créer.
                if (! isset($d['_code']) && $v['action'] === self::MODIFICATION && $cle !== null && isset($creees[$cle])) {
                    $d['_code'] = $creees[$cle];
                }
                if ($v['action'] === self::MODIFICATION && ! isset($d['_code'])) {
                    throw new RuntimeException('L’absence de la ligne précédente n’a pas pu être écrite : celle-ci non plus.');
                }
                if (isset($d['_code'])) {
                    $code = (int) $d['_code'];
                    unset($d['_code']);
                    $this->ecrivain->modifier($code, $d);
                    $bilan['modifiees']++;

                    continue;
                }

                // L'élève a pu être créé par la feuille Élèves juste avant : son code
                // interne n'existait pas encore à l'analyse, il se lit maintenant.
                $eleve = $codes[$d['matricule']] ?? null;
                if ($eleve === null) {
                    throw new RuntimeException("L’élève « {$d['matricule']} » est introuvable : absence non écrite.");
                }
                $nouvelle = $this->ecrivain->creer($d + ['eleve_code' => $eleve]);
                if ($cle !== null) {
                    $creees[$cle] = $nouvelle;
                }
                $bilan['creees']++;
            } catch (Throwable $e) {
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $this->motifEchec($e, $v['ligne'])];
            }
        }

        return $bilan;
    }

    /** Élève, jour, heure : l'identité d'une absence, comme AbsenceEcrivain::doublon(). */
    private function cle(string $matricule, string $date, ?string $heure): string
    {
        return mb_strtolower(implode('|', [$matricule, $date, trim((string) $heure)]));
    }

    /**
     * Matricule -> classe des élèves du fichier : ceux de l'année et de l'établissement,
     * plus ceux que la feuille Élèves va créer — qui l'emportent, comme pour les notes.
     *
     * @return array<string, string>
     */
    private function elevesDuFichier(array $lignes): array
    {
        $matricules = [];
        foreach ($lignes as $ligne) {
            if (($m = $this->texte($ligne['Matricule'] ?? null)) !== null) {
                $matricules[$m] = true;
            }
        }

        $index = [];
        try {
            foreach (array_chunk(array_keys($matricules), 500) as $lot) {
                DB::connection('economat')->table('T_ETUDIANT')
                    ->select('Matricule', 'CodeClasse')
                    ->whereIn('Matricule', $lot)
                    ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
                    ->get()
                    ->each(function ($l) use (&$index) {
                        $classe = trim((string) $l->CodeClasse);
                        if (PerimetreEtablissement::classeVisible($classe)) {
                            $index[trim((string) $l->Matricule)] = $classe;
                        }
                    });
            }
        } catch (Throwable $e) {
            $this->referentielInjoignable('élèves', $e);
        }

        return ($this->attendus['eleves'] ?? []) + $index;
    }

    /** @return array<string, int> clé d'absence -> Code, pour l'année et l'établissement */
    private function existantes(): array
    {
        try {
            $index = [];
            (new CatalogueDonnees)->requete('absences')
                ->select('Code', 'Matricule', 'Date', 'Heure')
                ->get()
                ->each(function ($a) use (&$index) {
                    $date = substr((string) $a->Date, 0, 10);
                    $index[$this->cle(trim((string) $a->Matricule), $date, $a->Heure)] = (int) $a->Code;
                });

            return $index;
        } catch (Throwable $e) {
            // Vide, tout passerait en création : un fichier réimporté se dupliquerait.
            $this->referentielInjoignable('absences existantes', $e);
        }
    }

    /** @return array<string, int> matricule -> Code de T_ETUDIANT, lu au moment d'écrire */
    private function codesEleves(array $matricules): array
    {
        $codes = [];
        foreach (array_chunk(array_values(array_unique($matricules)), 500) as $lot) {
            DB::connection('economat')->table('T_ETUDIANT')
                ->select('Code', 'Matricule')
                ->whereIn('Matricule', $lot)
                ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'AnneeAcad'))
                ->get()
                ->each(function ($l) use (&$codes) {
                    $codes[trim((string) $l->Matricule)] = (int) $l->Code;
                });
        }

        return $codes;
    }
}
