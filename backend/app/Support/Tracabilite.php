<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Lecture de ECONOMAT.dbo.T_TRACABILITE — journal d'activité tenu par la suite.
 *
 * Cette table ne nous appartient pas et sa structure n'a pas été relevée : plutôt que de
 * parier sur des noms de colonnes, on lit la liste réelle des colonnes à l'exécution et on
 * la rapproche de rôles métier (quand, qui, quoi, sur quoi, où). Une colonne absente
 * n'apparaît simplement pas — la page reste utilisable, et rien ne casse le jour où la
 * table diffère de ce qu'on imaginait.
 *
 * Aucune écriture : NEXORA ne trace pas ici, il restitue ce qu'ECONOMAT y a écrit.
 */
class Tracabilite
{
    public const TABLE = 'T_TRACABILITE';

    /**
     * Rôle métier -> motifs de noms de colonnes, du plus précis au plus large.
     * Le premier motif qui trouve une colonne gagne.
     */
    private const MOTIFS = [
        'id' => ['code', 'codes', 'id', 'num', 'numero'],
        'date' => ['dateoperation', 'datetrace', 'dateheure', 'datesaisie', 'date', 'heure'],
        'utilisateur' => ['login', 'utilisateur', 'user', 'codeuser', 'coduser', 'matricule', 'nomuser'],
        'action' => ['action', 'operation', 'evenement', 'typeoperation', 'type', 'libelle'],
        'objet' => ['tablecible', 'nomtable', 'table', 'ecran', 'formulaire', 'module', 'objet'],
        'detail' => ['detail', 'details', 'description', 'commentaire', 'observation', 'valeur', 'motif'],
        'poste' => ['poste', 'machine', 'terminal', 'ip', 'adresseip'],
        'societe' => ['codesociete', 'societe'],
        'etablissement' => ['codeetablissement', 'etablissement', 'codeetab', 'etab'],
    ];

    private static ?array $cache = null;

    /** Colonnes réelles de la table, ou [] si la table est injoignable. */
    public static function colonnesReelles(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            self::$cache = Schema::connection('economat')->getColumnListing(self::TABLE);
        } catch (Throwable $e) {
            self::$cache = [];
        }

        return self::$cache;
    }

    public static function oublier(): void
    {
        self::$cache = null;
    }

    public static function disponible(): bool
    {
        return self::colonnesReelles() !== [];
    }

    /**
     * Rôle métier -> nom réel de la colonne, pour les rôles effectivement présents.
     *
     * @return array<string, string>
     */
    public static function correspondance(): array
    {
        $reelles = self::colonnesReelles();
        if ($reelles === []) {
            return [];
        }

        // Index insensible à la casse : ECONOMAT mélange les conventions d'une table à l'autre.
        $index = [];
        foreach ($reelles as $colonne) {
            $index[strtolower(str_replace(['_', ' ', '-'], '', $colonne))] = $colonne;
        }

        $correspondance = [];
        $prises = [];

        foreach (self::MOTIFS as $role => $motifs) {
            foreach ($motifs as $motif) {
                // Égalité exacte d'abord, puis « contient » : DATEOPERATION avant DATE_MAJ.
                $trouvee = $index[$motif] ?? null;

                if (! $trouvee) {
                    foreach ($index as $normalisee => $colonne) {
                        if (str_contains($normalisee, $motif) && ! in_array($colonne, $prises, true)) {
                            $trouvee = $colonne;
                            break;
                        }
                    }
                }

                if ($trouvee && ! in_array($trouvee, $prises, true)) {
                    $correspondance[$role] = $trouvee;
                    $prises[] = $trouvee;
                    break;
                }
            }
        }

        return $correspondance;
    }

    public static function colonne(string $role): ?string
    {
        return self::correspondance()[$role] ?? null;
    }
}
