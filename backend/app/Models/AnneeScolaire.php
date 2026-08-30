<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnneeScolaire extends Model
{
    use HasFactory;

    protected $table = 'annees_scolaires';

    protected $fillable = ['libelle', 'date_debut', 'date_fin', 'active', 'cloturee'];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
        'active' => 'boolean',
        'cloturee' => 'boolean',
    ];

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function periodes()
    {
        return $this->hasMany(Periode::class);
    }
}
