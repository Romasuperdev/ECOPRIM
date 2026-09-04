<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentEtablissement extends Model
{
    protected $table = 'documents_etablissement';

    protected $fillable = ['nom', 'chemin', 'type_mime', 'taille', 'categorie'];
}
