<?php

namespace App\Services\Echange;

use App\Services\ReferentielEcrivain;
use Throwable;

/**
 * Socle des imports de référentiels (niveaux, classes, matières) : ils écrivent tous par
 * ReferentielEcrivain, le même que leurs écrans, et s'appliquent tous de la même façon —
 * une modification porte la clé primaire dans `_id`, une création n'en porte pas.
 */
abstract class ImportReferentiel extends Importateur
{
    protected ReferentielEcrivain $ecrivain;

    private string $pk;

    public function __construct(string $table, string $pk, array $map)
    {
        $this->ecrivain = new ReferentielEcrivain($table, $pk, $map);
        $this->pk = $pk;
    }

    public function appliquer(array $verdicts): array
    {
        $bilan = ['creees' => 0, 'modifiees' => 0, 'echecs' => []];
        // Code -> clé primaire de ce que CE fichier vient de créer : une ligne répétée plus
        // bas (« tout importer ») met à jour ce que la précédente a créé.
        $creees = [];

        foreach ($verdicts as $v) {
            if ($v['action'] === self::REJET) {
                continue;
            }

            try {
                $d = $v['donnees'];
                $this->avantEcriture($d);
                $cle = $d['_cle'] ?? null;
                $id = $d['_id'] ?? ($cle !== null ? ($creees[$cle] ?? null) : null);
                // Ligne qui vise un code écrit juste avant elle par l'import (classe reprise
                // d'une autre année…) : sa clé primaire se lit maintenant.
                if ($id === null && ! empty($d['_par_code'])) {
                    $id = $this->ecrivain->requete()
                        ->where($this->ecrivain->colonne('code'), $d['_par_code'])
                        ->value($this->pk);
                }
                // Les clés techniques (« _… ») ne vont jamais à l'écrivain.
                $d = array_filter($d, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);

                if ($id !== null) {
                    $this->ecrivain->modifier($id, $d);
                    $bilan['modifiees']++;
                } else {
                    $nouveau = $this->ecrivain->creer($d);
                    if ($cle !== null) {
                        $creees[$cle] = $nouveau;
                    }
                    $bilan['creees']++;
                }
            } catch (Throwable $e) {
                $bilan['echecs'][] = ['ligne' => $v['ligne'], 'motif' => $this->motifEchec($e, $v['ligne'])];
            }
        }

        return $bilan;
    }

    /** Ce qu'il faut écrire avant la ligne elle-même (un niveau à créer pour une classe…). */
    protected function avantEcriture(array $donnees): void {}

    /**
     * « Tout importer » : un code déjà vu plus haut dans le fichier. La ligne n'est pas une
     * erreur : elle met à jour ce que la précédente crée ou modifie, et le dit.
     *
     * @param  array{ligne: int, donnees: array}  $avant
     */
    protected function repetition(int $n, array $d, array $avant, ?int $existant, string $apercu): array
    {
        $note = $avant['donnees'] == $d
            ? "ligne identique à la ligne {$avant['ligne']} : déjà prise en compte"
            : "même code que la ligne {$avant['ligne']} : cette ligne la complète (la dernière valeur l’emporte)";

        return $this->ajuste($this->modification(
            $n,
            $d + ['_cle' => (string) ($d['code'] ?? '')] + ($existant !== null ? ['_id' => $existant] : []),
            $apercu,
        ), [$note]);
    }
}
