<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Structure réelle de ECONOMAT.dbo.T_NOTEENTETE et T_NOTEDETAILS, découverte à l'exécution.
 *
 * POURQUOI. La saisie des notes était bloquée depuis le début du projet : l'écran ne sait
 * que lire `V_NOTECLASSE`, qui est une VUE — on n'écrit pas dans une vue. Les vraies tables
 * sont ces deux-là, et leurs colonnes n'ont jamais pu être relevées. Plutôt que d'attendre
 * indéfiniment un SELECT, NEXORA fait comme pour T_TRACABILITE : il lit la liste des
 * colonnes au moment où il en a besoin et la rapproche de rôles métier.
 *
 * FERMÉ PAR DÉFAUT. Si les rôles indispensables ne sont pas trouvés, l'écriture est REFUSÉE
 * avec le détail de ce qui manque — jamais tentée « au cas où ». Écrire à l'aveugle dans une
 * table de production partagée est le seul risque qu'on ne prend pas. `diagnostic()` expose
 * ce que le serveur a vu, ce qui permet de vérifier la correspondance depuis l'application
 * elle-même plutôt que depuis SQL Server.
 *
 * GARDE-FOUS DU RAPPROCHEMENT. Une correspondance exacte l'emporte toujours sur une
 * correspondance partielle ; une colonne déjà prise ne peut pas l'être deux fois ; et les
 * rôles structurants (clé primaire, lien entête→détail) n'acceptent QUE l'exact, sans quoi
 * `Code` irait se confondre avec `CodeClasse`. Les rôles qui portent une valeur refusent
 * en outre toute colonne préfixée `Code`/`Num`, qui est par convention une référence.
 */
class SchemaNotes
{
    public const ENTETE = 'T_NOTEENTETE';
    public const DETAIL = 'T_NOTEDETAILS';

    /** Rôles sans lesquels on ne sait pas écrire : leur absence ferme la saisie. */
    public const REQUIS_ENTETE = ['id', 'classe', 'matiere', 'session'];
    public const REQUIS_DETAIL = ['id', 'entete', 'eleve', 'note'];

    /** Rôles structurants : exact uniquement. */
    private const STRICTS = ['id', 'entete'];

    /** Rôles porteurs d'une valeur : jamais une colonne de référence (Code…, Num…). */
    private const VALEURS = ['note', 'coefficient', 'bareme', 'date', 'libelle', 'appreciation', 'absent'];

    private const MOTIFS_ENTETE = [
        'id' => ['code', 'codenote', 'numnote', 'num', 'id'],
        'classe' => ['codeclasse', 'classe'],
        'matiere' => ['codematiere', 'matiere'],
        'session' => ['codesession', 'session', 'periode', 'trimestre', 'sequence'],
        'annee' => ['codeannee', 'anneeacad', 'anneecour', 'annee'],
        'type' => ['typenote', 'typeevaluation', 'typeeval', 'nature', 'type'],
        'date' => ['datenote', 'dateevaluation', 'datesaisie', 'date'],
        'coefficient' => ['coefficient', 'coef'],
        'bareme' => ['notesur', 'bareme', 'sur'],
        'libelle' => ['libelle', 'intitule', 'designation', 'titre'],
        'professeur' => ['codeprof', 'matriculeprof', 'professeur', 'enseignant'],
        'etablissement' => ['codeetablissement', 'codeetab', 'etablissement'],
    ];

    private const MOTIFS_DETAIL = [
        'id' => ['code', 'coddetail', 'codedetail', 'num', 'id'],
        'entete' => ['codeentete', 'numentete', 'codenote', 'numnote', 'entete'],
        'eleve' => ['matricule', 'codeeleve', 'codeetudiant', 'eleve', 'etudiant'],
        'note' => ['note', 'valeur', 'points', 'moyenne'],
        'appreciation' => ['appreciation', 'observation', 'commentaire', 'remarque'],
        'absent' => ['absent', 'abs'],
    ];

    /** @var array<string, array<int, string>> */
    private static array $cache = [];

    /** Colonnes réelles d'une des deux tables, [] si elle est injoignable. */
    public static function colonnesReelles(string $table): array
    {
        if (array_key_exists($table, self::$cache)) {
            return self::$cache[$table];
        }

        try {
            self::$cache[$table] = Schema::connection('economat')->getColumnListing($table);
        } catch (Throwable $e) {
            self::$cache[$table] = [];
        }

        return self::$cache[$table];
    }

    public static function oublier(): void
    {
        self::$cache = [];
    }

    /**
     * Rôle métier -> nom réel de colonne, pour les rôles effectivement présents.
     *
     * @return array<string, string>
     */
    public static function correspondance(string $table): array
    {
        $motifs = $table === self::ENTETE ? self::MOTIFS_ENTETE : self::MOTIFS_DETAIL;
        $reelles = self::colonnesReelles($table);
        if ($reelles === []) {
            return [];
        }

        $index = [];
        foreach ($reelles as $colonne) {
            $index[self::normaliser($colonne)] = $colonne;
        }

        $trouve = [];
        $prises = [];

        // Passe 1 — égalité stricte. Elle seule sert aux rôles structurants.
        foreach ($motifs as $role => $liste) {
            foreach ($liste as $motif) {
                $colonne = $index[$motif] ?? null;
                if ($colonne !== null && ! in_array($colonne, $prises, true)) {
                    $trouve[$role] = $colonne;
                    $prises[] = $colonne;
                    break;
                }
            }
        }

        // Passe 2 — correspondance partielle, pour les rôles encore vides.
        foreach ($motifs as $role => $liste) {
            if (isset($trouve[$role]) || in_array($role, self::STRICTS, true)) {
                continue;
            }
            foreach ($liste as $motif) {
                foreach ($index as $normalisee => $colonne) {
                    if (in_array($colonne, $prises, true) || ! str_contains($normalisee, $motif)) {
                        continue;
                    }
                    // Un rôle qui porte une valeur ne peut pas être une référence.
                    if (in_array($role, self::VALEURS, true) && preg_match('/^(code|num)/', $normalisee)) {
                        continue;
                    }
                    $trouve[$role] = $colonne;
                    $prises[] = $colonne;
                    break 2;
                }
            }
        }

        return $trouve;
    }

    /** Rôles indispensables qui n'ont pas pu être rapprochés d'une colonne. */
    public static function manquants(string $table): array
    {
        if (self::colonnesReelles($table) === []) {
            return $table === self::ENTETE ? self::REQUIS_ENTETE : self::REQUIS_DETAIL;
        }

        $requis = $table === self::ENTETE ? self::REQUIS_ENTETE : self::REQUIS_DETAIL;

        return array_values(array_diff($requis, array_keys(self::correspondance($table))));
    }

    /** Vrai seulement si les deux tables sont là ET complètes : sinon, on n'écrit pas. */
    public static function saisiePossible(): bool
    {
        return self::manquants(self::ENTETE) === [] && self::manquants(self::DETAIL) === [];
    }

    /**
     * Ce que le serveur a réellement vu — de quoi contrôler le rapprochement sans
     * ouvrir SQL Server, et de quoi corriger les motifs si l'un d'eux tombe à côté.
     */
    public static function diagnostic(): array
    {
        $bloc = fn (string $t) => [
            'table' => $t,
            'accessible' => self::colonnesReelles($t) !== [],
            'colonnes_reelles' => self::colonnesReelles($t),
            'correspondance' => self::correspondance($t),
            'roles_manquants' => self::manquants($t),
        ];

        return [
            'saisie_possible' => self::saisiePossible(),
            'entete' => $bloc(self::ENTETE),
            'detail' => $bloc(self::DETAIL),
        ];
    }

    private static function normaliser(string $colonne): string
    {
        return strtolower(str_replace(['_', ' ', '-'], '', $colonne));
    }
}
