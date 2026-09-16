<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Établissement de travail, choisi dans l'en-tête et conservé en session par
 * ContexteController (`etablissement_code`).
 *
 * Pourquoi ce détour par les classes : T_ETUDIANT n'a PAS de colonne
 * CODEETABLISSEMENT — seulement CODESOCIETE. Impossible donc de filtrer les
 * élèves directement. Le rattachement passe par la classe : T_CLASSE porte
 * CODEETABLISSEMENT, et T_ETUDIANT.CodeClasse y renvoie. Les absences et les
 * moyennes, qui portent elles aussi un CodeClasse, se filtrent de la même façon.
 *
 * Conséquence assumée : une classe dont CODEETABLISSEMENT est vide n'appartient
 * à aucun établissement, et ses élèves sortent des totaux. Ce n'est pas un bug à
 * masquer mais une donnée à corriger, d'où `classesOrphelines()` — le tableau de
 * bord l'affiche plutôt que de laisser un écart inexpliqué.
 *
 * Sans établissement choisi, rien n'est restreint : on garde la vue société
 * plutôt que de vider l'écran (même principe que ContexteScolaire).
 */
class PerimetreEtablissement
{
    private static ?array $cache = null;

    /** Code de l'établissement de travail, ou null en vue société. */
    public static function code(): ?string
    {
        try {
            $code = Session::get('etablissement_code');
        } catch (Throwable $e) {
            return null;
        }

        return ($code === null || $code === '') ? null : (string) $code;
    }

    public static function nom(): ?string
    {
        try {
            return Session::get('etablissement_nom');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Codes des classes de l'établissement de travail, pour l'année en cours.
     * `null` = aucun établissement choisi, donc aucune restriction à appliquer.
     * Un tableau vide, lui, est une réponse : cet établissement n'a pas de classe.
     */
    public static function codesClasses(): ?array
    {
        return self::resoudre()['codes'];
    }

    /** Classes de l'année sans CODEETABLISSEMENT renseigné. */
    public static function classesOrphelines(): int
    {
        return self::resoudre()['orphelines'];
    }

    /** Le filtrage par établissement a-t-il réellement pu s'appliquer ? */
    public static function applique(): bool
    {
        return self::resoudre()['applique'];
    }

    /**
     * Pourquoi il ne s'est pas appliqué, le cas échéant :
     * 'aucun_etablissement'       — vue société, rien à restreindre ;
     * 'aucune_classe_rattachee'   — un établissement est choisi, mais aucune classe
     *                               de l'année ne porte son code (voir resoudre()).
     */
    public static function raison(): ?string
    {
        return self::resoudre()['raison'];
    }

    /**
     * Restreint une requête portant un code de classe à l'établissement de travail.
     * À utiliser sur T_ETUDIANT, T_ABSENCEELEVE, V_MOYENNE_ELEVE_CLASSE, V_NOTECLASSE…
     */
    public static function appliquerParClasse($query, string $colonne = 'CodeClasse')
    {
        $codes = self::codesClasses();

        return $codes === null ? $query : $query->whereIn($colonne, $codes);
    }

    public static function oublier(): void
    {
        self::$cache = null;
    }

    private static function resoudre(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $code = self::code();

        try {
            $classes = ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
            )->get(['CodeClasse', 'CODEETABLISSEMENT']);

            // `?? null` et non un accès direct : selon l'installation ECONOMAT, la
            // colonne peut manquer. Elle a déjà manqué — et l'accès direct levait alors
            // une Error qui vidait silencieusement les effectifs du tableau de bord.
            $etab = fn ($c) => isset($c->CODEETABLISSEMENT) ? trim((string) $c->CODEETABLISSEMENT) : '';

            $orphelines = $classes->filter(fn ($c) => $etab($c) === '')->count();

            if ($code === null) {
                return self::$cache = self::etat(null, $orphelines, false, 'aucun_etablissement');
            }

            $codes = $classes
                ->filter(fn ($c) => $etab($c) === $code)
                ->pluck('CodeClasse')
                ->map(fn ($c) => (string) $c)
                ->values()
                ->all();
        } catch (Throwable $e) {
            // Référentiel illisible : on ne restreint rien plutôt que de tout masquer,
            // et on le dit — le tableau de bord affiche alors un avertissement.
            return self::$cache = self::etat(null, 0, false, 'referentiel_injoignable');
        }

        // Aucune classe de l'année ne porte le code de cet établissement. Restreindre
        // donnerait zéro partout — un écran qui paraît cassé alors que le problème est
        // dans les données. On garde les chiffres de la société et on le DIT : c'est à
        // l'appelant d'afficher l'avertissement, pas de le taire.
        if ($codes === []) {
            return self::$cache = self::etat(null, $orphelines, false, 'aucune_classe_rattachee');
        }

        return self::$cache = self::etat($codes, $orphelines, true, null);
    }

    private static function etat(?array $codes, int $orphelines, bool $applique, ?string $raison): array
    {
        return compact('codes', 'orphelines', 'applique', 'raison');
    }
}
