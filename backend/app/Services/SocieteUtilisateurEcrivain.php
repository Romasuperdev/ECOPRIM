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
        $this->verifierCompte($userId);
        $codes = $this->normaliser($codesSocietes);
        $societes = $this->numautos($codes);

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

    /**
     * Fait de la liste donnée LA liste des sociétés du compte : ce qui manque est créé,
     * ce qui n'y figure plus est détaché, et le statut d'administrateur de chaque liaison
     * suit `$codesAdmin` (un code absent de `$codesSocietes` y est ignoré).
     *
     * C'est le geste du formulaire de la console : on choisit un compte, ses sociétés
     * sont précochées, on coche ou décoche, on enregistre. Une liste vide retire tout.
     *
     * @param  string[]  $codesSocietes
     * @param  string[]  $codesAdmin
     * @return array{crees: int, retires: int}
     */
    public function synchroniser(int $userId, array $codesSocietes, array $codesAdmin = []): array
    {
        $codes = $this->normaliser($codesSocietes);
        $admins = $this->normaliser($codesAdmin)->intersect($codes);

        // Retirer les sociétés d'un compte désactivé reste permis ; lui en donner, non.
        if ($codes->isNotEmpty()) {
            $this->verifierCompte($userId);
        }
        $societes = $this->numautos($codes);

        $bilan = ['crees' => 0, 'retires' => 0];
        DB::connection('master')->transaction(function () use ($userId, $codes, $admins, $societes, &$bilan) {
            $existantes = $this->table()->where('user_id', $userId)->get();

            foreach ($existantes as $ligne) {
                if (! $codes->contains(trim((string) $ligne->societe_id))) {
                    $this->table()->where('id', $ligne->id)->delete();
                    $bilan['retires']++;
                }
            }

            $presentes = $existantes->keyBy(fn ($l) => trim((string) $l->societe_id));
            foreach ($codes as $code) {
                $admin = $admins->contains($code) ? 1 : 0;
                $ligne = $presentes->get($code);

                if ($ligne) {
                    if ((int) ($ligne->EstAdminSociete ?? 0) !== $admin) {
                        $this->table()->where('id', $ligne->id)->update(['EstAdminSociete' => $admin, 'updated_at' => now()]);
                    }

                    continue;
                }

                $this->table()->insert([
                    'user_id' => $userId,
                    'societe_id' => $code,
                    'societe_numauto' => $societes[$code] !== null ? (int) $societes[$code] : null,
                    'EstAdminSociete' => $admin,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $bilan['crees']++;
            }
        });

        return $bilan;
    }

    /**
     * Détache le compte de toutes ses sociétés (« Retirer tout »). Le compte, lui, reste.
     *
     * @return int Nombre de liaisons retirées.
     */
    public function detacherTout(int $userId): int
    {
        return $this->table()->where('user_id', $userId)->delete();
    }

    private function verifierCompte(int $userId): void
    {
        $compte = DB::connection('master')->table('RH_USER')->where('Id', $userId)->first();
        if (! $compte || filter_var($compte->Supprimer, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['utilisateur_id' => ['Ce compte est introuvable ou désactivé.']]);
        }
    }

    private function normaliser(array $codes)
    {
        return collect($codes)->map(fn ($c) => trim((string) $c))->filter()->unique()->values();
    }

    /** CODESOCIETE => NUMAUTO des sociétés données ; refuse toute société inconnue. */
    private function numautos($codes)
    {
        if ($codes->isEmpty()) {
            return collect();
        }

        $societes = DB::connection('master')->table('US_SOCIETE')
            ->whereIn('CODESOCIETE', $codes)->pluck('NUMAUTO', 'CODESOCIETE');

        $inconnues = $codes->reject(fn ($c) => $societes->has($c));
        if ($inconnues->isNotEmpty()) {
            throw ValidationException::withMessages([
                'societes' => ['Société inconnue : '.$inconnues->implode(', ').'.'],
            ]);
        }

        return $societes;
    }

    private function trouver(int $id): object
    {
        $ligne = $this->table()->where('id', $id)->first();
        abort_unless($ligne, 404, 'Rattachement introuvable.');

        return $ligne;
    }
}
