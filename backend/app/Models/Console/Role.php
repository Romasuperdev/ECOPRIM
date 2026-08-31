<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;

/** Catalogue de rôles ECOPRIM (affectés au niveau utilisateur↔établissement). */
class Role extends Model
{
    protected $connection = 'ecoprim';
    protected $table = 'console_roles';

    protected $fillable = ['code', 'nom'];
}
