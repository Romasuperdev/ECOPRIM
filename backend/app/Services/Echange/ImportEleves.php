<?php

namespace App\Services\Echange;

use App\Models\Console\Etablissement;
use App\Services\EtudiantEcrivain;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import des élèves — ECONOMAT.dbo.T_ETUDIANT, par EtudiantEcrivain.
 *
 * Passe par l'écrivain existant et pas par un INSERT à part : c'est lui qui connaît la
 * liste blanche des colonnes, et un import qui écrirait ailleurs toucherait au financier
 * (Scolarite, TotalPaye, Remise…) que NEXORA s'interdit depuis le premier jour.
 *
 * LA CLÉ EST LE MATRICULE. Il existe déjà -> la fiche est mise à jour ; il n'existe pas ->
 * l'élève est créé. Rien n'est jamais supprimé.
 *
 * REJETS PLUTÔT QUE RATTRAPAGES. Une classe inconnue, un matricule vide, un doublon dans
 * le fichier : la ligne est écartée et dite, les autres passent. Créer quand même l'élève
 * avec une classe fausse le rendrait invisible partout — les écrans, les effectifs et les
 * bulletins passent tous par la classe — et personne ne saurait qu'il manque.
 */
class ImportEleves extends Importateur
{
    /** En-tête du catalogue -> champ attendu par EtudiantEcrivain. */
    private const MAP = [
        'Matricule' => 'matricule',
        'Nom' => 'nom',
        'Prénom' => 'prenom',
        'Sexe' => 'sexe',
        'Date de naissance' => 'date_naissance',
        'Lieu de naissance' => 'lieu_naissance',
        'Nationalité' => 'nationalite',
        'Classe' => 'classe_code',
        'Niveau' => 'niveau_code',
        'Redoublant' => 'redoublant',
        'Nom du pere ou tuteur' => 'pere_nom',
        'Prénom du pere ou tuteur' => 'pere_prenom',
        'Profession du pere ou tuteur' => 'pere_profession',
        'Téléphone du pere ou tuteur' => 'pere_telephone',
        'Email du pere ou tuteur' => 'pere_email',
        'Nom de la mere' => 'mere_nom',
        'Prénom de la mere' => 'mere_prenom',
        'Profession de la mere' => 'mere_profession',
        'Téléphone de la mere' => 'mere_telephone',
        'Email de la mere' => 'mere_email',
    ];

    /** Champs traités comme des dates plutôt que comme du texte. */
    private const DATES = ['date_naissance'];

    public function __construct(private EtudiantEcrivain $ecrivain) {}

    public function verdicts(array $lignes): array
    {
        // Les classes que la feuille Classes ouvre comptent comme connues.
        $classes = $this->avecAttendusDe('classes', $this->classesConnues());
        $existants = $this->matriculesExistants($lignes);
        $annee = ContexteScolaire::annee();
        $societe = $this->societe();

        // Tous les matricules du fichier : un matricule attribué à l'import (« tout
        // importer ») ne doit pas tomber sur celui d'une ligne plus bas.
        $duFichier = [];
        foreach ($lignes as $ligne) {
            if (($m = $this->texte($ligne['Matricule'] ?? null)) !== null) {
                $duFichier[$m] = true;
            }
        }

        $vus = [];
        $noms = [];
        $bruts = [];
        $verdicts = [];

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $d = $this->champs($ligne);
            $note = null;
            $notes = [];

            $matricule = $d['matricule'] ?? null;
            $nom = $d['nom'] ?? null;
            $prenom = $d['prenom'] ?? null;
            $apercu = trim(($matricule ?? '?').' — '.trim(($nom ?? '').' '.($prenom ?? '')));

            if ($matricule === null) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, 'Matricule absent : c’est la clé sur laquelle la fiche est reconnue.', $apercu);

                    continue;
                }
                if ($nom === null && $prenom === null) {
                    $verdicts[] = $this->rejet($n, 'Ni matricule, ni nom, ni prénom : rien n’identifie l’élève.', $apercu);

                    continue;
                }

                // Déjà inscrit (même nom, prénom et date de naissance) ? Sa fiche est reprise.
                $connu = $this->memeEleve($d);
                if ($connu !== null) {
                    $matricule = trim((string) $connu->Matricule);
                    $existants[$matricule] ??= ['code' => (int) $connu->Code, 'annee' => trim((string) ($connu->AnneeAcad ?? ''))];
                    $notes[] = "matricule absent : élève reconnu par nom, prénom et date de naissance (« {$matricule} »)";
                } else {
                    $millesime = AnneeImport::paire((string) $annee)[0] ?? (int) date('Y');
                    $matricule = $this->matriculeLibre(sprintf('IMP%s-%04d', $millesime, $n), $duFichier, true);
                    $notes[] = "matricule absent : « {$matricule} » attribué";
                }
                $d['matricule'] = $matricule;
                $duFichier[$matricule] = true;
                $apercu = trim($matricule.' — '.trim(($nom ?? '').' '.($prenom ?? '')));
            }

            $identite = mb_strtolower(trim(($nom ?? '').' '.($prenom ?? '')));
            $repete = null;

            if (isset($vus[$matricule])) {
                // Deux enfants différents sous le même numéro : avec « Tout importer », le
                // second reçoit un matricule marqué, dit dans le rapport pour être corrigé.
                // Le même enfant deux fois : la seconde ligne met à jour la fiche de la
                // première (en mode strict, elle est écartée).
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, "Matricule « {$matricule} » déjà présent ligne {$vus[$matricule]} du fichier.", $apercu);

                    continue;
                }

                if ($identite === $noms[$matricule] || $nom === null || $prenom === null) {
                    $repete = $vus[$matricule];
                    $notes[] = $bruts[$matricule] == $d
                        ? "ligne identique à la ligne {$repete} : déjà prise en compte"
                        : "même élève que la ligne {$repete} : cette ligne complète sa fiche (la dernière valeur l’emporte)";
                } else {
                    $origine = $matricule;
                    $matricule = $this->matriculeLibre($origine, $vus + $duFichier);
                    $d['matricule'] = $matricule;
                    $duFichier[$matricule] = true;
                    $note = "Matricule « {$origine} » déjà porté ligne {$vus[$origine]} par un autre élève : créé sous « {$matricule} », à corriger.";
                    $apercu = trim($matricule.' — '.trim(($nom ?? '').' '.($prenom ?? '')));
                }
            }
            if ($repete === null) {
                $vus[$matricule] = $n;
                $noms[$matricule] = $identite;
                $bruts[$matricule] = $d;
            }

            $existe = isset($existants[$matricule]);

            // ECONOMAT ne tient qu'UNE fiche par élève, rattachée à une seule année. Celle
            // d'une autre année ne se « met pas à jour » depuis l'année visée : on modifierait
            // en silence l'autre année, tout en annonçant celle-ci.
            if ($existe && ! $this->dansLAnnee($existants[$matricule]['annee']) && $this->toutImporter) {
                // « Tout importer » : une fiche est ajoutée pour l'année visée (T_ETUDIANT
                // porte une année par ligne) ; celle de l'autre année reste intacte.
                $notes[] = "déjà inscrit en {$existants[$matricule]['annee']} : fiche ajoutée pour {$annee}, l’autre année reste intacte";
                unset($existants[$matricule]);
                $existe = false;
            }
            if ($existe && ! $this->dansLAnnee($existants[$matricule]['annee'])) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Cet élève existe déjà, inscrit en {$existants[$matricule]['annee']} : ECONOMAT ne garde qu'une fiche par élève, l'importer en {$annee} la retirerait de son année.",
                    $apercu,
                );

                continue;
            }
            $existe = $existe || $repete !== null;

            if (! $existe && ($nom === null || $prenom === null)) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, 'Nom et prénom sont obligatoires pour créer un élève.', $apercu);

                    continue;
                }
                if ($nom === null) {
                    $d['nom'] = 'A COMPLETER';
                    $notes[] = 'nom absent : « A COMPLETER » provisoire';
                }
                if ($prenom === null) {
                    $d['prenom'] = 'A compléter';
                    $notes[] = 'prénom absent : « A compléter » provisoire';
                }
            }

            $classe = $d['classe_code'] ?? null;

            if (! $existe && $classe === null) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, 'Classe absente : un élève sans classe n’apparaît sur aucun écran.', $apercu);

                    continue;
                }
                // Rangé dans une classe d'attente, à reclasser depuis l'écran Élèves.
                $classe = $d['classe_code'] = 'A-CLASSER';
                $notes[] = 'classe absente : rangé dans « A-CLASSER », à reclasser';
            }

            if ($classe !== null && ! isset($classes[$classe])) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet(
                        $n,
                        "Classe « {$classe} » inconnue pour l’année {$annee} dans cet établissement."
                        .$this->suggestionClasse($classe, $classes),
                        $apercu,
                    );

                    continue;
                }

                $resolue = $this->resoudreClasse($classe, $d['niveau_code'] ?? null);
                if ($resolue['motif'] !== null) {
                    $verdicts[] = $this->rejet($n, $resolue['motif'].$this->suggestionClasse($classe, $classes), $apercu);

                    continue;
                }
                if ($resolue['plan'] !== null) {
                    $d['_classe_a_creer'] = $resolue['plan'];
                }
                if (! empty($resolue['code'])) {
                    $d['classe_code'] = $resolue['code'];
                }
                $notes[] = $resolue['note'];
            }

            if ($this->toutImporter) {
                $d = $this->tronquer($d, EtudiantEcrivain::LARGEURS, $notes, array_flip(self::MAP));
            } elseif (($trop = $this->tropLong($d)) !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            if (array_key_exists('date_naissance', $d) && $d['date_naissance'] === null
                && ($brute = $this->texte($ligne['Date de naissance'] ?? null)) !== null) {
                if (! $this->toutImporter) {
                    $verdicts[] = $this->rejet($n, 'Date de naissance illisible. Formats admis : 25/09/2018 ou 2018-09-25.', $apercu);

                    continue;
                }
                // Laissée de côté plutôt qu'effacée : la date déjà connue reste en place.
                unset($d['date_naissance']);
                $notes[] = "date de naissance « {$brute} » illisible : laissée vide";
            }

            // L'année et la société ne se prennent jamais dans le fichier : elles viennent
            // du contexte de travail, comme pour une inscription à l'écran.
            $contexte = array_filter([
                'annee' => $annee,
                'societe_code' => $societe,
            ], fn ($v) => $v !== null);

            if ($repete !== null) {
                // L'élève est créé (ou mis à jour) par la ligne précédente : celle-ci le
                // retrouve par son matricule au moment d'écrire.
                $verdict = $this->modification($n, $d + array_filter([
                    '_code' => $existants[$matricule]['code'] ?? null,
                ]) + ['_si_absent' => $contexte], $apercu);
            } elseif (isset($existants[$matricule])) {
                $verdict = $this->modification($n, $d + ['_code' => $existants[$matricule]['code']], $apercu);
            } else {
                $verdict = ['motif' => $note] + $this->creation($n, $d + $contexte, $apercu);
            }

            $verdicts[] = $this->ajuste($verdict, $notes);
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
                $this->assurerClasse($donnees['_classe_a_creer'] ?? null);
                $code = $donnees['_code'] ?? null;
                $siAbsent = $donnees['_si_absent'] ?? null;
                unset($donnees['_classe_a_creer'], $donnees['_code'], $donnees['_si_absent']);

                if ($v['action'] === self::CREATION) {
                    $this->ecrivain->creer($donnees);
                    $bilan['creees']++;

                    continue;
                }

                if ($code === null && ! empty($donnees['matricule'])) {
                    $code = $this->ecrivain->parMatricule($donnees['matricule'])?->Code;
                }
                if ($code === null && is_array($siAbsent)) {
                    // La ligne précédente n'a pas pu créer l'élève : celle-ci le crée.
                    $this->ecrivain->creer($donnees + $siAbsent);
                    $bilan['creees']++;

                    continue;
                }

                $this->ecrivain->modifier((int) $code, $donnees);
                $bilan['modifiees']++;
            } catch (Throwable $e) {
                // Une ligne qui casse n'arrête pas les autres : sur 300 fiches, tout
                // annuler pour une seule ferait recommencer un travail déjà bon.
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $this->motifEchec($e, $v['ligne'])];
            }
        }

        return $bilan;
    }

    /** L'élève déjà inscrit sous la même identité (nom, prénom, date de naissance), ou null. */
    private function memeEleve(array $d)
    {
        if (empty($d['date_naissance'])) {
            return null;
        }

        try {
            return $this->ecrivain->memeIdentite($d);
        } catch (Throwable $e) {
            $this->referentielInjoignable('élèves existants', $e);
        }
    }

    /** Ligne du fichier -> champs de l'écrivain, dates converties. */
    private function champs(array $ligne): array
    {
        $d = [];
        foreach (self::MAP as $entete => $champ) {
            if (! array_key_exists($entete, $ligne)) {
                continue;
            }

            if (in_array($champ, self::DATES, true)) {
                $d[$champ] = $this->date($ligne[$entete]);
            } elseif ($champ === 'redoublant') {
                $d[$champ] = $this->booleen($ligne[$entete]) ? 1 : 0;
            } else {
                $d[$champ] = $this->texte($ligne[$entete]);
            }
        }

        return $d;
    }

    /**
     * Une valeur plus longue que sa colonne est REFUSÉE, pas tronquée : SQL Server
     * rejetterait la ligne, et tronquer en silence produirait un numéro de téléphone
     * amputé que personne ne remarquerait avant d'avoir à s'en servir.
     */
    private function tropLong(array $d): ?string
    {
        foreach (EtudiantEcrivain::LARGEURS as $champ => $largeur) {
            $valeur = $d[$champ] ?? null;
            if (is_string($valeur) && mb_strlen($valeur) > $largeur) {
                return "Le champ « {$champ} » dépasse {$largeur} caractères (".mb_strlen($valeur).').';
            }
        }

        return null;
    }

    /**
     * Classes valides pour l'année et l'établissement de travail, code -> niveau (le niveau
     * sert à suggérer la bonne classe). Référentiel injoignable : l'analyse est refusée —
     * sans lui, aucune classe ne serait contrôlée.
     *
     * @return array<string, string>
     */
    private function classesConnues(): array
    {
        try {
            return (new CatalogueDonnees)->requete('classes')
                ->select('CodeClasse', 'CodN')
                ->get()
                ->mapWithKeys(fn ($c) => [trim((string) $c->CodeClasse) => trim((string) $c->CodN)])
                ->all();
        } catch (Throwable $e) {
            $this->referentielInjoignable('classes', $e);
        }
    }

    /**
     * Les matricules du fichier qui existent déjà, en une requête plutôt qu'une par ligne :
     * sur 800 élèves, la version naïve fait 800 allers-retours vers SQL Server.
     *
     * @return array<string, array{code: int, annee: string}> matricule -> fiche
     */
    private function matriculesExistants(array $lignes): array
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

        $trouves = [];
        try {
            foreach (array_chunk(array_keys($matricules), 500) as $lot) {
                DB::connection('economat')->table('T_ETUDIANT')
                    ->select('Code', 'Matricule', 'AnneeAcad')
                    ->whereIn('Matricule', $lot)
                    ->get()
                    ->each(function ($l) use (&$trouves) {
                        $m = trim((string) $l->Matricule);
                        // Plusieurs fiches (une par année) : celle de l'année visée l'emporte.
                        if (isset($trouves[$m]) && $this->dansLAnnee($trouves[$m]['annee'])) {
                            return;
                        }
                        $trouves[$m] = [
                            'code' => (int) $l->Code,
                            'annee' => trim((string) $l->AnneeAcad),
                        ];
                    });
            }
        } catch (Throwable $e) {
            // Vide, tout passerait en création : autant de doublons que d'élèves connus.
            $this->referentielInjoignable('élèves existants', $e);
        }

        return $trouves;
    }

    /**
     * « P24-0046 » pris -> « P24-0046-2 », puis « -3 »… : libre dans le fichier ET en base.
     * `$baseIncluse` : le candidat de départ lui-même est essayé d'abord (matricule attribué).
     */
    private function matriculeLibre(string $origine, array $vus, bool $baseIncluse = false): string
    {
        for ($i = $baseIncluse ? 1 : 2; ; $i++) {
            $candidat = $i === 1 ? $origine : "{$origine}-{$i}";
            if (isset($vus[$candidat])) {
                continue;
            }
            try {
                if (! DB::connection('economat')->table('T_ETUDIANT')->where('Matricule', $candidat)->exists()) {
                    return $candidat;
                }
            } catch (Throwable $e) {
                $this->referentielInjoignable('élèves existants', $e);
            }
        }
    }

    /** La fiche est-elle dans l'année visée ? Sans année sur la fiche, on ne la refuse pas. */
    private function dansLAnnee(string $anneeFiche): bool
    {
        $variantes = array_map(fn ($v) => trim((string) $v), ContexteScolaire::variantes());

        return $anneeFiche === '' || $variantes === [] || in_array($anneeFiche, $variantes, true);
    }

    /** La société de l'établissement de travail, comme AccesAutomatique la résout. */
    private function societe(): ?string
    {
        $etablissement = PerimetreEtablissement::code();
        if ($etablissement === null) {
            return null;
        }

        try {
            return Etablissement::where('code', $etablissement)->value('societe_code');
        } catch (Throwable $e) {
            return null;
        }
    }
}
