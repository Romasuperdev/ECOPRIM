<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $table = 'documents';

    protected $fillable = ['documentable_type', 'documentable_id', 'nom', 'chemin', 'type_mime', 'taille', 'categorie'];

    public function documentable()
    {
        return $this->morphTo();
    }
}
