<?php

namespace App\Models;

use App\Support\Permissions;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
     * Périmètre — codes sociétés autorisés (societe_utilisateur via user_id).
     * Un Super Admin voit tout (tableau vide = pas de filtre appliqué en amont).
     */
    public function allowedSocieteIds(): array
    {
        if (! $this->user_id) {
            return [];
        }

        return DB::connection('master')
            ->table('societe_utilisateur')
            ->where('user_id', $this->user_id)
            ->pluck('societe_id')
            ->all();
    }

    // --- Console : les deux sortes d'administrateur ---

    /** Codes des rôles d'administration au catalogue console_roles. */
    public const ROLE_ADMIN_SOCIETE = 'admin-societe';
    public const ROLE_ADMIN_ETABLISSEMENT = 'admin-etablissement';

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
        try {
            return DB::connection('ecoprim')
                ->table('console_affectations as a')
                ->join('console_roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')
                    ->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->where(fn ($q) => $q->where('r.code', self::ROLE_ADMIN_SOCIETE)
                    ->orWhere('r.nom', 'Admin Société'))
                ->whereNotNull('a.societe_code')
                ->pluck('a.societe_code')
                ->map(fn ($c) => trim((string) $c))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            // Base console absente (migrations non passées) : personne n'est admin société.
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
        try {
            return DB::connection('ecoprim')
                ->table('console_affectations as a')
                ->join('console_roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.rh_user_id', $this->Id)
                ->where('a.actif', true)
                ->where(fn ($q) => $q->whereNull('a.date_fin')
                    ->orWhere('a.date_fin', '>=', now()->toDateString()))
                ->where(fn ($q) => $q->where('r.code', self::ROLE_ADMIN_ETABLISSEMENT)
                    ->orWhere('r.nom', 'Admin Établissement'))
                ->whereNotNull('a.etablissement_code')
                ->pluck('a.etablissement_code')
                ->map(fn ($c) => trim((string) $c))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
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

    public function aLaPermission(string $code): bool
    {
        if ($this->isSuperAdmin() || $this->estAdminSociete() || $this->estAdminEtablissement()) {
            return ! Permissions::interditeAuxAdministrateurs($code);
        }

        try {
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
     * Codes des rôles console actifs et non échus de l'utilisateur, tous niveaux et
     * toutes sociétés confondus — même condition « actif + non échu » que
     * societesAdministrees()/etablissementsAdministres().
     */
    public function rolesConsoleActifs(): Collection
    {
        try {
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
        } catch (\Throwable $e) {
            return collect();
        }
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
     */
    public function typePortail(): string
    {
        if ($this->isSuperAdmin()) {
            return 'staff';
        }

        $roles = $this->rolesConsoleActifs();
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
