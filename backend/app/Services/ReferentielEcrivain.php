<?php

namespace App\Services;

/**
 * Écriture contrôlée d'un référentiel ECONOMAT (années, cycles, niveaux, classes, matières).
 *
 * Chaque référentiel déclare sa table, sa clé primaire et la correspondance
 * champ métier -> colonne réelle. Rien en dehors de cette correspondance n'est jamais
 * écrit : les colonnes de rattachement propres à ECONOMAT (CODESOCIETE, CODEETABLISSEMENT,
 * CodeEtab…) et tout ce que les autres applications de la suite y déposent restent intacts.
 *
 * La suppression est réelle mais conditionnelle : le contrôleur vérifie d'abord, via
 * DependancesReferentiel, que rien ne s'y réfère. Un référentiel n'est pas un historique,
 * et une ligne créée par erreur doit pouvoir disparaître — tant qu'elle ne laisse personne
 * orphelin.
 */
class ReferentielEcrivain
{
    private EconomatTable $table;

    public function __construct(
        private string $nomTable,
        private string $pk,
        private array $map,
    ) {
        $this->table = EconomatTable::pour($nomTable, $pk);
    }

    public function requete()
    {
        return $this->table->requete();
    }

    public function colonne(string $champ): ?string
    {
        return $this->map[$champ] ?? null;
    }

    /** Ne retient que les champs connus, en convertissant les booléens. */
    private function colonnes(array $donnees, array $booleens = []): array
    {
        $ligne = [];
        foreach ($this->map as $champ => $colonne) {
            if (array_key_exists($champ, $donnees)) {
                $ligne[$colonne] = in_array($champ, $booleens, true)
                    ? (bool) $donnees[$champ]
                    : $donnees[$champ];
            }
        }

        return $ligne;
    }

    public function creer(array $donnees, array $booleens = [])
    {
        return $this->table->inserer($this->colonnes($donnees, $booleens));
    }

    public function modifier($id, array $donnees, array $booleens = []): void
    {
        $ligne = $this->colonnes($donnees, $booleens);
        if ($ligne !== []) {
            $this->table->modifier($id, $ligne);
        }
    }

    public function trouver($id)
    {
        return $this->table->trouver($id);
    }

    /** Un code déjà pris — les référentiels d'ECONOMAT n'ont pas de contrainte d'unicité. */
    public function codeExiste(string $champ, string $valeur, $sauf = null): bool
    {
        $colonne = $this->colonne($champ);
        if (! $colonne) {
            return false;
        }

        return $this->requete()
            ->where($colonne, $valeur)
            ->when($sauf !== null, fn ($q) => $q->where($this->pk, '!=', $sauf))
            ->exists();
    }

    /** Suppression réelle. Le contrôleur a déjà vérifié que rien n'en dépend. */
    public function supprimer($id): void
    {
        $this->requete()->where($this->pk, $id)->delete();
    }
}
