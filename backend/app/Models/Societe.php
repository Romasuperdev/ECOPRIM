<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Societe extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code', 'nom', 'sigle', 'logo',
        'adresse', 'adresse_ligne2', 'code_postal', 'ville', 'pays',
        'telephone', 'fax', 'email', 'site_web',
        'activite_principale', 'activite_secondaire', 'forme_juridique', 'regime_fiscal', 'capital',
        'representant_civilite', 'representant_nom', 'representant_fonction', 'representant_telephone', 'representant_mobile',
        'statut',
    ];

    protected $casts = [
        'capital' => 'decimal:2',
    ];

    public function etablissements()
    {
        return $this->hasMany(Etablissement::class);
    }

    public function affectations()
    {
        return $this->hasMany(Affectation::class);
    }
}
