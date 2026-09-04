<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('Super Admin');
    }

    /**
     * IMPORTANT : on retire volontairement le scope global `perimetre` d'Affectation ici.
     * Ce scope calcule son filtre en appelant allowedSocieteIds()/allowedEtablissementIds() —
     * si cette méthode le laissait actif, toute résolution du périmètre d'un utilisateur non
     * Super Admin reboucle indéfiniment sur elle-même (stack overflow).
     */
    private function affectationsActives()
    {
        return $this->affectations()->withoutGlobalScope('perimetre')
            ->where('actif', true)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhere('date_fin', '>=', now()->toDateString()));
    }

    /**
     * Périmètre — sociétés sur lesquelles l'utilisateur a une affectation directe
     * (typiquement Admin Société). Vide si aucune affectation : on ne voit rien par
     * défaut plutôt que tout (fail closed). Une société désactivée n'est jamais
     * incluse : la suspension d'une société coupe l'accès de ses Admin Société, pas
     * seulement l'affichage dans le menu.
     */
    public function allowedSocieteIds(): array
    {
        return $this->affectationsActives()
            ->whereNotNull('societe_id')
            ->whereHas('societe', fn ($q) => $q->where('statut', '!=', 'inactif'))
            ->pluck('societe_id')->unique()->values()->all();
    }

    /**
     * Périmètre — établissements sur lesquels l'utilisateur a une affectation directe
     * (typiquement Direction / Admin Établissement). Même règle : un établissement
     * désactivé sort du périmètre de ses affectés.
     */
    public function allowedEtablissementIds(): array
    {
        return $this->affectationsActives()
            ->whereNotNull('etablissement_id')
            ->whereHas('etablissement', fn ($q) => $q->withoutGlobalScope('perimetre')->where('statut', '!=', 'inactif'))
            ->pluck('etablissement_id')->unique()->values()->all();
    }
}
