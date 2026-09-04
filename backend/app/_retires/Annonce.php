<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Annonce extends Model
{
    protected $table = 'annonces';

    protected $fillable = ['auteur_id', 'classe_id', 'titre', 'contenu'];

    public function auteur()
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }
}
