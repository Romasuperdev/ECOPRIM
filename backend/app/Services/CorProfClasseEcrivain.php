<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Affectations enseignant ↔ classe ↔ matière : ECONOMAT.dbo.T_CORPROFCLASSE.
 *
 * Colonnes écrites, et elles seules : le professeur, la classe, la matière, l'année et
 * l'indicateur de titulaire. Tout le reste de la ligne — au premier chef LOGIN, qui trace
 * la saisie côté ECONOMAT — est laissé intact.
 *
 * SUPPRESSION RÉELLE — deuxième exception assumée à la règle « jamais de DELETE », après
 * T_EMPLOIDUTEMPS : une affectation est de l'organisation qu'on réajuste, pas un
 * historique. Elle n'est autorisée que si rien n'en dépend en aval (créneaux d'emploi du
 * temps, notes déjà saisies) — c'est le contrôleur qui le vérifie, ce service ne fait
 * qu'exécuter.
 */
class CorProfClasseEcrivain
{
    /** Champ métier -> colonne réelle. Rien d'autre n'est jamais écrit. */
    public const MAP = [
        'classe' => 'CodeClasse',
        'matiere' => 'CodeMatiere',
        'enseignant' => 'CodeProfesseur',
        'annee' => 'ANNEE',
        'principale' => 'Principale',
    ];

    private EconomatTable $table;

    public function __construct()
    {
        $this->table = EconomatTable::pour('T_CORPROFCLASSE', 'Code');
    }

    public function requete()
    {
        return $this->table->requete();
    }

    private function colonnes(array $donnees): array
    {
        $ligne = [];
        foreach (self::MAP as $champ => $colonne) {
            if (array_key_exists($champ, $donnees)) {
                $ligne[$colonne] = $champ === 'principale'
                    ? (bool) $donnees[$champ]
                    : $donnees[$champ];
            }
        }

        return $ligne;
    }

    public function creer(array $donnees): int
    {
        return (int) $this->table->inserer($this->colonnes($donnees));
    }

    public function modifier(int $code, array $donnees): void
    {
        $this->table->modifier($code, $this->colonnes($donnees));
    }

    public function trouver(int $code)
    {
        return $this->table->trouver($code);
    }

    /**
     * Affectation existante pour ce couple classe + matière sur l'année. Une erreur de
     * lecture remonte : « aucune » par défaut créait une seconde affectation en doublon.
     */
    public function pourClasseEtMatiere(string $classe, string $matiere, ?string $annee)
    {
        return $this->requete()
            ->where('CodeClasse', $classe)
            ->where('CodeMatiere', $matiere)
            ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
            ->first();
    }

    /**
     * Un seul titulaire par classe et par année : désigner un nouveau titulaire retire
     * la marque aux autres, plutôt que de laisser deux titulaires coexister.
     */
    public function definirTitulaire(string $classe, ?string $annee, int $code): void
    {
        try {
            $this->requete()
                ->where('CodeClasse', $classe)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->where('Code', '!=', $code)
                ->update(['Principale' => false]);
        } catch (Throwable $e) {
            // Colonne Principale VRAIMENT absente sur cette base (constaté par le schéma) :
            // il n'y a pas de titulaire à désigner. Toute autre erreur remonte — l'avaler
            // laissait croire le titulaire désigné alors que rien n'avait été écrit.
            if (Schema::connection('economat')->hasColumn('T_CORPROFCLASSE', 'Principale')) {
                throw $e;
            }

            return;
        }

        $this->table->modifier($code, ['Principale' => true]);
    }

    /** Suppression réelle. Le contrôleur a déjà vérifié que rien n'en dépend. */
    public function supprimer(int $code): void
    {
        DB::connection('economat')->table('T_CORPROFCLASSE')->where('Code', $code)->delete();
    }
}
