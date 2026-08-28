<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enseignant extends Model
{
    protected $table = 'enseignants';

    protected $fillable = ['matricule', 'nom', 'prenom', 'email', 'telephone', 'user_id', 'statut', 'actif'];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function classesPrincipales()
    {
        return $this->hasMany(Classe::class, 'enseignant_principal_id');
    }

    public function intervenants()
    {
        return $this->hasMany(ClasseMatiereEnseignant::class);
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
