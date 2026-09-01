<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Écriture contrôlée dans dbmasterbacou.RH_USER.
 *
 * Règle : CRÉATION et MODIFICATION uniquement — jamais de DELETE. Un compte retiré du
 * service est désactivé logiquement (Supprimer = 1), comme le fait déjà la connexion
 * qui refuse les comptes marqués supprimés. RH_USER est partagée avec les autres
 * applications de la suite : rien n'y est jamais effacé depuis ECOPRIM.
 *
 * Le mot de passe est haché avec Hash::make (bcrypt), exactement ce que vérifie
 * AuthController via Hash::check.
 */
class RhUserEcrivain
{
    /** Largeurs réelles des colonnes RH_USER. */
    public const LARGEURS = [
        'login' => 50, 'mot_de_passe' => 150, 'matricule' => 50, 'nom' => 50,
        'prenom' => 50, 'etab' => 50, 'email' => 50, 'contact' => 50,
        'profil' => 50, 'code_app' => 50,
    ];

    public function loginExiste(string $login, ?int $sauf = null): bool
    {
        try {
            return $this->table()->where('Login', $login)
                ->when($sauf, fn ($q) => $q->where('Id', '!=', $sauf))
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function creer(array $d): int
    {
        $ligne = $this->colonnes($d) + [
            'MotDePasse' => Hash::make($d['mot_de_passe']),
            'Supprimer' => 0,
            'SuperAdmin' => ! empty($d['super_admin']) ? 1 : 0,
        ];

        if ($this->idEstIdentity()) {
            return (int) $this->table()->insertGetId($ligne, 'Id');
        }

        $id = $this->prochainId();
        $this->table()->insert($ligne + ['Id' => $id]);

        return $id;
    }

    public function modifier(int $id, array $d): void
    {
        $ligne = $this->colonnes($d);

        if (! empty($d['mot_de_passe'])) {
            $ligne['MotDePasse'] = Hash::make($d['mot_de_passe']);
        }
        if (array_key_exists('super_admin', $d)) {
            $ligne['SuperAdmin'] = ! empty($d['super_admin']) ? 1 : 0;
        }

        $this->table()->where('Id', $id)->update($ligne);
    }

    /** Désactivation logique — remplace la suppression, interdite ici. */
    public function definirActif(int $id, bool $actif): void
    {
        $this->table()->where('Id', $id)->update(['Supprimer' => $actif ? 0 : 1]);
    }

    private function colonnes(array $d): array
    {
        $map = [
            'Login' => 'login', 'Nom' => 'nom', 'Prenom' => 'prenom', 'Email' => 'email',
            'Matricule' => 'matricule', 'Etab' => 'etab', 'Contact' => 'contact',
            'Profil' => 'profil', 'CodeApp' => 'code_app',
        ];

        $ligne = [];
        foreach ($map as $colonne => $cle) {
            if (array_key_exists($cle, $d)) {
                $ligne[$colonne] = $d[$cle];
            }
        }

        return $ligne;
    }

    private function idEstIdentity(): bool
    {
        try {
            $v = DB::connection('master')->selectOne(
                "SELECT COLUMNPROPERTY(OBJECT_ID('dbo.RH_USER'),'Id','IsIdentity') AS est_identity"
            );

            return (bool) ($v->est_identity ?? false);
        } catch (Throwable $e) {
            return false; // SQLite des tests : compteur manuel
        }
    }

    private function prochainId(): int
    {
        try {
            return ((int) $this->table()->max('Id')) + 1;
        } catch (Throwable $e) {
            return 1;
        }
    }

    private function table()
    {
        return DB::connection('master')->table('RH_USER');
    }
}
