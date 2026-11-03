<?php

namespace App\Support;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
 * Fermé par défaut (fail closed). Seul le Super Admin en vue générale — ni société ni
 * établissement choisis — n'a aucune restriction (`null`). Tout autre compte du personnel
 * est borné aux établissements de ses affectations actives (console_affectations) : un
 * Admin Société à tous ceux de ses sociétés, les autres à ceux qui leur sont affectés.
 * Le choix de l'en-tête (établissement, société) ne fait que resserrer ce périmètre,
 * jamais l'élargir ; sans choix, il reste entier — et un compte sans affectation ne voit
 * rien. Une lecture qui échoue ferme aussi : mieux vaut un écran vide qu'un écran qui
 * montre les données d'une autre société.
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

    /** Établissements visibles, mémorisés le temps d'une requête ; `false` = pas encore lus. */
    private static array|null|false $visibles = false;

    /** Le périmètre a été fermé faute d'avoir pu être lu (voir raison()). */
    private static bool $incident = false;

    /** Compte du personnel sans aucune affectation active : il n'a rien à voir. */
    private static bool $sansAffectation = false;

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
     * Codes des classes du périmètre, pour l'année en cours.
     * `null` = aucune restriction : vue générale du Super Admin (ou compte de portail, dont
     * le contrôleur impose lui-même le périmètre). Un tableau vide, lui, est une réponse :
     * rien à montrer (établissement sans classe, compte sans affectation, référentiel
     * illisible — voir raison()).
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
     * Pourquoi il ne s'est pas appliqué (ou pourquoi il est vide), le cas échéant :
     * 'aucun_etablissement'       — vue générale du Super Admin, rien à restreindre ;
     * 'aucune_affectation'        — compte du personnel affecté à aucun établissement :
     *                               le périmètre est vide ;
     * 'referentiel_injoignable'   — les affectations, la société ou les classes n'ont pas
     *                               pu être lues : le périmètre est FERMÉ (codes = []).
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
     * Aucun établissement visible (société sans établissement, compte sans affectation,
     * périmètre illisible) : il n'y a rien à montrer, même dans les écrans qui ne passent
     * pas par les classes.
     */
    public static function rienAMontrer(): bool
    {
        if (self::etablissementsVisibles() === []) {
            return true;
        }

        $etat = self::resoudre();

        return $etat['codes'] === [] && $etat['raison'] === 'referentiel_injoignable';
    }

    /**
     * La classe relève-t-elle d'un établissement visible, QUELLE QUE SOIT son année ?
     *
     * classeVisible() ne connaît que les classes de l'année de travail. Or un élève à
     * réinscrire porte encore la classe de l'an passé : il faut remonter à l'établissement
     * de cette classe-là pour savoir s'il est des nôtres. Lecture impossible : non.
     */
    public static function classeDuPerimetre(?string $code): bool
    {
        $visibles = self::etablissementsVisibles();
        if ($visibles === null) {
            return true;
        }

        $code = trim((string) $code);
        if ($code === '' || $visibles === []) {
            return false;
        }
        if (self::classeVisible($code)) {
            return true;
        }

        try {
            $etablissements = DB::connection('economat')->table('T_CLASSE')
                ->where('CodeClasse', $code)->pluck('CODEETABLISSEMENT')
                ->map(fn ($c) => trim((string) $c))->all();
        } catch (Throwable $e) {
            Log::error("Établissement d'une classe illisible : accès fermé.", ['classe' => $code, 'erreur' => $e->getMessage()]);

            return false;
        }

        return array_intersect($etablissements, $visibles) !== [];
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

    /**
     * Borne une requête sur T_PROFESSEUR aux enseignants de l'écran Enseignants — partagé
     * avec l'export, qui ne doit pas rendre plus que l'écran.
     *
     * Un enseignant relève de l'établissement par ses affectations de l'année dans les
     * classes visibles, OU par son établissement de rattachement (Site), posé à sa
     * création : sans cela, un enseignant tout juste créé — donc sans classe — n'apparaîtrait
     * nulle part. L'année filtre les fiches, sauf celles qui ont des affectations cette
     * année ou qui ne portent pas d'année.
     */
    public static function appliquerAuxEnseignants($query)
    {
        $codes = self::codesEnseignants();
        $etabs = self::etablissementsVisibles();
        $site = ClassesEnseignant::siteDisponible();

        if ($codes !== null) {
            $query->where(function ($w) use ($codes, $etabs, $site) {
                $w->whereIn('Code', $codes === [] ? [-1] : $codes);
                if ($site && $etabs) {
                    $w->orWhereIn('Site', $etabs);
                }
            });
        }

        if (ContexteScolaire::annee() === null) {
            return $query;
        }

        return $query->where(function ($w) use ($codes) {
            ContexteScolaire::appliquer($w, 'CodeAnnee');
            $w->orWhereNull('CodeAnnee')->orWhere('CodeAnnee', '');
            if ($codes) {
                $w->orWhereIn('Code', $codes);
            }
        });
    }

    public static function enseignantVisible($code): bool
    {
        $codes = self::codesEnseignants();
        if ($codes === null || in_array((int) $code, $codes, true)) {
            return true;
        }

        // Rattaché à un établissement visible (T_PROFESSEUR.Site, posé à sa création) :
        // un enseignant encore sans classe reste consultable et modifiable.
        $etabs = self::etablissementsVisibles();
        if (! $etabs || ! ClassesEnseignant::siteDisponible()) {
            return false;
        }
        try {
            $site = DB::connection('economat')->table('T_PROFESSEUR')->where('Code', $code)->value('Site');
        } catch (Throwable $e) {
            return false;
        }

        return $site !== null && self::parmi((string) $site, $etabs);
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
        self::$visibles = false;
        self::$incident = false;
        self::$sansAffectation = false;
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
            // Liste fermée : un type « indisponible » (rôles illisibles) n'est pas un portail
            // et ne doit pas échapper au filtre.
            return self::$portails[$user] ??= in_array($user->typePortail(), ['enseignant', 'parent'], true);
        } catch (Throwable $e) {
            // Rôles illisibles : on garde le filtre de l'application complète.
            return false;
        }
    }

    /**
     * Société de travail (choisie dans l'en-tête), ou null en vue générale. Une société
     * compte un ou plusieurs établissements : elle borne ceux qu'on peut voir.
     *
     * Une erreur rend null : réservé à l'AFFICHAGE. Le calcul du périmètre, lui, appelle
     * PerimetreConsole::codeCourant directement pour fermer en cas d'erreur.
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
     * Établissements qu'un compte du personnel a le droit de voir, indépendamment de ce
     * qu'il a choisi dans l'en-tête — ou null pour le Super Admin, qui les voit tous.
     *
     * Lus dans ses affectations actives et non échues (même condition que
     * RhUser::societesAdministrees) : une affectation Admin Société ouvre TOUS les
     * établissements de cette société ; toute autre (Admin Établissement, Secrétaire,
     * Comptable…) ouvre l'établissement affecté, et lui seul.
     *
     * Sans compte identifié, rien. Une erreur de lecture n'est PAS avalée ici : elle
     * remonte à l'appelant, qui doit fermer (etablissementsVisibles le fait).
     */
    public static function etablissementsAutorises(?RhUser $user = null): ?array
    {
        $user ??= auth()->user();

        if (! $user instanceof RhUser) {
            return [];
        }
        if ($user->isSuperAdmin()) {
            return null;
        }

        $affectations = DB::connection('ecoprim')
            ->table('console_affectations as a')
            ->join('console_roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.rh_user_id', $user->Id)
            ->where('a.actif', true)
            ->where(fn ($q) => $q->whereNull('a.date_fin')
                ->orWhere('a.date_fin', '>=', now()->toDateString()))
            ->get(['a.societe_code', 'a.etablissement_code', 'r.code']);

        $codes = [];
        foreach ($affectations as $a) {
            // Reconnu par son code seul, à la casse près, comme dans RhUser : un nom se
            // choisit librement et ne doit rien ouvrir.
            $adminSociete = trim((string) $a->code) === RhUser::ROLE_ADMIN_SOCIETE;
            $societe = trim((string) $a->societe_code);

            if ($adminSociete && $societe !== '') {
                array_push($codes, ...self::etablissementsDeSociete($societe));
            } else {
                $codes[] = trim((string) $a->etablissement_code);
            }
        }

        // L'établissement de rattachement du compte (RH_USER.Etab), posé par un administrateur
        // dans la console, ouvre lui aussi cet établissement. Sans cela, un compte rattaché
        // mais sans rôle d'affectation arrivait sur « Aucun établissement ».
        $rattachement = trim((string) ($user->Etab ?? ''));
        if ($rattachement !== '') {
            $codes[] = $rattachement;
        }

        return array_values(array_unique(array_filter($codes, fn ($c) => $c !== '')));
    }

    private static function parmi(string $code, array $codes): bool
    {
        foreach ($codes as $c) {
            if (self::memeCode($c, $code)) {
                return true;
            }
        }

        return false;
    }

    /** Codes d'établissement comparés comme le fait SQL Server : sans casse ni espaces. */
    public static function memeCode(?string $a, ?string $b): bool
    {
        return mb_strtoupper(trim((string) $a)) === mb_strtoupper(trim((string) $b));
    }

    /**
     * Établissements dont on montre les données, ou null s'il n'y a rien à restreindre.
     *
     * Super Admin :
     *   — un établissement est choisi (et appartient à la société de travail) : lui seul ;
     *   — sinon, une société est choisie : tous SES établissements — et s'il n'y en a
     *     aucun, la liste est vide : une société sans établissement n'a rien à montrer ;
     *   — sinon (vue générale) : aucune restriction. C'est le SEUL cas qui rend null.
     *
     * Tout autre compte du personnel : ses établissements autorisés (affectations),
     * resserrés à la société courante, puis à l'établissement choisi s'il en fait partie.
     * Jamais null : sans affectation, la liste est vide ; si les affectations ou la
     * société ne peuvent être lues, elle est vide aussi (raison 'referentiel_injoignable').
     */
    public static function etablissementsVisibles(): ?array
    {
        if (self::comptePortail()) {
            return null;
        }

        if (self::$visibles !== false) {
            return self::$visibles;
        }

        return self::$visibles = self::calculerVisibles();
    }

    private static function calculerVisibles(): ?array
    {
        try {
            $user = auth()->user();
        } catch (Throwable $e) {
            $user = null;
        }

        if (! $user instanceof RhUser) {
            // Aucun compte identifié : rien à montrer, jamais tout.
            return [];
        }

        $choisi = self::code();

        try {
            $superAdmin = $user->isSuperAdmin();
            $autorises = $superAdmin ? null : self::etablissementsAutorises($user);
            $societe = PerimetreConsole::codeCourant($user);
        } catch (Throwable $e) {
            self::$incident = true;
            Log::error('Périmètre établissement illisible : accès fermé.', [
                'rh_user_id' => $user->Id ?? null,
                'erreur' => $e->getMessage(),
            ]);

            return [];
        }

        if ($superAdmin) {
            if ($societe === null) {
                return $choisi === null ? null : [$choisi];
            }

            $deLaSociete = self::etablissementsDeSociete($societe);

            // Un établissement choisi avant de changer de société n'en fait plus partie : il
            // ne doit pas continuer à afficher les données d'une autre société.
            return ($choisi !== null && self::parmi($choisi, $deLaSociete)) ? [$choisi] : $deLaSociete;
        }

        if ($autorises === []) {
            self::$sansAffectation = true;

            return [];
        }

        // La société courante (Admin Société, Admin Établissement) resserre le périmètre ;
        // un compte sans rôle d'administration n'en a pas : ses affectations suffisent.
        if ($societe !== null) {
            $autorises = array_values(array_intersect($autorises, self::etablissementsDeSociete($societe)));
        }

        // Un établissement choisi hors de ce périmètre (resté en session d'un autre compte,
        // ou d'une autre société) est ignoré : il ne doit jamais l'élargir.
        return ($choisi !== null && self::parmi($choisi, $autorises)) ? [$choisi] : $autorises;
    }

    private static function resoudre(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $visibles = self::etablissementsVisibles();

        if (self::$incident) {
            return self::$cache = self::etat([], 0, false, 'referentiel_injoignable');
        }

        try {
            $classes = ContexteScolaire::appliquer(
                DB::connection('economat')->table('T_CLASSE'), 'ANNEE'
            )->get(['CodeClasse', 'CODEETABLISSEMENT']);

            // Sans la colonne, aucune classe ne pourrait être rattachée à un établissement :
            // c'est une panne du référentiel, pas un établissement vide.
            if ($classes->isNotEmpty() && ! property_exists($classes->first(), 'CODEETABLISSEMENT')) {
                throw new \RuntimeException('T_CLASSE ne porte pas de colonne CODEETABLISSEMENT.');
            }

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
            Log::error('Classes illisibles pour le périmètre établissement.', ['erreur' => $e->getMessage()]);

            // Référentiel illisible : on FERME dès qu'une restriction était due — un écran
            // vide plutôt que les données de tous les établissements. Seule la vue générale
            // du Super Admin (aucune restriction demandée) reste sans filtre. `raison` le dit
            // dans la réponse du tableau de bord (champ `perimetre`) ; c'est à l'écran d'en
            // faire un message.
            return self::$cache = self::etat($visibles === null ? null : [], 0, false, 'referentiel_injoignable');
        }

        // Une société compte un ou plusieurs établissements, et chacun n'affiche que SES
        // données. Un établissement sans classe cette année n'a donc rien à montrer :
        // la liste vide s'applique telle quelle (zéro élève, zéro classe), au lieu de
        // retomber sur les chiffres de toute la société — ce que faisait l'ancienne règle,
        // au prix d'un avertissement que l'utilisateur ne voulait pas.
        return self::$cache = self::etat($codes, $orphelines, true, self::$sansAffectation ? 'aucune_affectation' : null);
    }

    private static function etat(?array $codes, int $orphelines, bool $applique, ?string $raison): array
    {
        return compact('codes', 'orphelines', 'applique', 'raison');
    }
}
