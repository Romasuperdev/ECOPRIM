<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Eleve extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'eleves';

    protected $fillable = [
        'matricule',
        'nom',
        'prenom',
        'date_naissance',
        'numero_acte_naissance',
        'acte_delivre_par',
        'lieu_naissance',
        'pays_naissance',
        'nationalite',
        'sexe',
        'photo',
        'classe_id',
        'statut',
        'redoublant',
        'type_eleve',
        'lv2',
        'situation_familiale',
        'adresse',
        'ville',
        'commune',
        'quartier',
        'region',
        'email',
        'telephone',
    ];

    protected $casts = [
        'date_naissance' => 'date',
        'redoublant' => 'boolean',
    ];

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function parents()
    {
        return $this->belongsToMany(ParentEleve::class, 'eleve_parent', 'eleve_id', 'parent_id')
            ->withPivot('lien_parente');
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function absences()
    {
        return $this->hasMany(Absence::class);
    }

    public function sanctions()
    {
        return $this->hasMany(Sanction::class);
    }

    public function retards()
    {
        return $this->hasMany(Retard::class);
    }

    public function inscriptions()
    {
        return $this->hasMany(Inscription::class);
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
