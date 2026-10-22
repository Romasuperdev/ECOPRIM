<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Écriture contrôlée dans dbmasterbacou.societe_utilisateur — le rattachement d'un compte
 * à une société, condition n° 2 de la connexion (« Ce compte n'est rattaché à aucune
 * société »).
 *
 * C'est la seule table de la plateforme qu'ECOPRIM écrit en dehors de RH_USER : sans elle,
 * un administrateur devait passer par une autre application pour rattacher un compte.
 *
 * Une ligne = (compte, société). `user_id` porte RH_USER.Id — pas RH_USER.user_id, qui
 * désigne autre chose (la table `users` des rôles) ; les données réelles le confirment.
 * `societe_numauto` recopie US_SOCIETE.NUMAUTO, la valeur que la page de connexion envoie.
 *
 * Contrairement à RH_USER, on SUPPRIME ici : une ligne de liaison n'a pas d'existence
 * propre, la détacher est la seule façon de retirer l'accès. Le compte, lui, reste.
 */
class SocieteUtilisateurEcrivain
{
    private function table()
    {
        return DB::connection('master')->table('societe_utilisateur');
    }

    /**
     * Rattache un compte à chacune des sociétés données. Une liaison déjà présente n'est
     * pas dupliquée : seul son statut d'administrateur est mis à jour.
     *
     * @param  string[]  $codesSocietes
     * @return int Nombre de liaisons créées (les mises à jour ne comptent pas).
     */
    public function rattacher(int $userId, array $codesSocietes, bool $adminSociete): int
    {
        $compte = DB::connection('master')->table('RH_USER')->where('Id', $userId)->first();
        if (! $compte || filter_var($compte->Supprimer, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['utilisateur_id' => ['Ce compte est introuvable ou désactivé.']]);
        }

        $codes = collect($codesSocietes)->map(fn ($c) => trim((string) $c))->filter()->unique()->values();
        $societes = DB::connection('master')->table('US_SOCIETE')
            ->whereIn('CODESOCIETE', $codes)->pluck('NUMAUTO', 'CODESOCIETE');

        $inconnues = $codes->reject(fn ($c) => $societes->has($c));
        if ($inconnues->isNotEmpty()) {
            throw ValidationException::withMessages([
                'societes' => ['Société inconnue : '.$inconnues->implode(', ').'.'],
            ]);
        }

        $crees = 0;
        DB::connection('master')->transaction(function () use ($userId, $codes, $societes, $adminSociete, &$crees) {
            foreach ($codes as $code) {
                $existante = $this->table()->where('user_id', $userId)->where('societe_id', $code)->first();

                if ($existante) {
                    $this->table()->where('id', $existante->id)->update([
                        'EstAdminSociete' => $adminSociete ? 1 : 0,
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                $this->table()->insert([
                    'user_id' => $userId,
                    'societe_id' => $code,
                    'societe_numauto' => $societes[$code] !== null ? (int) $societes[$code] : null,
                    'EstAdminSociete' => $adminSociete ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $crees++;
            }
        });

        return $crees;
    }

    public function definirAdmin(int $id, bool $adminSociete): void
    {
        $this->trouver($id);
        $this->table()->where('id', $id)->update([
            'EstAdminSociete' => $adminSociete ? 1 : 0,
            'updated_at' => now(),
        ]);
    }

    public function detacher(int $id): void
    {
        $this->trouver($id);
        $this->table()->where('id', $id)->delete();
    }

    private function trouver(int $id): object
    {
        $ligne = $this->table()->where('id', $id)->first();
        abort_unless($ligne, 404, 'Rattachement introuvable.');

        return $ligne;
    }
}
