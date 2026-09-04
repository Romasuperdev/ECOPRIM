<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParentEleve extends Model
{
    protected $table = 'parents';

    protected $fillable = ['user_id', 'nom', 'prenom', 'telephone', 'email', 'profession', 'adresse'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function eleves()
    {
        return $this->belongsToMany(Eleve::class, 'eleve_parent', 'parent_id', 'eleve_id')
            ->withPivot('lien_parente');
    }
}
