<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Absences élèves — ECONOMAT.dbo.T_ABSENCEELEVE.
 *
 * Colonnes écrites, et elles seules : l'élève (matricule et code interne), sa classe, la
 * date, l'heure, le motif, le caractère justifié et l'année. `CodeSession` et tout ce
 * qu'ECONOMAT dépose par ailleurs restent intacts.
 *
 * SUPPRESSION RÉELLE — troisième exception assumée à la règle « jamais de DELETE », après
 * T_EMPLOIDUTEMPS et T_CORPROFCLASSE. Une absence saisie par erreur — mauvais élève,
 * mauvais jour — n'a aucune autre voie de correction, et rien ne dépend d'une ligne
 * d'absence en aval. Elle reste bornée par le verrou d'année clôturée.
 */
class AbsenceEcrivain
{
    public const MAP = [
        'matricule' => 'Matricule',
        'eleve_code' => 'CodeEleve',
        'classe' => 'CodeClasse',
        'date' => 'Date',
        'heure' => 'Heure',
        'motif' => 'Cause',
        'justifiee' => 'Justifier',
        'annee' => 'AnneeCour',
    ];

    private EconomatTable $table;

    public function __construct()
    {
        $this->table = EconomatTable::pour('T_ABSENCEELEVE', 'Code');
    }

    public function requete()
    {
        return $this->table->requete();
    }

    private function colonnes(array $d): array
    {
        $ligne = [];
        foreach (self::MAP as $champ => $colonne) {
            if (array_key_exists($champ, $d)) {
                $ligne[$colonne] = $champ === 'justifiee' ? (bool) $d[$champ] : $d[$champ];
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
        $ligne = $this->colonnes($donnees);
        if ($ligne !== []) {
            $this->table->modifier($code, $ligne);
        }
    }

    public function trouver(int $code)
    {
        return $this->table->trouver($code);
    }

    /** Même élève, même jour, même heure : c'est une double saisie, pas deux absences. */
    public function doublon(array $d, ?int $sauf = null): bool
    {
        try {
            return $this->requete()
                ->where('Matricule', $d['matricule'])
                ->whereDate('Date', $d['date'])
                ->when(($d['heure'] ?? null) !== null, fn ($q) => $q->where('Heure', $d['heure']))
                ->when($sauf !== null, fn ($q) => $q->where('Code', '!=', $sauf))
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function supprimer(int $code): void
    {
        DB::connection('economat')->table('T_ABSENCEELEVE')->where('Code', $code)->delete();
    }
}
