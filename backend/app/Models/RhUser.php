<?php

namespace App\Models;

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
