<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;

/** Catalogue de rôles ECOPRIM (affectés au niveau utilisateur↔établissement). */
class Role extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'console_roles';

    protected $fillable = ['code', 'nom', 'societe_code'];

    /**
     * Codes de permission accordés à ce rôle (App\Support\Permissions::CATALOGUE).
     * Pas de relation Eloquent : seul le code de permission (une chaîne, pas un modèle)
     * porte du sens côté pivot `console_role_permissions`.
     */
    public function permissionCodes(): array
    {
        return $this->newQuery()->getConnection()
            ->table('console_role_permissions')
            ->where('role_id', $this->id)
            ->pluck('permission_code')
            ->all();
    }
}
