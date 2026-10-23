<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écriture contrôlée dans dbmasterbacou.US_APPLICATIONS — le catalogue des applications
 * de la suite (CodeApp, Intitule).
 *
 * La table n'a ni clé primaire ni contrainte d'unicité : c'est ici qu'on garantit qu'un
 * code n'existe qu'une fois. Le code ne se modifie pas (les comptes et les sociétés le
 * reprennent) ; seul l'intitulé change.
 *
 * Supprimer n'est permis que pour une application que personne n'utilise : ni une société
 * (US_SOCIETE_APPLICATION), ni un compte (RH_USER.CodeApp, comptes désactivés compris —
 * la donnée y reste). Sinon, refus explicite plutôt que des références orphelines.
 */
class UsApplicationEcrivain
{
    /** Largeurs réelles : CodeApp varchar(50), Intitule varchar(50). */
    public const LARGEURS = ['code' => 50, 'intitule' => 50];

    public function trouver(string $code): ?object
    {
        return $this->table()->where('CodeApp', $code)->first();
    }

    public function existe(string $code): bool
    {
        return $this->table()->where('CodeApp', trim($code))->exists();
    }

    public function creer(string $code, string $intitule): void
    {
        $this->table()->insert(['CodeApp' => trim($code), 'Intitule' => trim($intitule)]);
    }

    public function modifier(string $code, string $intitule): void
    {
        $this->table()->where('CodeApp', $code)->update(['Intitule' => trim($intitule)]);
    }

    /** @throws ValidationException si l'application est encore utilisée */
    public function supprimer(string $code): void
    {
        $societes = DB::connection('master')->table('US_SOCIETE_APPLICATION')->where('CodeApp', $code)->count();
        $comptes = DB::connection('master')->table('RH_USER')->where('CodeApp', $code)->count();

        if ($societes > 0 || $comptes > 0) {
            $raisons = array_filter([
                $societes > 0 ? "{$societes} société(s)" : null,
                $comptes > 0 ? "{$comptes} compte(s)" : null,
            ]);

            throw ValidationException::withMessages([
                'application' => ['Cette application ne peut pas être supprimée : elle est utilisée par '
                    .implode(' et ', $raisons).'. Retirez-la d’abord de ces sociétés et comptes.'],
            ]);
        }

        $this->table()->where('CodeApp', $code)->delete();
    }

    private function table()
    {
        return DB::connection('master')->table('US_APPLICATIONS');
    }
}
