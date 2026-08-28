<?php

namespace App\Models;

use App\Models\Concerns\BelongsToPerimetre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Etablissement extends Model
{
    use SoftDeletes, BelongsToPerimetre;

    protected $fillable = [
        'societe_id', 'code', 'nom', 'type', 'logo', 'couleur_primaire', 'couleur_secondaire',
        'adresse', 'telephone', 'email', 'site_web',
        'nom_responsable', 'prenom_responsable', 'fonction_responsable', 'contact_responsable', 'email_directeur',
        'sous_prefecture', 'circonscription', 'code_iep', 'intitule_iep', 'code_dren', 'intitule_dren',
        'statut',
    ];

    /**
     * Filtre appliqué par le scope global BelongsToPerimetre : une société ne voit que
     * ses propres établissements, un établissement ne voit que lui-même.
     */
    public function scopeForPerimetre($query, array $societeIds, array $etablissementIds)
    {
        if (! $societeIds && ! $etablissementIds) {
            // Fail closed : aucune affectation résolue = aucune ligne visible.
            return $query->whereRaw('1 = 0');
        }

        $query->where(function ($q) use ($societeIds, $etablissementIds) {
            if ($societeIds) {
                $q->orWhereIn('societe_id', $societeIds);
            }
            if ($etablissementIds) {
                $q->orWhereIn('id', $etablissementIds);
            }
        });
    }

    public function societe()
    {
        return $this->belongsTo(Societe::class);
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class);
    }
}
