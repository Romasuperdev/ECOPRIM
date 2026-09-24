<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Matières enseignées par un professeur : ECONOMAT.dbo.T_CORPROFMAT.
 *
 * Pourquoi cette table plutôt que la colonne T_PROFESSEUR.Matiere : celle-ci est un
 * varchar(50) qui ne tient qu'UNE matière — y entasser une liste séparée par des virgules
 * déborderait au troisième ou quatrième intitulé, et silencieusement. T_CORPROFMAT est
 * prévue exactement pour cela et n'était jusqu'ici pas exploitée (0 ligne).
 *
 * À ne pas confondre avec T_CORPROFCLASSE, qui dit « ce professeur enseigne CETTE matière
 * DANS CETTE classe ». Ici on décrit ce qu'il enseigne, indépendamment des classes —
 * `CodeClasse` reste donc vide. Les deux coexistent sans se contredire.
 *
 * L'identifiant y est le MATRICULE, non le code interne : la table ne porte pas de colonne
 * CodeProfesseur. Un enseignant sans matricule ne peut donc pas avoir de liste — le cas
 * est rare (le matricule est exigé à la création) mais existe sur les fiches anciennes,
 * et il est signalé plutôt qu'avalé.
 *
 * SUPPRESSION RÉELLE — troisième exception assumée à la règle « jamais de DELETE », après
 * T_EMPLOIDUTEMPS et T_CORPROFCLASSE : décocher une matière doit la retirer. Ce n'est pas
 * un historique, c'est la description de l'enseignant à l'instant présent.
 */
class MatieresEnseignantEcrivain
{
    private EconomatTable $table;

    public function __construct()
    {
        $this->table = EconomatTable::pour('T_CORPROFMAT', 'Code');
    }

    /** Codes des matières d'un professeur pour une année. Vide si illisible ou sans matricule. */
    public function pour(?string $matricule, ?string $annee): array
    {
        if (! $matricule) {
            return [];
        }

        try {
            return $this->table->requete()
                ->where('MatriculeProfesseur', $matricule)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->pluck('CodeMatiere')
                ->map(fn ($c) => trim((string) $c))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Matricules des enseignants ayant cette matière — sert au filtre de la liste. */
    public function matriculesEnseignant(string $matiere, ?string $annee): array
    {
        try {
            return $this->table->requete()
                ->where('CodeMatiere', $matiere)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->pluck('MatriculeProfesseur')
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Aligne la liste stockée sur celle reçue : ajoute ce qui manque, retire ce qui a été
     * décoché, ne touche à rien d'autre. Les lignes inchangées ne sont pas réécrites, ce
     * qui laisse intactes les colonnes remplies par ECONOMAT de son côté (LOGIN).
     *
     * @return bool  Faux si la liste n'a pas pu être enregistrée (pas de matricule).
     */
    public function definir(?string $matricule, ?string $annee, array $codes): bool
    {
        if (! $matricule) {
            return false;
        }

        $voulues = collect($codes)->map(fn ($c) => trim((string) $c))->filter()->unique()->values();
        $actuelles = collect($this->pour($matricule, $annee));

        foreach ($voulues->diff($actuelles) as $code) {
            $this->table->inserer([
                'MatriculeProfesseur' => $matricule,
                'CodeMatiere' => $code,
                'ANNEE' => $annee,
            ]);
        }

        $aRetirer = $actuelles->diff($voulues);
        if ($aRetirer->isNotEmpty()) {
            DB::connection('economat')->table('T_CORPROFMAT')
                ->where('MatriculeProfesseur', $matricule)
                ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
                ->whereIn('CodeMatiere', $aRetirer->all())
                ->delete();
        }

        return true;
    }
}
