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
        $classes = $this->classesConnues();
        $existants = $this->matriculesExistants($lignes);
        $annee = ContexteScolaire::annee();
        $societe = $this->societe();

        $vus = [];
        $verdicts = [];

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $d = $this->champs($ligne);

            $matricule = $d['matricule'] ?? null;
            $nom = $d['nom'] ?? null;
            $prenom = $d['prenom'] ?? null;
            $apercu = trim(($matricule ?? '?').' — '.trim(($nom ?? '').' '.($prenom ?? '')));

            if ($matricule === null) {
                $verdicts[] = $this->rejet($n, 'Matricule absent : c’est la clé sur laquelle la fiche est reconnue.', $apercu);

                continue;
            }

            if (isset($vus[$matricule])) {
                $verdicts[] = $this->rejet($n, "Matricule « {$matricule} » déjà présent ligne {$vus[$matricule]} du fichier.", $apercu);

                continue;
            }
            $vus[$matricule] = $n;

            $existe = isset($existants[$matricule]);

            if (! $existe && ($nom === null || $prenom === null)) {
                $verdicts[] = $this->rejet($n, 'Nom et prénom sont obligatoires pour créer un élève.', $apercu);

                continue;
            }

            $classe = $d['classe_code'] ?? null;

            if (! $existe && $classe === null) {
                $verdicts[] = $this->rejet($n, 'Classe absente : un élève sans classe n’apparaît sur aucun écran.', $apercu);

                continue;
            }

            if ($classe !== null && $classes !== null && ! isset($classes[$classe])) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Classe « {$classe} » inconnue pour l’année {$annee} dans cet établissement.",
                    $apercu,
                );

                continue;
            }

            if (($trop = $this->tropLong($d)) !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            if (array_key_exists('date_naissance', $d) && $d['date_naissance'] === null
                && $this->texte($ligne['Date de naissance'] ?? null) !== null) {
                $verdicts[] = $this->rejet($n, 'Date de naissance illisible. Formats admis : 25/09/2018 ou 2018-09-25.', $apercu);

                continue;
            }

            if ($existe) {
                $verdicts[] = $this->modification($n, $d + ['_code' => $existants[$matricule]], $apercu);
            } else {
                // L'année et la société ne se prennent jamais dans le fichier : elles
                // viennent du contexte de travail, comme pour une inscription à l'écran.
                $creation = $d + array_filter([
                    'annee' => $annee,
                    'societe_code' => $societe,
                ], fn ($v) => $v !== null);

                $verdicts[] = $this->creation($n, $creation, $apercu);
            }
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
                if ($v['action'] === self::CREATION) {
                    $this->ecrivain->creer($v['donnees']);
                    $bilan['creees']++;
                } else {
                    $donnees = $v['donnees'];
                    $code = (int) $donnees['_code'];
                    unset($donnees['_code']);
                    $this->ecrivain->modifier($code, $donnees);
                    $bilan['modifiees']++;
                }
            } catch (Throwable $e) {
                // Une ligne qui casse n'arrête pas les autres : sur 300 fiches, tout
                // annuler pour une seule ferait recommencer un travail déjà bon.
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $e->getMessage()];
            }
        }

        return $bilan;
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
     * Codes de classe valides pour l'année et l'établissement de travail.
     * `null` = le référentiel est injoignable ; on n'invente alors aucun rejet.
     */
    private function classesConnues(): ?array
    {
        try {
            return (new CatalogueDonnees)->requete('classes')
                ->pluck('CodeClasse')
                ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
                ->all();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Les matricules du fichier qui existent déjà, en une requête plutôt qu'une par ligne :
     * sur 800 élèves, la version naïve fait 800 allers-retours vers SQL Server.
     *
     * @return array<string, int> matricule -> Code
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
                    ->select('Code', 'Matricule')
                    ->whereIn('Matricule', $lot)
                    ->get()
                    ->each(function ($l) use (&$trouves) {
                        $trouves[trim((string) $l->Matricule)] = (int) $l->Code;
                    });
            }
        } catch (Throwable $e) {
            return [];
        }

        return $trouves;
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
