<?php

namespace App\Services\Echange;

use App\Services\ProfesseurEcrivain;
use App\Support\ClassesEnseignant;
use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import des enseignants — ECONOMAT.dbo.T_PROFESSEUR, par ProfesseurEcrivain, le même que
 * l'écran Enseignants : état civil, coordonnées, carrière. Le salaire et le mot de passe
 * ne sont jamais écrits, pas plus ici qu'à l'écran.
 *
 * LA FICHE, PAS LE COMPTE. Créer un enseignant à l'écran lui ouvre aussi un compte, dont
 * le mot de passe s'affiche une fois et une seule. Un import de cinquante fiches ne peut
 * pas montrer cinquante mots de passe : il crée les fiches, rattachées à l'établissement
 * de travail, et le compte s'ouvre ensuite depuis la fiche de chacun, sur l'écran. Faute
 * d'établissement de travail unique, la création est refusée, comme pour les classes.
 *
 * LA CLÉ EST LE MATRICULE. Un matricule déjà porté par un enseignant d'un autre
 * établissement est écarté : un fichier de l'école A ne réécrit pas une fiche de l'école B.
 */
class ImportEnseignants extends Importateur
{
    /** En-tête du catalogue -> champ de ProfesseurEcrivain. */
    private const MAP = [
        'Matricule' => 'matricule',
        'Nom' => 'nom',
        'Prénom' => 'prenom',
        'Sexe' => 'sexe',
        'Email' => 'email',
        'Téléphone' => 'telephone',
        'Statut' => 'statut',
        'Grade' => 'grade',
        'Diplôme' => 'diplome',
        'Date embauche' => 'date_embauche',
    ];

    public function __construct(private ProfesseurEcrivain $ecrivain) {}

    public function verdicts(array $lignes): array
    {
        $annee = ContexteScolaire::annee();
        $existants = $this->existants();
        $etablissement = $this->etablissementDeCreation();
        $libelles = array_flip(self::MAP);
        $vus = [];
        $verdicts = [];

        if ($this->toutImporter) {
            return $this->verdictsToutImporter($lignes, $annee, $existants, $etablissement, $libelles);
        }

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $d = $this->champs($ligne);
            $matricule = $d['matricule'] ?? null;
            $apercu = trim(($matricule ?? '?').' — '.trim(($d['nom'] ?? '').' '.($d['prenom'] ?? '')));

            if ($matricule === null) {
                $verdicts[] = $this->rejet($n, 'Matricule absent : c’est la clé sur laquelle la fiche est reconnue.', $apercu);

                continue;
            }

            if (isset($vus[$matricule])) {
                $verdicts[] = $this->rejet($n, "Matricule « {$matricule} » déjà présent ligne {$vus[$matricule]} du fichier.", $apercu);

                continue;
            }
            $vus[$matricule] = $n;

            // Comme à l'écran : nom et prénom sont exigés, à la création comme en modification.
            if (($d['nom'] ?? null) === null || ($d['prenom'] ?? null) === null) {
                $verdicts[] = $this->rejet($n, 'Nom et prénom sont obligatoires.', $apercu);

                continue;
            }

            if (array_key_exists('sexe', $d) && $d['sexe'] !== null) {
                $sexe = $this->sexe($d['sexe']);
                if ($sexe === null) {
                    $verdicts[] = $this->rejet($n, "Sexe « {$d['sexe']} » illisible : M ou F.", $apercu);

                    continue;
                }
                $d['sexe'] = $sexe;
            }

            if (($d['email'] ?? null) !== null && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
                $verdicts[] = $this->rejet($n, "Email « {$d['email']} » invalide.", $apercu);

                continue;
            }

            if (($trop = $this->depassement($d, ProfesseurEcrivain::LARGEURS, $libelles)) !== null) {
                $verdicts[] = $this->rejet($n, $trop, $apercu);

                continue;
            }

            $code = $existants[$matricule] ?? null;

            if ($code !== null) {
                if (! PerimetreEtablissement::enseignantVisible($code)) {
                    $verdicts[] = $this->rejet($n, "Le matricule « {$matricule} » appartient à un enseignant d’un autre établissement.", $apercu);

                    continue;
                }
                $verdicts[] = $this->modification($n, $d + ['_code' => $code], $apercu);

                continue;
            }

            if ($etablissement === null) {
                $verdicts[] = $this->rejet(
                    $n,
                    "Choisissez d'abord, dans l'en-tête, l'établissement où créer les enseignants.",
                    $apercu,
                );

                continue;
            }

            $verdicts[] = $this->creation($n, $d + array_filter(['annee_code' => $annee]) + ['_site' => $etablissement], $apercu);
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
                $d = $v['donnees'];
                $site = $d['_site'] ?? null;
                $repete = ! empty($d['_repete']);
                unset($d['_site'], $d['_repete']);
                // Ligne répétée (« tout importer ») : la fiche que la précédente a créée se
                // retrouve maintenant par son matricule.
                if ($repete && ! isset($d['_code']) && ($connu = $this->codeDe((string) $d['matricule'])) !== null) {
                    $d['_code'] = $connu;
                }
                if (isset($d['_code'])) {
                    $code = (int) $d['_code'];
                    unset($d['_code']);
                    $this->ecrivain->modifier($code, $d);
                    $bilan['modifiees']++;
                } else {
                    $code = $this->ecrivain->creer($d);
                    $bilan['creees']++;
                }
                // Sans établissement de rattachement, une fiche sans classe n'apparaîtrait
                // sur aucun écran (voir EnseignantController::index).
                ClassesEnseignant::rattacher($code, $site);
            } catch (Throwable $e) {
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $this->motifEchec($e, $v['ligne'])];
            }
        }

        return $bilan;
    }

    /**
     * Mode « tout importer ».
     *
     * Rattrapés : matricule absent (attribué), matricule répété dans le fichier (la ligne
     * met à jour la fiche de la précédente), matricule porté par un enseignant d'un autre
     * établissement (fiche créée sous un matricule libre, « M-2 »), nom ou prénom absent
     * (celui de la fiche gardé, sinon provisoire), sexe illisible et email invalide (laissés
     * vides), valeur trop longue (coupée).
     *
     * Reste écarté : sans établissement de travail déterminé pour créer la fiche.
     */
    private function verdictsToutImporter(array $lignes, ?string $annee, array $existants, ?string $etablissement, array $libelles): array
    {
        $verdicts = [];
        $vus = [];
        $pris = $existants;
        foreach ($lignes as $ligne) {
            if (($m = $this->texte($ligne['Matricule'] ?? null)) !== null) {
                $pris[$m] = true;
            }
        }
        $enBase = function (string $c) {
            try {
                return $this->ecrivain->matriculeExiste($c);
            } catch (Throwable $e) {
                $this->referentielInjoignable('enseignants existants', $e);
            }
        };

        foreach ($lignes as $ligne) {
            $n = (int) $ligne['_ligne'];
            $d = $this->champs($ligne);
            $notes = [];
            $matricule = $d['matricule'] ?? null;
            $apercu = fn () => trim(($d['matricule'] ?? '?').' — '.trim(($d['nom'] ?? '').' '.($d['prenom'] ?? '')));

            if ($matricule === null) {
                if (($d['nom'] ?? null) === null && ($d['prenom'] ?? null) === null) {
                    $verdicts[] = $this->rejet($n, 'Ni matricule, ni nom, ni prénom : rien n’identifie l’enseignant.', $apercu());

                    continue;
                }
                $millesime = AnneeImport::paire((string) $annee)[0] ?? (int) date('Y');
                $matricule = $this->codeLibre(sprintf('ENS%s-%04d', $millesime, $n), $pris, $enBase, 50, true);
                $d['matricule'] = $matricule;
                $pris[$matricule] = true;
                $notes[] = "matricule absent : « {$matricule} » attribué";
            }

            $code = $existants[$matricule] ?? null;
            $repete = isset($vus[$matricule]);

            if ($code !== null && ! PerimetreEtablissement::enseignantVisible($code)) {
                $ancien = $matricule;
                $matricule = $this->codeLibre($ancien, $pris, $enBase);
                $d['matricule'] = $matricule;
                $pris[$matricule] = true;
                $code = null;
                $repete = isset($vus[$matricule]);
                $notes[] = "matricule « {$ancien} » porté par un enseignant d’un autre établissement : fiche créée sous « {$matricule} », à corriger";
            }

            if ($repete) {
                $notes[] = $vus[$matricule]['brut'] == $d
                    ? "ligne identique à la ligne {$vus[$matricule]['ligne']} : déjà prise en compte"
                    : "même matricule que la ligne {$vus[$matricule]['ligne']} : cette ligne complète la fiche (la dernière valeur l’emporte)";
            }
            $brut = $d;

            $connue = $code !== null || $repete;
            foreach (['nom' => 'Nom', 'prenom' => 'Prénom'] as $champ => $libelle) {
                if (($d[$champ] ?? null) !== null) {
                    continue;
                }
                if ($connue) {
                    unset($d[$champ]);
                    $notes[] = "{$libelle} absent : celui de la fiche est gardé";
                } else {
                    $d[$champ] = $champ === 'nom' ? 'A COMPLETER' : 'A compléter';
                    $notes[] = "{$libelle} absent : « {$d[$champ]} » provisoire";
                }
            }

            if (($d['sexe'] ?? null) !== null) {
                $sexe = $this->sexe($d['sexe']);
                if ($sexe === null) {
                    $notes[] = "sexe « {$d['sexe']} » illisible (M ou F) : laissé vide";
                    unset($d['sexe']);
                } else {
                    $d['sexe'] = $sexe;
                }
            }

            if (($d['email'] ?? null) !== null && filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false) {
                $notes[] = "email « {$d['email']} » invalide : laissé vide";
                unset($d['email']);
            }

            $d = $this->tronquer($d, ProfesseurEcrivain::LARGEURS, $notes, $libelles);

            if ($code !== null) {
                $vus[$matricule] ??= ['ligne' => $n, 'brut' => $brut];
                $verdicts[] = $this->ajuste($this->modification($n, $d + ['_code' => $code], $apercu()), $notes);

                continue;
            }

            if ($etablissement === null) {
                $verdicts[] = $this->rejet($n, "Choisissez d'abord, dans l'en-tête, l'établissement où créer les enseignants.", $apercu());

                continue;
            }

            if ($repete) {
                $verdicts[] = $this->ajuste($this->modification($n, $d + ['_repete' => true, '_site' => $etablissement], $apercu()), $notes);

                continue;
            }

            $vus[$matricule] = ['ligne' => $n, 'brut' => $brut];
            $verdicts[] = $this->ajuste($this->creation($n, $d + array_filter(['annee_code' => $annee]) + ['_site' => $etablissement], $apercu()), $notes);
        }

        return $verdicts;
    }

    /** Le Code de la fiche qui porte ce matricule, lu au moment d'écrire. */
    private function codeDe(string $matricule): ?int
    {
        $code = DB::connection('economat')->table('T_PROFESSEUR')
            ->where('MatriculeProfesseur', $matricule)->value('Code');

        return $code === null ? null : (int) $code;
    }

    private function champs(array $ligne): array
    {
        $d = [];
        foreach (self::MAP as $entete => $champ) {
            if (! array_key_exists($entete, $ligne)) {
                continue;
            }
            // La date d'embauche est une colonne texte dans T_PROFESSEUR : une date lisible
            // est remise au format de la base, une autre est gardée telle qu'écrite.
            $d[$champ] = $champ === 'date_embauche'
                ? ($this->date($ligne[$entete]) ?? $this->texte($ligne[$entete]))
                : $this->texte($ligne[$entete]);
        }

        return $d;
    }

    /** « M », « Masculin », « H », « Homme » -> M ; « F », « Féminin », « Femme » -> F. */
    private function sexe(string $valeur): ?string
    {
        $initiale = mb_strtoupper(mb_substr(trim($valeur), 0, 1));

        return match ($initiale) {
            'M', 'H' => 'M',
            'F' => 'F',
            default => null,
        };
    }

    /** @return array<string, int> matricule -> Code */
    private function existants(): array
    {
        try {
            return DB::connection('economat')->table('T_PROFESSEUR')
                ->select('Code', 'MatriculeProfesseur')
                ->whereNotNull('MatriculeProfesseur')
                ->get()
                ->filter(fn ($l) => trim((string) $l->MatriculeProfesseur) !== '')
                ->mapWithKeys(fn ($l) => [trim((string) $l->MatriculeProfesseur) => (int) $l->Code])
                ->all();
        } catch (Throwable $e) {
            // Vide, tout passerait en création : chaque enseignant connu serait dupliqué.
            $this->referentielInjoignable('enseignants existants', $e);
        }
    }
}
