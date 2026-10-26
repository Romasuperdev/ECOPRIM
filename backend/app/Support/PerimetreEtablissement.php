<?php

namespace App\Support;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;
use WeakMap;

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
 *
 * Les comptes des portails Enseignant et Parent n'ont pas d'établissement de travail :
 * leur périmètre est le leur (voir comptePortail()).
 */
class PerimetreEtablissement
{
    private static ?array $cache = null;

    /** Enseignants du périmètre, mémorisés comme les classes ; `false` = pas encore lus. */
    private static array|null|false $enseignants = false;

    /** Verdict « compte de portail » par compte, lu une fois par requête. */
    private static ?WeakMap $portails = null;

    /** Code de l'établissement de travail, ou null en vue société. */
    public static function code(): ?string
    {
        if (self::comptePortail()) {
            return null;
        }

        try {
            $code = Session::get('etablissement_code');
        } catch (Throwable $e) {
            return null;
        }

        return ($code === null || $code === '') ? null : (string) $code;
    }

    public static function nom(): ?string
    {
        if (self::comptePortail()) {
            return null;
        }

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
     * 'referentiel_injoignable'   — les classes n'ont pas pu être lues.
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

    /**
     * La classe fait-elle partie du périmètre ? Toujours vrai quand rien n'est restreint ;
     * une classe absente (élève sans classe) n'appartient, elle, à aucun établissement.
     */
    public static function classeVisible(?string $code): bool
    {
        $codes = self::codesClasses();
        if ($codes === null) {
            return true;
        }

        $code = trim((string) $code);

        return $code !== '' && in_array($code, array_map('trim', $codes), true);
    }

    /**
     * 404 pour un élément rattaché à une classe hors périmètre : ce qu'une liste ne montre
     * pas, son détail ne doit pas le confirmer non plus.
     */
    public static function exigerClasse(?string $code, string $message = 'Classe introuvable.'): void
    {
        abort_unless(self::classeVisible($code), 404, $message);
    }

    /**
     * Une société choisie sans aucun établissement : il n'y a rien à montrer, même dans
     * les écrans qui ne passent pas par les classes.
     */
    public static function rienAMontrer(): bool
    {
        return self::etablissementsVisibles() === [];
    }

    /**
     * Codes des enseignants (T_PROFESSEUR.Code) du périmètre, ou null sans restriction.
     *
     * T_PROFESSEUR ne porte ni classe ni établissement : un enseignant appartient au
     * périmètre par ses affectations de l'année (T_CORPROFCLASSE) dans les classes visibles
     * — même règle que le compteur du tableau de bord. Affectations illisibles : liste vide,
     * comme ce compteur, qui tombe alors à zéro.
     */
    public static function codesEnseignants(): ?array
    {
        if (self::$enseignants !== false) {
            return self::$enseignants;
        }

        $codes = self::codesClasses();
        if ($codes === null) {
            return self::$enseignants = null;
        }
        if ($codes === []) {
            return self::$enseignants = [];
        }

        try {
            $requete = DB::connection('economat')->table('T_CORPROFCLASSE')
                ->whereIn('CodeClasse', $codes)
                ->whereNotNull('CodeProfesseur');
            ContexteScolaire::appliquer($requete, 'ANNEE');

            return self::$enseignants = $requete->distinct()->pluck('CodeProfesseur')
                ->map(fn ($c) => (int) $c)->values()->all();
        } catch (Throwable $e) {
            return self::$enseignants = [];
        }
    }

    public static function enseignantVisible($code): bool
    {
        $codes = self::codesEnseignants();

        return $codes === null || in_array((int) $code, $codes, true);
    }

    /** 404 pour un enseignant hors périmètre, comme exigerClasse() pour une classe. */
    public static function exigerEnseignant($code, string $message = 'Enseignant introuvable.'): void
    {
        abort_unless(self::enseignantVisible($code), 404, $message);
    }

    public static function oublier(): void
    {
        self::$cache = null;
        self::$enseignants = false;
        self::$portails = null;
    }

    /**
     * Le compte connecté est-il celui d'un portail restreint (Enseignant, Parent) ?
     *
     * Son périmètre n'est pas un établissement de travail mais le sien — les classes qu'il
     * enseigne, les enfants qui lui sont rattachés — et le contrôleur du portail l'impose
     * avant de déléguer aux contrôleurs du personnel. Leur appliquer EN PLUS le filtre
     * d'établissement viderait ses listes, ou refuserait ses documents en 404, dès qu'un
     * établissement ou une société traîne en session (connexion sur un poste où un autre
     * compte avait choisi le sien, par exemple) : il n'en a pas, il n'en choisit pas.
     *
     * Un compte qui cumule un rôle d'administration n'est pas un compte de portail
     * (RhUser::typePortail) : il garde le périmètre de l'application complète.
     */
    private static function comptePortail(): bool
    {
        try {
            $user = auth()->user();
        } catch (Throwable $e) {
            return false;
        }

        if (! $user instanceof RhUser) {
            return false;
        }

        self::$portails ??= new WeakMap;

        try {
            return self::$portails[$user] ??= $user->typePortail() !== 'staff';
        } catch (Throwable $e) {
            // Rôles illisibles : on garde le filtre de l'application complète.
            return false;
        }
    }

    /**
     * Société de travail (choisie dans l'en-tête), ou null en vue générale. Une société
     * compte un ou plusieurs établissements : elle borne ceux qu'on peut voir.
     */
    public static function societe(): ?string
    {
        try {
            return PerimetreConsole::codeCourant();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Codes des établissements rattachés à une société : ceux d'ECONOMAT et ceux créés
     * dans ECOPRIM. Chaque source est lue à part — l'une injoignable n'efface pas l'autre.
     */
    public static function etablissementsDeSociete(string $societe): array
    {
        $codes = [];

        try {
            $codes = DB::connection('economat')->table('BEtablissements')
                ->where('CodeSociete', $societe)->pluck('CodeEtablissement')->all();
        } catch (Throwable $e) {
            // Source absente : on continue avec la seconde.
        }
        try {
            $codes = array_merge($codes, DB::connection('ecoprim')->table('console_etablissements')
                ->where('societe_code', $societe)->pluck('code')->all());
        } catch (Throwable $e) {
            // Idem.
        }

        return array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $codes))));
    }

    /**
     * Établissements dont on montre les données, ou null s'il n'y a rien à restreindre.
     *
     *   — un établissement est choisi (et appartient à la société de travail) : lui seul ;
     *   — sinon, une société est choisie : tous SES établissements — et s'il n'y en a
     *     aucun, la liste est vide : une société sans établissement n'a rien à montrer ;
     *   — sinon (vue générale du Super Admin) : aucune restriction.
     */
    public static function etablissementsVisibles(): ?array
    {
        if (self::comptePortail()) {
            return null;
        }

        $choisi = self::code();
        $societe = self::societe();

        if ($societe === null) {
            return $choisi === null ? null : [$choisi];
        }

        $deLaSociete = self::etablissementsDeSociete($societe);

        // Un établissement choisi avant de changer de société n'en fait plus partie : il
        // ne doit pas continuer à afficher les données d'une autre société.
        return ($choisi !== null && in_array($choisi, $deLaSociete, true)) ? [$choisi] : $deLaSociete;
    }

    private static function resoudre(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $visibles = self::etablissementsVisibles();

        try {
            $classes = ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
            )->get(['CodeClasse', 'CODEETABLISSEMENT']);

            // `?? null` et non un accès direct : selon l'installation ECONOMAT, la
            // colonne peut manquer. Elle a déjà manqué — et l'accès direct levait alors
            // une Error qui vidait silencieusement les effectifs du tableau de bord.
            $etab = fn ($c) => isset($c->CODEETABLISSEMENT) ? trim((string) $c->CODEETABLISSEMENT) : '';

            $orphelines = $classes->filter(fn ($c) => $etab($c) === '')->count();

            if ($visibles === null) {
                return self::$cache = self::etat(null, $orphelines, false, 'aucun_etablissement');
            }

            $codes = $classes
                ->filter(fn ($c) => in_array($etab($c), $visibles, true))
                ->pluck('CodeClasse')
                ->map(fn ($c) => (string) $c)
                ->values()
                ->all();
        } catch (Throwable $e) {
            // Référentiel illisible : on ne restreint rien plutôt que de tout masquer,
            // et on le dit — le tableau de bord affiche alors un avertissement.
            return self::$cache = self::etat(null, 0, false, 'referentiel_injoignable');
        }

        // Une société compte un ou plusieurs établissements, et chacun n'affiche que SES
        // données. Un établissement sans classe cette année n'a donc rien à montrer :
        // la liste vide s'applique telle quelle (zéro élève, zéro classe), au lieu de
        // retomber sur les chiffres de toute la société — ce que faisait l'ancienne règle,
        // au prix d'un avertissement que l'utilisateur ne voulait pas.
        return self::$cache = self::etat($codes, $orphelines, true, null);
    }

    private static function etat(?array $codes, int $orphelines, bool $applique, ?string $raison): array
    {
        return compact('codes', 'orphelines', 'applique', 'raison');
    }
}
