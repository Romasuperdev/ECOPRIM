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
}
