<?php

namespace App\Services;

use App\Support\SchemaNotes;
use RuntimeException;

/**
 * Saisie des notes — ECONOMAT.dbo.T_NOTEENTETE + T_NOTEDETAILS.
 *
 * Une évaluation, c'est une ENTÊTE (une classe, une matière, une session, un type, une
 * date, un coefficient) et autant de DÉTAILS que d'élèves notés. NEXORA écrit dans les deux
 * et nulle part ailleurs : `V_NOTECLASSE`, qui alimente les écrans de restitution et les
 * bulletins, est une vue construite par-dessus — elle se met à jour toute seule.
 *
 * COLONNES ÉCRITES. Uniquement celles qu'App\Support\SchemaNotes a su rapprocher d'un rôle
 * métier. Tout ce qu'ECONOMAT dépose par ailleurs dans ces lignes est laissé intact, et une
 * colonne inconnue n'est jamais devinée. Si un rôle indispensable manque, l'écriture est
 * refusée : mieux vaut une saisie indisponible qu'une ligne fausse dans une table partagée.
 *
 * PAS DE SUPPRESSION. La règle commune s'applique sans exception ici : on corrige une note
 * en la réécrivant, jamais en effaçant la ligne. Une note supprimée casserait la moyenne
 * déjà calculée par ECONOMAT et le rang qui en découle.
 */
class NoteEcrivain
{
    /** @return array<string, string> rôle -> colonne */
    public function entete(): array
    {
        return SchemaNotes::correspondance(SchemaNotes::ENTETE);
    }

    /** @return array<string, string> rôle -> colonne */
    public function detail(): array
    {
        return SchemaNotes::correspondance(SchemaNotes::DETAIL);
    }

    public function disponible(): bool
    {
        return SchemaNotes::saisiePossible();
    }

    /** Message destiné à l'écran quand la saisie est fermée : il doit dire quoi regarder. */
    public function raisonIndisponible(): string
    {
        $manque = [
            SchemaNotes::ENTETE => SchemaNotes::manquants(SchemaNotes::ENTETE),
            SchemaNotes::DETAIL => SchemaNotes::manquants(SchemaNotes::DETAIL),
        ];

        foreach ($manque as $table => $roles) {
            if ($roles !== [] && SchemaNotes::colonnesReelles($table) === []) {
                return "La table {$table} est introuvable sur la connexion ECONOMAT : la saisie des notes reste fermée.";
            }
        }

        $details = [];
        foreach ($manque as $table => $roles) {
            if ($roles !== []) {
                $details[] = $table.' ('.implode(', ', $roles).')';
            }
        }

        return 'Colonnes non identifiées dans '.implode(' et ', $details)
            .'. NEXORA refuse d\'écrire dans une table de production qu\'il ne reconnaît pas.';
    }

    private function table(string $quoi): EconomatTable
    {
        $nom = $quoi === 'entete' ? SchemaNotes::ENTETE : SchemaNotes::DETAIL;
        $roles = $quoi === 'entete' ? $this->entete() : $this->detail();

        return EconomatTable::pour($nom, $roles['id']);
    }

    /** Ne garde que les rôles connus ET fournis : le reste de la ligne n'est pas touché. */
    private function ligne(array $roles, array $donnees): array
    {
        $ligne = [];
        foreach ($roles as $role => $colonne) {
            if ($role !== 'id' && array_key_exists($role, $donnees) && $donnees[$role] !== null) {
                $ligne[$colonne] = $donnees[$role];
            }
        }

        return $ligne;
    }

    private function exiger(): void
    {
        if (! $this->disponible()) {
            throw new RuntimeException($this->raisonIndisponible());
        }
    }

    /**
     * L'entête de cette évaluation, créée si elle n'existe pas.
     *
     * L'identité d'une évaluation, ce sont la classe, la matière, la session et l'année,
     * plus le type et la date quand la table les porte. Deux devoirs de maths le même jour
     * dans la même classe restent donc la même entête — c'est voulu : on saisit une feuille
     * de notes, pas deux. Un second devoir se distingue par son libellé, et l'écran le
     * propose ; s'il diffère, l'entête aussi.
     */
    /**
     * L'entête déjà saisie pour cette identité, ou null. Isolée d'`entetePour()` pour que
     * l'analyse d'un import puisse annoncer « création » ou « modification » SANS écrire :
     * un rapport qui devrait créer la ligne pour savoir quoi annoncer ne serait plus un
     * rapport. Les deux chemins partagent forcément la même définition de l'identité.
     */
    public function enteteExistante(array $criteres): ?int
    {
        $this->exiger();
        $roles = $this->entete();

        // Sur quoi on reconnaît une entête déjà saisie.
        $identite = array_filter(
            ['classe', 'matiere', 'session', 'annee', 'type', 'date', 'libelle'],
            fn ($r) => isset($roles[$r]) && array_key_exists($r, $criteres) && $criteres[$r] !== null
        );

        $requete = $this->table('entete')->requete();
        foreach ($identite as $role) {
            $requete->where($roles[$role], $criteres[$role]);
        }

        $existante = $requete->first();

        return $existante ? (int) $existante->{$roles['id']} : null;
    }

    /** Cet élève a-t-il déjà une note sur cette entête ? Même usage que ci-dessus. */
    public function noteExistante(int $entete, string $eleve): bool
    {
        $this->exiger();
        $roles = $this->detail();

        return $this->table('detail')->requete()
            ->where($roles['entete'], $entete)
            ->where($roles['eleve'], $eleve)
            ->exists();
    }

    public function entetePour(array $criteres): int
    {
        $this->exiger();
        $roles = $this->entete();

        $code = $this->enteteExistante($criteres);
        if ($code !== null) {
            // Le coefficient et le barème peuvent être ajustés sans recréer l'évaluation.
            $maj = $this->ligne(array_intersect_key($roles, array_flip(['coefficient', 'bareme', 'professeur'])), $criteres);
            if ($maj !== []) {
                $this->table('entete')->modifier($code, $maj);
            }

            return $code;
        }

        return (int) $this->table('entete')->inserer($this->ligne($roles, $criteres));
    }

    /**
     * Enregistre les notes d'une entête. Une note déjà saisie pour cet élève est REMPLACÉE,
     * jamais dupliquée : on ne veut pas deux lignes pour le même élève sur la même
     * évaluation, la moyenne d'ECONOMAT les compterait toutes les deux.
     *
     * @param  array<int, array{eleve: string, note?: float|null, appreciation?: string|null, absent?: bool}>  $lignes
     * @return array{creees: int, modifiees: int}
     */
    public function enregistrer(int $entete, array $lignes): array
    {
        $this->exiger();
        $roles = $this->detail();
        $bilan = ['creees' => 0, 'modifiees' => 0];

        foreach ($lignes as $saisie) {
            $eleve = $saisie['eleve'] ?? null;
            if ($eleve === null || $eleve === '') {
                continue;
            }

            $existante = $this->table('detail')->requete()
                ->where($roles['entete'], $entete)
                ->where($roles['eleve'], $eleve)
                ->first();

            $valeurs = $this->ligne($roles, $saisie + ['entete' => $entete]);

            if ($existante) {
                $this->table('detail')->modifier((int) $existante->{$roles['id']}, $valeurs);
                $bilan['modifiees']++;
            } else {
                $this->table('detail')->inserer($valeurs);
                $bilan['creees']++;
            }
        }

        return $bilan;
    }

    /** Les notes déjà saisies pour une entête, pour repeupler la feuille. */
    public function notesDe(int $entete): array
    {
        $this->exiger();
        $roles = $this->detail();

        return $this->table('detail')->requete()
            ->where($roles['entete'], $entete)
            ->get()
            ->map(fn ($l) => [
                'eleve' => $l->{$roles['eleve']},
                'note' => isset($roles['note']) ? $l->{$roles['note']} : null,
                'appreciation' => isset($roles['appreciation']) ? $l->{$roles['appreciation']} : null,
                'absent' => isset($roles['absent']) ? (bool) $l->{$roles['absent']} : false,
            ])
            ->all();
    }
}
