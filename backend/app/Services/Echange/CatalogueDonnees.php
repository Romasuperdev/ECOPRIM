<?php

namespace App\Services\Echange;

use App\Support\ContexteScolaire;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ce que NEXORA sait échanger avec un tableur, déclaré UNE fois.
 *
 * L'export, le modèle vierge et l'import lisent tous les trois cette même déclaration.
 * Séparés, ils auraient divergé dès la première colonne ajoutée : on aurait exporté une
 * colonne que le modèle n'annonce pas, ou attendu à l'import un en-tête que l'export
 * n'écrit jamais. Ici, ajouter une colonne à un jeu la fait apparaître aux trois endroits.
 *
 * PÉRIMÈTRE. Un export n'est pas une porte dérobée : il rend exactement ce que l'écran
 * rendrait. Même année de travail, même établissement. D'où `annee` et `classe` ci-dessous,
 * qui nomment la colonne sur laquelle chaque filtre s'applique — null quand le jeu n'est
 * pas concerné (une matière traverse les années, comme dans MatiereController).
 *
 * IMPORTABLE. Seuls les jeux pour lesquels NEXORA possède DÉJÀ un chemin d'écriture
 * contrôlé le sont — l'écrivain de leur écran, jamais un INSERT inventé pour l'import dans
 * une base de production partagée. C'est aujourd'hui le cas des huit. Un jeu qui ne le
 * serait pas porte `importable => false` et un `pourquoi_pas`, que l'écran affiche plutôt
 * que de laisser l'utilisateur chercher un bouton absent.
 */
class CatalogueDonnees
{
    /**
     * Les en-têtes sont en clair et en français : c'est un fichier que quelqu'un ouvre,
     * lit et corrige à la main. Le nom de la colonne SQL, lui, ne quitte pas ce fichier.
     */
    public const JEUX = [
        'eleves' => [
            'libelle' => 'Élèves',
            'feuille' => 'Eleves',
            'connexion' => 'economat',
            'table' => 'T_ETUDIANT',
            'annee' => 'AnneeAcad',
            'classe' => 'CodeClasse',
            'tri' => ['Nom', 'Prenom'],
            'importable' => true,
            'colonnes' => [
                'Matricule' => 'Matricule',
                'Nom' => 'Nom',
                'Prénom' => 'Prenom',
                'Sexe' => 'Sexe',
                'Date de naissance' => 'DateNaiss',
                'Lieu de naissance' => 'LieuNaiss',
                'Nationalité' => 'Nationalite',
                'Classe' => 'CodeClasse',
                'Niveau' => 'CodeNiveau',
                'Redoublant' => 'Redoublant',
                'Nom du pere ou tuteur' => 'NomPereTuteur',
                'Prénom du pere ou tuteur' => 'PrenomPereTuteur',
                'Profession du pere ou tuteur' => 'ProfessionPereTuteur',
                'Téléphone du pere ou tuteur' => 'TelephonePereTuteur',
                'Email du pere ou tuteur' => 'EmailPereTuteur',
                'Nom de la mere' => 'NomMere',
                'Prénom de la mere' => 'PrenomMere',
                'Profession de la mere' => 'ProfessionMere',
                'Téléphone de la mere' => 'TelephoneMere',
                'Email de la mere' => 'EmailMere',
            ],
        ],

        'notes' => [
            'libelle' => 'Notes',
            'feuille' => 'Notes',
            'connexion' => 'economat',
            'table' => 'V_NOTECLASSE',
            'annee' => 'CodeAnnee',
            'classe' => 'CodeClasse',
            'tri' => ['CodeClasse', 'CodeMatiere', 'Nom'],
            'importable' => true,
            'colonnes' => [
                'Matricule' => 'Matricule',
                'Nom' => 'Nom',
                'Prénom' => 'Prenom',
                'Classe' => 'CodeClasse',
                'Matière' => 'CodeMatiere',
                'Libellé matière' => 'LibelleMatiere',
                'Session' => 'CodeSession',
                'Type' => 'TypeNote',
                'Note' => 'Note',
                'Coefficient' => 'Coefficient',
            ],
        ],

        'absences' => [
            'libelle' => 'Absences',
            'feuille' => 'Absences',
            'connexion' => 'economat',
            'table' => 'T_ABSENCEELEVE',
            'annee' => 'AnneeCour',
            'classe' => 'CodeClasse',
            'tri' => ['Date'],
            'importable' => true,
            // SQL Server rend un `bit` en 1 / 0, pas en booléen PHP : la colonne sortirait
            // donc en chiffres sur une feuille faite pour être lue. Déclaré plutôt que
            // deviné au type — une colonne qui contient 0 et 1 n'est pas forcément un
            // oui/non, et « Ordre » ou « Coefficient » y passeraient aussi.
            'oui_non' => ['Justifiée'],
            'colonnes' => [
                'Matricule' => 'Matricule',
                'Classe' => 'CodeClasse',
                'Date' => 'Date',
                'Heure' => 'Heure',
                'Motif' => 'Cause',
                'Justifiée' => 'Justifier',
            ],
        ],

        'evaluations' => [
            'libelle' => 'Évaluations',
            'feuille' => 'Evaluations',
            'connexion' => 'ecoprim',
            'table' => 'evaluations',
            'annee' => 'annee',
            'classe' => 'classe_code',
            'tri' => ['date'],
            'importable' => true,
            'colonnes' => [
                'Titre' => 'titre',
                'Classe' => 'classe_code',
                'Matière' => 'matiere_code',
                'Enseignant' => 'enseignant_code',
                'Type' => 'type',
                'Date' => 'date',
                'Heure de début' => 'heure_debut',
                'Heure de fin' => 'heure_fin',
                'Coefficient' => 'coefficient',
                'Note maximale' => 'note_maximale',
            ],
        ],

        'enseignants' => [
            'libelle' => 'Enseignants',
            'feuille' => 'Enseignants',
            'connexion' => 'economat',
            'table' => 'T_PROFESSEUR',
            // T_PROFESSEUR ne porte ni classe ni établissement : le périmètre est celui de
            // l'écran Enseignants (PerimetreEtablissement::appliquerAuxEnseignants), année
            // comprise. Sans lui, l'export rendait les enseignants de toute la société.
            'annee' => null,
            'classe' => null,
            'perimetre' => 'enseignants',
            'tri' => ['NomProfesseur'],
            'importable' => true,
            'colonnes' => [
                'Matricule' => 'MatriculeProfesseur',
                'Nom' => 'NomProfesseur',
                'Prénom' => 'PrenomProfesseur',
                'Sexe' => 'Sexe',
                'Email' => 'EmailProfesseur',
                'Téléphone' => 'ContactProfesseur',
                'Statut' => 'TypeProfesseur',
                'Grade' => 'GradeProfesseur',
                'Diplôme' => 'DiplTitrUniv',
                'Date embauche' => 'DateEmbauche',
            ],
        ],

        'classes' => [
            'libelle' => 'Classes',
            'feuille' => 'Classes',
            'connexion' => 'economat',
            'table' => 'T_CLASSE',
            'annee' => 'ANNEE',
            // T_CLASSE porte l'établissement en propre : inutile de passer par elle-même.
            'etablissement' => 'CODEETABLISSEMENT',
            'classe' => null,
            'tri' => ['LibelleClasse'],
            'importable' => true,
            'colonnes' => [
                'Code' => 'CodeClasse',
                'Libellé' => 'LibelleClasse',
                'Niveau' => 'CodN',
                'Année' => 'ANNEE',
            ],
        ],

        'matieres' => [
            'libelle' => 'Matières',
            'feuille' => 'Matieres',
            'connexion' => 'economat',
            'table' => 'T_MATIERE',
            // Une matière traverse les années scolaires (cf. MatiereController).
            'annee' => null,
            'classe' => null,
            'tri' => ['LibelleMatiere'],
            'importable' => true,
            'colonnes' => [
                'Code' => 'CodeMatiere',
                'Libellé' => 'LibelleMatiere',
                'Cycle' => 'CodeCycle',
            ],
        ],

        'niveaux' => [
            'libelle' => 'Niveaux',
            'feuille' => 'Niveaux',
            'connexion' => 'economat',
            'table' => 'T_NIVEAU',
            'annee' => 'ANNEE',
            // Pas de filtre d'établissement : les niveaux sont un référentiel de l'année,
            // partagé par les établissements, et l'écran Niveaux les montre tous (voir
            // NiveauController::index). Filtrés, ils sortaient presque tous de l'export.
            'classe' => null,
            'tri' => ['Ordre'],
            'importable' => true,
            'colonnes' => [
                'Code' => 'CodeNiveau',
                'Libellé' => 'LibelleNiveau',
                'Ordre' => 'Ordre',
                'Année' => 'ANNEE',
            ],
        ],
    ];

    public static function existe(string $code): bool
    {
        return array_key_exists($code, self::JEUX);
    }

    public static function codes(): array
    {
        return array_keys(self::JEUX);
    }

    /**
     * L'ordre dans lequel un classeur d'année entière s'importe — distinct de l'ordre des
     * onglets à l'export, qui met les élèves en tête parce que c'est ce qu'on y cherche.
     * Ici chaque feuille passe après celles dont elle dépend : on ouvre les niveaux, puis
     * les classes qui s'y rangent, puis on y inscrit les élèves, et seulement ensuite leurs
     * notes et leurs absences.
     */
    public const ORDRE_IMPORT = [
        'niveaux', 'classes', 'matieres', 'enseignants', 'eleves', 'notes', 'absences', 'evaluations',
    ];

    /** Les jeux importables, dans l'ordre d'import. */
    public static function importables(): array
    {
        $importables = array_keys(array_filter(self::JEUX, fn ($j) => $j['importable']));

        return array_values(array_merge(
            array_intersect(self::ORDRE_IMPORT, $importables),
            array_diff($importables, self::ORDRE_IMPORT),
        ));
    }

    public static function jeu(string $code): array
    {
        return self::JEUX[$code];
    }

    /** Les en-têtes d'un jeu, dans l'ordre où ils sont écrits. */
    public static function entetes(string $code): array
    {
        return array_keys(self::JEUX[$code]['colonnes']);
    }

    /** @var array<string, array<int, string>> */
    private static array $colonnesReelles = [];

    /**
     * Les colonnes que la table porte VRAIMENT, relevées à l'exécution — même principe que
     * SchemaNotes. ECONOMAT est une base de production qu'on ne contrôle pas : une colonne
     * renommée entre deux versions ferait autrement échouer la requête entière, et la
     * feuille arriverait VIDE sans que rien ne le dise. Une feuille muette est le pire des
     * résultats : on croit que la donnée n'existe pas.
     */
    private function colonnesReelles(string $code): array
    {
        $jeu = self::jeu($code);
        $cle = $jeu['connexion'].'.'.$jeu['table'];

        if (! array_key_exists($cle, self::$colonnesReelles)) {
            try {
                self::$colonnesReelles[$cle] = Schema::connection($jeu['connexion'])
                    ->getColumnListing($jeu['table']);
            } catch (Throwable $e) {
                self::$colonnesReelles[$cle] = [];
            }
        }

        return self::$colonnesReelles[$cle];
    }

    /**
     * En-tête -> colonne, réduit à ce qui existe. Une table introuvable rend tout le jeu
     * (on n'a rien pu relever) plutôt que rien, pour que la requête échoue franchement.
     *
     * @return array<string, string>
     */
    public function colonnesLisibles(string $code): array
    {
        $declarees = self::jeu($code)['colonnes'];
        $reelles = $this->colonnesReelles($code);

        if ($reelles === []) {
            return $declarees;
        }

        $index = array_change_key_case(array_flip($reelles), CASE_LOWER);

        return array_filter($declarees, fn ($colonne) => isset($index[strtolower($colonne)]));
    }

    /**
     * Les en-têtes déclarés que la table ne porte pas. Affiché à l'écran : mieux vaut une
     * colonne annoncée vide et dite, qu'une colonne absente sans explication.
     */
    public function colonnesIntrouvables(string $code): array
    {
        return array_values(array_diff(
            self::entetes($code),
            array_keys($this->colonnesLisibles($code)),
        ));
    }

    public static function oublierLeSchema(): void
    {
        self::$colonnesReelles = [];
    }

    /**
     * La requête d'un jeu, déjà bornée à l'année de travail et à l'établissement.
     * Séparée de `lignes()` pour que `compter()` n'ait pas à tout charger en mémoire.
     */
    public function requete(string $code)
    {
        $jeu = self::jeu($code);
        $requete = DB::connection($jeu['connexion'])->table($jeu['table']);

        if ($jeu['annee']) {
            ContexteScolaire::appliquer($requete, $jeu['annee']);
        }

        if (($jeu['perimetre'] ?? null) === 'enseignants') {
            PerimetreEtablissement::appliquerAuxEnseignants($requete);
        } elseif (! empty($jeu['etablissement'])) {
            // Les établissements VISIBLES, comme les écrans — pas l'établissement de la
            // session : sans établissement choisi, celui-ci est vide et l'export rendait
            // les classes de tous les établissements, y compris ceux hors des affectations.
            $etablissements = PerimetreEtablissement::etablissementsVisibles();
            if ($etablissements !== null) {
                $requete->whereIn($jeu['etablissement'], $etablissements);
            }
        } elseif ($jeu['classe']) {
            PerimetreEtablissement::appliquerParClasse($requete, $jeu['classe']);
        }

        return $requete;
    }

    /**
     * Les lignes d'un jeu, en-tête lisible -> valeur. Un jeu injoignable rend un tableau
     * vide plutôt que de faire échouer tout le classeur : une table absente d'ECONOMAT ne
     * doit pas priver l'utilisateur des sept autres feuilles.
     */
    public function lignes(string $code): array
    {
        $jeu = self::jeu($code);
        $colonnes = $this->colonnesLisibles($code);

        try {
            $requete = $this->requete($code)->select(array_values($colonnes));
            // Un tri sur une colonne absente ferait échouer toute la requête : on trie sur
            // ce qui existe, quitte à ne pas trier du tout.
            foreach (array_intersect($jeu['tri'], array_values($colonnes)) as $colonne) {
                $requete->orderBy($colonne);
            }

            return $requete->get()->map(function ($ligne) use ($jeu, $colonnes) {
                $sortie = [];
                // On parcourt les colonnes DÉCLARÉES : une colonne absente de la base donne
                // une cellule vide, et l'en-tête reste. Sans quoi le fichier exporté et le
                // modèle d'import n'auraient plus les mêmes colonnes.
                $ouiNon = $jeu['oui_non'] ?? [];
                foreach ($jeu['colonnes'] as $entete => $colonne) {
                    $sortie[$entete] = isset($colonnes[$entete])
                        ? $this->valeur($ligne->{$colonne} ?? null, in_array($entete, $ouiNon, true))
                        : null;
                }

                return $sortie;
            })->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function compter(string $code): int
    {
        try {
            return (int) $this->requete($code)->count();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Une date SQL Server arrive en « 2026-09-25 00:00:00.000 » : dans un tableur comme sur
     * papier, l'heure à zéro n'apprend rien et gêne la relecture.
     */
    private function valeur($brut, bool $ouiNon = false)
    {
        if ($ouiNon) {
            return $brut === null ? null : ((bool) $brut ? 'Oui' : 'Non');
        }

        if (is_bool($brut)) {
            return $brut ? 'Oui' : 'Non';
        }

        if (is_string($brut) && preg_match('/^(\d{4}-\d{2}-\d{2})[ T]00:00:00(\.\d+)?$/', $brut, $m)) {
            return $m[1];
        }

        return $brut;
    }
}
