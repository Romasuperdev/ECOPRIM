<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Société — LECTURE SEULE sur dbmasterbacou.US_SOCIETE (statut via ECO_SOCIETE_SUSPENSION). */
class Societe extends Model
{
    protected $connection = 'master';
    protected $table = 'US_SOCIETE';
    protected $primaryKey = 'CODESOCIETE';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $visible = ['id', 'code', 'nom', 'ville', 'adresse', 'telephone', 'email', 'representant', 'statut'];
    protected $appends = ['id', 'code', 'nom', 'ville', 'adresse', 'telephone', 'email', 'representant', 'statut'];

    public function getIdAttribute() { return $this->attributes['CODESOCIETE'] ?? null; }
    public function getCodeAttribute() { return $this->attributes['CODESOCIETE'] ?? null; }
    public function getNomAttribute() { return $this->attributes['NOMSOCIETE'] ?? null; }
    public function getVilleAttribute() { return $this->attributes['VILLESOCIETE'] ?? null; }
    public function getAdresseAttribute() { return $this->attributes['AD1SOCIETE'] ?? ($this->attributes['ADRESSE'] ?? null); }
    public function getTelephoneAttribute() { return $this->attributes['TELSOCIETE'] ?? null; }
    public function getEmailAttribute() { return $this->attributes['EMAILSOCIETE'] ?? null; }
    public function getRepresentantAttribute() { return $this->attributes['NOMPRENOMREPRESENTANT'] ?? ($this->attributes['REPRESENTANT'] ?? null); }

    public function getStatutAttribute(): string
    {
        $suspendu = DB::connection('master')->table('ECO_SOCIETE_SUSPENSION')
            ->where('CODESOCIETE', $this->attributes['CODESOCIETE'] ?? null)
            ->value('SUSPENDU');

        return $suspendu ? 'inactif' : 'actif';
    }
}
