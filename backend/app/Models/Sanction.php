<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sanction extends Model
{
    protected $table = 'sanctions';

    protected $fillable = ['eleve_id', 'date_sanction', 'faute', 'sanction', 'observation'];

    protected $casts = [
        'date_sanction' => 'date',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }
}
