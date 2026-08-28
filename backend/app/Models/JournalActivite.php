<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalActivite extends Model
{
    const UPDATED_AT = null;

    protected $table = 'journal_activite';

    protected $fillable = [
        'user_id', 'action', 'module', 'objet_type', 'objet_id', 'donnees_avant', 'donnees_apres', 'ip', 'user_agent',
    ];

    protected $casts = [
        'donnees_avant' => 'array',
        'donnees_apres' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
