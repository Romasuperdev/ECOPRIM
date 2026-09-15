<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Permissions accordées à un rôle (console_role_permissions), et leur application réelle
 * (App\Http\Middleware\EnsurePermission, RhUser::aLaPermission) — distinct des trois
 * niveaux d'administration de la console, qui passent toujours.
 */
class PermissionTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        Artisan::call('migrate', [
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);

        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);

        $eco('console_societes')->insert([
            ['id' => 1, 'code' => 'ABN', 'nom' => 'Groupe ABN', 'actif' => true],
            ['id' => 2, 'code' => 'SUD', 'nom' => 'Groupe Sud', 'actif' => true],
        ]);
        $eco('console_etablissements')->insert([
            ['id' => 1, 'code' => 'E-ABN', 'intitule' => 'École Nord', 'societe_code' => 'ABN', 'actif' => true],
        ]);

        $economat = fn (string $t) => DB::connection('economat')->table($t);
        $economat('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
        ]);
        $economat('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2', 'ANNEE' => self::ANNEE],
        ]);
        $economat('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MAT', 'LibelleMatiere' => 'Mathématiques'],
        ]);
    }

    private function compte(int $id, string $login, bool $superAdmin = false): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'MotDePasse' => Hash::make('x'),
            'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ]);
    }

    private function role(string $code, string $nom, ?string $societe = 'ABN'): int
    {
        return DB::connection('ecoprim')->table('console_roles')
            ->insertGetId(['code' => $code, 'nom' => $nom, 'societe_code' => $societe]);
    }

    private function affecter(int $rhUserId, int $roleId, string $societe = 'ABN', string $etab = 'E-ABN'): void
    {
        DB::connection('ecoprim')->table('console_affectations')->insert([
            'rh_user_id' => $rhUserId, 'societe_code' => $societe,
            'etablissement_code' => $etab, 'role_id' => $roleId, 'actif' => true,
        ]);
    }

    private function accorder(int $roleId, array $codes): void
    {
        foreach ($codes as $code) {
            DB::connection('ecoprim')->table('console_role_permissions')
                ->insert(['role_id' => $roleId, 'permission_code' => $code]);
        }
    }

    private function adminSociete(string $societe = 'ABN'): RhUser
    {
        $roleId = $this->role('admin-societe', 'Admin Société', $societe);
        $rh = $this->compte(2, 'admin');
        $this->affecter(2, $roleId, $societe);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    // --- RhUser::aLaPermission() ---

    public function test_un_super_admin_a_toujours_la_permission(): void
    {
        $rh = $this->compte(1, 'boss', true);

        $this->assertTrue($rh->aLaPermission('saisir_notes'));
        $this->assertTrue($rh->aLaPermission('gerer_utilisateurs'));
    }

    public function test_un_utilisateur_sans_affectation_n_a_aucune_permission(): void
    {
        $rh = $this->compte(10, 'seul');

        $this->assertFalse($rh->aLaPermission('saisir_notes'));
    }

    public function test_un_role_avec_la_permission_accordee_passe(): void
    {
        $roleId = $this->role('enseignant', 'Enseignant');
        $this->accorder($roleId, ['saisir_notes', 'creer_devoirs']);
        $rh = $this->compte(10, 'ens');
        $this->affecter(10, $roleId);

        $this->assertTrue($rh->aLaPermission('saisir_notes'));
        $this->assertTrue($rh->aLaPermission('creer_devoirs'));
        $this->assertFalse($rh->aLaPermission('gerer_utilisateurs'));
    }

    public function test_une_affectation_inactive_ne_donne_pas_la_permission(): void
    {
        $roleId = $this->role('enseignant', 'Enseignant');
        $this->accorder($roleId, ['saisir_notes']);
        $rh = $this->compte(10, 'ens');
        DB::connection('ecoprim')->table('console_affectations')->insert([
            'rh_user_id' => 10, 'societe_code' => 'ABN', 'etablissement_code' => 'E-ABN',
            'role_id' => $roleId, 'actif' => false,
        ]);

        $this->assertFalse($rh->aLaPermission('saisir_notes'));
    }

    // --- Gestion des permissions d'un rôle (RoleController) ---

    public function test_l_admin_societe_consulte_et_modifie_les_permissions_d_un_role_de_sa_societe(): void
    {
        $this->adminSociete('ABN');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');

        $this->getJson("/api/v1/roles/{$roleId}/permissions")->assertOk()
            ->assertJsonPath('accordees', []);

        $this->putJson("/api/v1/roles/{$roleId}/permissions", [
            'permissions' => ['inscrire_eleve', 'modifier_dossier_eleve'],
        ])->assertOk()->assertJsonCount(2, 'accordees');

        $this->assertDatabaseHas('console_role_permissions', ['role_id' => $roleId, 'permission_code' => 'inscrire_eleve'], 'ecoprim');
    }

    public function test_remplacer_les_permissions_retire_celles_qui_ne_sont_plus_envoyees(): void
    {
        $this->adminSociete('ABN');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');
        $this->accorder($roleId, ['inscrire_eleve', 'gerer_parents']);

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['inscrire_eleve']])
            ->assertOk()->assertJsonPath('accordees', ['inscrire_eleve']);
    }

    public function test_un_code_de_permission_inconnu_est_refuse(): void
    {
        $this->adminSociete('ABN');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['fantome']])
            ->assertStatus(422)->assertJsonValidationErrors('permissions.0');
    }

    public function test_l_admin_societe_ne_peut_pas_gerer_les_permissions_d_un_role_d_une_autre_societe(): void
    {
        $this->adminSociete('ABN');
        $roleId = $this->role('secretaire', 'Secrétaire', 'SUD');

        $this->getJson("/api/v1/roles/{$roleId}/permissions")->assertStatus(403);
        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => []])->assertStatus(403);
    }

    // --- Application réelle sur une route protégée ---

    // Le code de rôle 'enseignant' est réservé (RhUser::ROLE_ENSEIGNANT) : il confine son
    // titulaire au portail restreint (typePortail()), qui n'atteint jamais ces routes-ci
    // (portail:staff). Un rôle « métier » soumis aux permissions, comme Secrétaire ou un
    // profil pédagogique à accès complet, porte donc un autre code — 'pedagogie' ici.

    public function test_un_role_sans_la_permission_ne_peut_pas_creer_de_devoir(): void
    {
        $roleId = $this->role('pedagogie', 'Pédagogie');
        $rh = $this->compte(10, 'ens');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->postJson('/api/v1/devoirs', [
            'titre' => 'Exercices', 'classe' => 'CM2A', 'matiere' => 'MAT', 'date_remise' => '2026-10-15',
        ])->assertStatus(403);
    }

    public function test_un_role_avec_la_permission_peut_creer_un_devoir(): void
    {
        $roleId = $this->role('pedagogie', 'Pédagogie');
        $this->accorder($roleId, ['creer_devoirs']);
        $rh = $this->compte(10, 'ens');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->assertTrue($rh->fresh()->aLaPermission('creer_devoirs'));

        $this->postJson('/api/v1/devoirs', [
            'titre' => 'Exercices', 'classe' => 'CM2A', 'matiere' => 'MAT', 'date_remise' => '2026-10-15',
        ])->assertCreated();
    }

    public function test_un_admin_etablissement_peut_creer_un_devoir_sans_permission_explicite(): void
    {
        $roleId = $this->role('admin-etablissement', 'Admin Établissement');
        $rh = $this->compte(10, 'dir');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->postJson('/api/v1/devoirs', [
            'titre' => 'Exercices', 'classe' => 'CM2A', 'matiere' => 'MAT', 'date_remise' => '2026-10-15',
        ])->assertCreated();
    }
}
