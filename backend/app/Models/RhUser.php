<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Utilisateur authentifiable en LECTURE SEULE, basé sur dbmasterbacou.RH_USER.
 * ECOPRIM n'a pas de table users locale : l'identité, les rôles et le périmètre sont
 * lus directement dans dbmasterbacou (RH_USER + role_user/roles + societe_utilisateur).
 * Aucune écriture.
 */
class RhUser extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $connection = 'master';

    protected $table = 'RH_USER';

    protected $primaryKey = 'Id';

    protected $keyType = 'int';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    // --- Contrat Authenticatable (le mot de passe est dans MotDePasse) ---

    public function getAuthIdentifierName(): string
    {
        return 'Id';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->MotDePasse;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Pas de "remember me" : aucune écriture.
    }

    public function getRememberTokenName()
    {
        return null;
    }

    // --- Statut ---

    public function estSupprime(): bool
    {
        return filter_var($this->Supprimer, FILTER_VALIDATE_BOOLEAN);
    }

    // --- Rôles (dérivés de dbmasterbacou, lecture seule) ---

    /**
     * Rôles de l'utilisateur : le bit SuperAdmin donne « Super Admin », plus les rôles
     * réels rattachés via role_user (join sur RH_USER.user_id → users.id → role_user).
     */
    public function getRoleNames(): Collection
    {
        $roles = collect();

        if (filter_var($this->SuperAdmin, FILTER_VALIDATE_BOOLEAN)) {
            $roles->push('Super Admin');
        }

        if ($this->user_id) {
            $noms = DB::connection('master')
                ->table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('role_user.user_id', $this->user_id)
                ->pluck('roles.name');

            $roles = $roles->merge($noms);
        }

        return $roles->filter()->unique()->values();
    }

    public function hasRole($roles): bool
    {
        $wanted = is_array($roles) ? $roles : explode('|', (string) $roles);

        return $this->getRoleNames()->intersect($wanted)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return filter_var($this->SuperAdmin, FILTER_VALIDATE_BOOLEAN)
            || $this->getRoleNames()->contains('Super Admin');
    }

    /**
     * Périmètre — codes sociétés autorisés (societe_utilisateur).
     * Un Super Admin voit tout (tableau vide = pas de filtre appliqué en amont).
     *
     * `societe_utilisateur.user_id` porte RH_USER.Id, et non RH_USER.user_id (qui pointe
     * vers la table `users` des rôles) : les deux se confondent pour certains comptes et
     * pas pour d'autres, si bien que l'ancienne jointure prêtait à un compte les sociétés
     * d'un autre.
     */
    public function allowedSocieteIds(): array
    {
        return DB::connection('master')
            ->table('societe_utilisateur')
            ->where('user_id', $this->Id)
            ->pluck('societe_id')
            ->all();
    }

    // --- Console : les deux sortes d'administrateur ---

    /**
     * Codes des rôles d'administration au catalogue console_roles.
     *
     * Ces rôles ne se reconnaissent QUE par leur code, jamais par leur nom : un nom se
     * choisit librement à la création d'un rôle de société, et la collation de SQL Server
     * ignore la casse — un rôle baptisé « ADMIN SOCIÉTÉ » par un Admin Établissement
     * passait pour le vrai. La création d'un rôle portant l'un de ces codes, ou un nom qui
     * y ressemble, est refusée (voir Role::ressembleARoleAdministration).
     *
     * `super-admin` figure au catalogue pour l'affichage, mais n'ouvre rien : le Super
     * Admin se lit sur RH_USER (bit SuperAdmin), pas dans une affectation de la console.
     */
    public const ROLE_SUPER_ADMIN = 'super-admin';
    public const ROLE_ADMIN_SOCIETE = 'admin-societe';
    public const ROLE_ADMIN_ETABLISSEMENT = 'admin-etablissement';

    /** Liste fermée des codes réservés à l'administration. */
    public const ROLES_ADMINISTRATION = [self::ROLE_SUPER_ADMIN, self::ROLE_ADMIN_SOCIETE, self::ROLE_ADMIN_ETABLISSEMENT];

    /**
     * Codes des sociétés que l'utilisateur administre, lus dans les affectations propres
     * à NEXORA (console_affectations + console_roles).
     *
     * « Fail closed » assumé : aucune affectation résolue = aucune société, jamais un
     * repli « si vide, alors tout ». Un Super Admin ne passe pas par ici : il administre
     * toutes les sociétés, ce que dit isSuperAdmin().
     *
     * Une affectation ne compte que si elle est active et non échue, pour qu'un accès
     * retiré ou daté cesse de lui-même.
     */
    public function societesAdministrees(): array
    {
        // Base console absente (migrations non passées) : personne n'est admin société.
        return $this->codesAdministres(self::ROLE_ADMIN_SOCIETE, 'societe_code');
    }

    /**
     * Codes (société ou établissement, selon `$colonne`) des affectations actives et non
     * échues portant EXACTEMENT le rôle d'administration `$codeRole`.
     *
     * La comparaison du code est refaite en PHP, à la casse près : sous une collation
     * insensible à la casse, `r.code = 'admin-societe'` reconnaîtrait aussi un
     * « ADMIN-SOCIETE » créé à côté. Fail closed : lecture impossible = aucun code.
     */
    private function codesAdministres(string $codeRole, string $colonne): array
    {
        try {
            return DB::connection('ecoprim')
                ->table('console_affectations as a')
                ->join('console_roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')
                    ->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->where('r.code', $codeRole)
                ->whereNotNull('a.'.$colonne)
                ->get(['r.code as role_code', 'a.'.$colonne.' as valeur'])
                ->filter(fn ($l) => (string) $l->role_code === $codeRole)
                ->map(fn ($l) => trim((string) $l->valeur))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function estAdminSociete(): bool
    {
        return ! $this->isSuperAdmin() && $this->societesAdministrees() !== [];
    }

    /**
     * Codes des établissements que l'utilisateur administre à titre d'Admin
     * Établissement — un cran en dessous de l'Admin Société : borné à un ou plusieurs
     * établissements précis, pas à toute une société. Même logique fail-closed que
     * societesAdministrees().
     */
    public function etablissementsAdministres(): array
    {
        return $this->codesAdministres(self::ROLE_ADMIN_ETABLISSEMENT, 'etablissement_code');
    }

    public function estAdminEtablissement(): bool
    {
        return ! $this->isSuperAdmin() && ! $this->estAdminSociete() && $this->etablissementsAdministres() !== [];
    }

    /** Accès à la console, à quelque titre que ce soit (les trois niveaux d'admin). */
    public function peutAccederConsole(): bool
    {
        return $this->isSuperAdmin()
            || $this->societesAdministrees() !== []
            || $this->etablissementsAdministres() !== [];
    }

    /** Accès au niveau « société » de la console (pas le simple Admin Établissement). */
    public function peutAccederNiveauSociete(): bool
    {
        return $this->isSuperAdmin() || $this->estAdminSociete();
    }

    /** Niveaux d'administration, du plus bas au plus haut. */
    public const NIVEAU_AUCUN = 0;
    public const NIVEAU_ADMIN_ETABLISSEMENT = 1;
    public const NIVEAU_ADMIN_SOCIETE = 2;
    public const NIVEAU_SUPER_ADMIN = 3;

    public function niveauAdministration(): int
    {
        return match (true) {
            $this->isSuperAdmin() => self::NIVEAU_SUPER_ADMIN,
            $this->societesAdministrees() !== [] => self::NIVEAU_ADMIN_SOCIETE,
            $this->etablissementsAdministres() !== [] => self::NIVEAU_ADMIN_ETABLISSEMENT,
            default => self::NIVEAU_AUCUN,
        };
    }

    /**
     * Ce compte peut-il agir sur le compte `$cible` (le modifier, l'activer, le
     * désactiver, changer son mot de passe, lui poser ou retirer une affectation) ?
     *
     * Le périmètre (société, établissement) dit qui est VISIBLE ; il ne dit pas qui peut
     * être TOUCHÉ. Un administrateur ne gère donc jamais un compte d'un niveau
     * d'administration égal ou supérieur au sien : un Admin Établissement ne touche ni un
     * Admin Société ni un autre Admin Établissement, un Admin Société ni un Super Admin ni
     * un autre Admin Société. Sans cela, il suffisait qu'un administrateur plus élevé soit
     * visible chez lui pour qu'il réinitialise son mot de passe et se connecte à sa place.
     *
     * Le Super Admin gère tout ; chacun gère son propre compte.
     */
    public function peutGererCompte(RhUser $cible): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ((int) $cible->Id === (int) $this->Id) {
            return true;
        }

        if ($cible->isSuperAdmin()) {
            return false;
        }

        $niveau = $this->niveauAdministration();

        return $niveau > self::NIVEAU_AUCUN && $cible->niveauAdministration() < $niveau;
    }

    /**
     * Autorise une action précise du catalogue App\Support\Permissions, indépendamment
     * des trois niveaux d'administration de la console.
     *
     * Un Super Admin, un Admin Société et un Admin Établissement passent toujours : ils
     * administrent déjà tout ce périmètre par ailleurs, leur demander en plus une
     * permission cochée quelque part serait un verrou qu'eux-mêmes devraient lever pour
     * leur propre compte. Les autres (Enseignant, Secrétaire, Comptable...) ne passent que
     * si l'un de leurs rôles actifs accorde ce code.
     *
     * UNE exception, dans l'autre sens : les actions de
     * Permissions::INTERDITES_AUX_ADMINISTRATEURS leur sont fermées en dur — la saisie des
     * notes revient à l'enseignant, la direction la consulte. Se l'accorder depuis l'écran
     * Permissions ne suffit pas non plus : c'est une séparation des tâches, pas un réglage.
     */
    /**
     * Les permissions effectives de ce compte — ce que l'écran doit lui proposer. Fournie
     * au front (voir AuthController::userPayload) pour qu'il ne montre pas une action qui
     * finira en refus ; l'autorisation réelle reste décidée ici, à chaque appel.
     */
    public function permissions(): array
    {
        // Une seule requête, là où aLaPermission() en fait une ciblée : cette liste est
        // calculée à chaque chargement de l'application, la parcourir code par code
        // ferait autant d'allers-retours que le catalogue compte d'entrées.
        if ($this->isSuperAdmin() || $this->estAdminSociete() || $this->estAdminEtablissement()) {
            return array_values(array_diff(Permissions::codes(), Permissions::INTERDITES_AUX_ADMINISTRATEURS));
        }

        try {
            if (! $this->aUnRole()) {
                return Permissions::SANS_ROLE;
            }

            return DB::connection('ecoprim')->table('console_affectations as a')
                ->join('console_role_permissions as p', 'p.role_id', '=', 'a.role_id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->pluck('p.permission_code')->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Le compte a-t-il au moins un VRAI rôle actif (le rôle technique « sans rôle » ne
     * compte pas) ? Sans rôle, il reçoit les droits par défaut Permissions::SANS_ROLE.
     * Lecture impossible : exception (l'appelant ferme).
     */
    public function aUnRole(): bool
    {
        return $this->rolesConsoleActifs()
            ->reject(fn ($c) => $c === \App\Models\Console\Role::CODE_SANS_ROLE)
            ->isNotEmpty();
    }

    public function aLaPermission(string $code): bool
    {
        if ($this->isSuperAdmin() || $this->estAdminSociete() || $this->estAdminEtablissement()) {
            return ! Permissions::interditeAuxAdministrateurs($code);
        }

        try {
            if (! $this->aUnRole()) {
                return in_array($code, Permissions::SANS_ROLE, true);
            }

            return DB::connection('ecoprim')->table('console_affectations as a')
                ->join('console_role_permissions as p', 'p.role_id', '=', 'a.role_id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where('p.permission_code', $code)
                ->where(fn ($q) => $q->whereNull('a.date_fin')->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->exists();
        } catch (\Throwable $e) {
            // Base console absente (migrations non passées) : fail closed, jamais ouvert.
            return false;
        }
    }

    // --- Portails restreints : Enseignant et Parent ---

    public const ROLE_ENSEIGNANT = 'enseignant';

    public const ROLE_PARENT = 'parent';

    /** Rôles considérés comme un portail restreint plutôt que l'application complète. */
    private const ROLES_PORTAIL = [self::ROLE_ENSEIGNANT, self::ROLE_PARENT];

    /**
     * Type de portail rendu quand les rôles du compte n'ont pas pu être lus. Aucune route
     * ne l'accepte (PortailMiddleware n'ouvre que staff/enseignant/parent) : le compte
     * n'accède à rien tant que la lecture échoue, et la connexion est refusée
     * (AuthController::login).
     */
    public const PORTAIL_INDISPONIBLE = 'indisponible';

    /**
     * Codes des rôles console actifs et non échus de l'utilisateur, tous niveaux et
     * toutes sociétés confondus — même condition « actif + non échu » que
     * societesAdministrees()/etablissementsAdministres().
     *
     * Une lecture impossible LÈVE une exception au lieu de rendre une liste vide :
     * « aucune affectation » donne l'application complète (comptes historiques), si bien
     * qu'assimiler « illisible » à « vide » ouvrait l'application complète à un parent
     * dès que la base console ne répondait pas.
     */
    /**
     * Le compte est-il affecté à au moins un établissement ? Condition pour se connecter
     * à l'application comme à la console — sauf pour le Super Admin.
     *
     * Vaut affectation : un établissement de rattachement (RH_USER.Etab) ou une
     * affectation console active et non échue (établissement, ou société pour un Admin
     * Société). Lecture impossible : exception, à l'appelant de décider.
     */
    public function estAffecteAUnEtablissement(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if (trim((string) ($this->Etab ?? '')) !== '') {
            return true;
        }

        return DB::connection('ecoprim')->table('console_affectations')
            ->where('rh_user_id', $this->Id)
            ->where('actif', true)
            ->where(fn ($q) => $q->whereNull('date_fin')
                ->orWhere('date_fin', '>=', now()->toDateString()))
            ->where(fn ($q) => $q->where(fn ($e) => $e->whereNotNull('etablissement_code')->where('etablissement_code', '<>', ''))
                ->orWhere(fn ($s) => $s->whereNotNull('societe_code')->where('societe_code', '<>', '')))
            ->exists();
    }

    public const MESSAGE_SANS_ETABLISSEMENT = "Ce compte n'est affecté à aucun établissement. Demandez à votre administrateur de l'affecter à un établissement.";

    public function rolesConsoleActifs(): Collection
    {
        return DB::connection('ecoprim')
            ->table('console_affectations as a')
            ->join('console_roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.rh_user_id', $this->Id)
            ->where('a.actif', true)
            ->where(fn ($q) => $q->whereNull('a.date_fin')
                ->orWhere('a.date_fin', '>=', now()->toDateString()))
            ->pluck('r.code')
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Type de portail : 'staff' (application complète, comportement historique
     * inchangé) ou 'enseignant'/'parent' pour un compte dont TOUS les rôles actifs
     * sont EXACTEMENT ce rôle restreint.
     *
     * Au moindre rôle hors de {enseignant, parent} — ou en l'absence de toute
     * affectation console, le cas de tous les comptes historiques jamais repris dans
     * la console — l'accès complet est conservé : rien ne se restreint sans un geste
     * explicite de l'administrateur (affecter EXCLUSIVEMENT ce rôle).
     *
     * Rôles illisibles (base console injoignable) : PORTAIL_INDISPONIBLE, que nulle
     * route n'accepte. « Je ne sais pas » ne vaut jamais « application complète ».
     */
    public function typePortail(): string
    {
        if ($this->isSuperAdmin()) {
            return 'staff';
        }

        try {
            $roles = $this->rolesConsoleActifs();
        } catch (\Throwable $e) {
            Log::error("Rôles console illisibles pour le compte {$this->Id} : accès refusé.", [
                'erreur' => $e->getMessage(),
            ]);

            return self::PORTAIL_INDISPONIBLE;
        }

        // Le rôle technique « sans rôle » ne fait ni portail ni application complète.
        $roles = $roles->reject(fn ($c) => $c === \App\Models\Console\Role::CODE_SANS_ROLE)->values();

        if ($roles->isEmpty() || $roles->diff(self::ROLES_PORTAIL)->isNotEmpty()) {
            return 'staff';
        }

        return $roles->contains(self::ROLE_ENSEIGNANT) ? 'enseignant' : 'parent';
    }

    /**
     * Matricules des enfants rattachés à ce compte (rôle Parent), lus dans
     * console_affectation_eleves via ses affectations actives et non échues.
     * Fail closed : aucune affectation Parent résolue = aucun enfant, jamais un repli
     * « si vide, tout montrer ».
     */
    public function enfantsMatricules(): array
    {
        try {
            return DB::connection('ecoprim')
                ->table('console_affectations as a')
                ->join('console_roles as r', 'r.id', '=', 'a.role_id')
                ->join('console_affectation_eleves as e', 'e.affectation_id', '=', 'a.id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')
                    ->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->where('r.code', self::ROLE_PARENT)
                ->pluck('e.eleve_matricule')
                ->map(fn ($m) => trim((string) $m))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Droit d'administrer une société donnée. Le Super Admin les administre toutes. */
    public function peutAdministrerSociete(?string $code): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $code = trim((string) $code);

        return $code !== '' && in_array($code, $this->societesAdministrees(), true);
    }

    /**
     * Droit d'administrer un établissement donné : le Super Admin et l'Admin Société
     * (sur sa société) l'ont de plein droit ; l'Admin Établissement seulement sur ceux
     * qui lui sont affectés.
     */
    public function peutAdministrerEtablissement(?string $etablissementCode, ?string $societeCode = null): bool
    {
        if ($this->isSuperAdmin() || $this->peutAdministrerSociete($societeCode)) {
            return true;
        }

        $code = trim((string) $etablissementCode);

        return $code !== '' && in_array($code, $this->etablissementsAdministres(), true);
    }
}
