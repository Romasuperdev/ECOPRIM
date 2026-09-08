<?php

namespace App\Models\Console;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un enfant rattaché à une affectation « Parent » (console_affectations). */
class AffectationEleve extends Model
{
    protected $connection = 'ecoprim';

    protected $table = 'console_affectation_eleves';

    protected $fillable = ['affectation_id', 'eleve_matricule'];

    public function affectation(): BelongsTo
    {
        return $this->belongsTo(Affectation::class);
    }
}
