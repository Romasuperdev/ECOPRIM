<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPerimetre;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class Affectation extends Model
{
    use HasFactory, BelongsToPerimetre;

    protected $fillable = [
        'user_id', 'societe_id', 'etablissement_id', 'role_id', 'date_debut', 'date_fin', 'actif',
    ];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
        'actif' => 'boolean',
    ];

    public function scopeForPerimetre($query, array $societeIds, array $etablissementIds)
    {
        if (! $societeIds && ! $etablissementIds) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function ($q) use ($societeIds, $etablissementIds) {
            if ($societeIds) {
                $q->orWhereIn('societe_id', $societeIds)
                    ->orWhereHas('etablissement', fn ($eq) => $eq->whereIn('societe_id', $societeIds));
            }
            if ($etablissementIds) {
                $q->orWhereIn('etablissement_id', $etablissementIds);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function societe()
    {
        return $this->belongsTo(Societe::class);
    }

    public function etablissement()
    {
        return $this->belongsTo(Etablissement::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
