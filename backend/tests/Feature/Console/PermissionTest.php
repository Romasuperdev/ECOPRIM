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
        // CM2A appartient à E-ABN, l'établissement des comptes affectés : un devoir ne peut
        // viser qu'une classe du périmètre de qui l'écrit.
        $economat('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2', 'ANNEE' => self::ANNEE,
                'CODEETABLISSEMENT' => 'E-ABN'],
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

    /** Sauf les actions réservées au terrain — voir la section « séparation des tâches ». */
    public function test_un_super_admin_a_la_permission_sans_qu_on_la_lui_accorde(): void
    {
        $rh = $this->compte(1, 'boss', true);

        $this->assertTrue($rh->aLaPermission('creer_devoirs'));
        $this->assertTrue($rh->aLaPermission('gerer_utilisateurs'));
    }

    public function test_un_utilisateur_sans_role_fait_les_traitements_pas_le_parametrage(): void
    {
        $rh = $this->compte(10, 'seul');

        $this->assertFalse($rh->aLaPermission('saisir_notes'));
        $this->assertTrue($rh->aLaPermission('inscrire_eleve'));
        $this->assertTrue($rh->aLaPermission('saisir_absences'));
        $this->assertFalse($rh->aLaPermission('modifier_parametres_ecole'));
        $this->assertFalse($rh->aLaPermission('gerer_coefficients'));
    }

    public function test_l_affectation_sans_role_donne_les_droits_par_defaut(): void
    {
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');
        DB::connection('master')->table('RH_USER')->insert([
            'Id' => 11, 'Login' => 'agent', 'Nom' => 'A', 'Prenom' => 'B', 'Email' => 'a@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);

        $this->postJson('/api/v1/affectations', ['rh_user_id' => 11, 'etablissement_code' => 'E-ABN'])->assertCreated();

        $rh = RhUser::on('master')->find(11);
        $this->assertTrue($rh->estAffecteAUnEtablissement());
        $this->assertFalse($rh->aUnRole());
        $this->assertSame('staff', $rh->typePortail());
        $this->assertTrue($rh->aLaPermission('inscrire_eleve'));
        $this->assertFalse($rh->aLaPermission('modifier_parametres_ecole'));

        // Le rôle technique n'apparaît pas dans le catalogue.
        $codes = collect($this->getJson('/api/v1/roles')->json())->pluck('code');
        $this->assertNotContains('sans-role', $codes);
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

    public function test_l_admin_societe_consulte_les_permissions_sans_pouvoir_les_modifier(): void
    {
        $this->adminSociete('ABN');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');

        $this->getJson("/api/v1/roles/{$roleId}/permissions")->assertOk()
            ->assertJsonPath('accordees', []);

        // Donner des permissions à un rôle revient au seul Super Admin.
        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['inscrire_eleve']])
            ->assertForbidden();
    }

    public function test_le_super_admin_modifie_les_permissions_d_un_role(): void
    {
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');

        $this->putJson("/api/v1/roles/{$roleId}/permissions", [
            'permissions' => ['inscrire_eleve', 'modifier_dossier_eleve'],
        ])->assertOk()->assertJsonCount(2, 'accordees');

        $this->assertDatabaseHas('console_role_permissions', ['role_id' => $roleId, 'permission_code' => 'inscrire_eleve'], 'ecoprim');
    }

    public function test_remplacer_les_permissions_retire_celles_qui_ne_sont_plus_envoyees(): void
    {
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');
        $roleId = $this->role('secretaire', 'Secrétaire', 'ABN');
        $this->accorder($roleId, ['inscrire_eleve', 'gerer_parents']);

        $this->putJson("/api/v1/roles/{$roleId}/permissions", ['permissions' => ['inscrire_eleve']])
            ->assertOk()->assertJsonPath('accordees', ['inscrire_eleve']);
    }

    public function test_un_code_de_permission_inconnu_est_refuse(): void
    {
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');
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

    // --- Compte sans rôle, administrateurs, console ---

    private function sansRole(int $id = 12): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => 'agent'.$id, 'Nom' => 'AGENT', 'Prenom' => 'X', 'Email' => "a{$id}@e.ci",
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false, 'Etab' => 'E-ABN',
        ]);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    public function test_un_compte_sans_role_ne_touche_pas_au_parametrage(): void
    {
        $this->sansRole();
        $this->withSession(['etablissement_code' => 'E-ABN']);

        $this->postJson('/api/v1/classes', ['code' => 'CP1A', 'nom' => 'CP1 A', 'niveau_code' => 'CM2'])->assertForbidden();
        $this->putJson('/api/v1/classes/CM2A', ['nom' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/parametres/prerequis', ['libelle' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/affectations-enseignants', [])->assertForbidden();
        $this->postJson('/api/v1/niveaux', ['code' => 'X', 'libelle' => 'X'])->assertForbidden();
        // … mais consulte, et fait les traitements.
        $this->getJson('/api/v1/classes')->assertOk();
        $this->assertContains('inscrire_eleve', $this->getJson('/api/v1/me')->json('permissions'));
    }

    public function test_un_administrateur_n_a_pas_la_console_mais_administre_l_application(): void
    {
        $this->adminSociete('ABN');

        $moi = $this->getJson('/api/v1/me')->assertOk();
        $this->assertFalse($moi->json('peut_console'));
        $this->assertTrue($moi->json('administrateur'));
    }

    public function test_les_utilisateurs_de_la_configuration_sont_ceux_de_l_etablissement_de_travail(): void
    {
        DB::connection('ecoprim')->table('console_etablissements')->insert(
            ['id' => 2, 'code' => 'E-ABN2', 'intitule' => 'École Est', 'societe_code' => 'ABN', 'actif' => true]
        );
        $secretaire = $this->role('secretaire', 'Secrétaire');
        $this->compte(20, 'ici');
        $this->affecter(20, $secretaire, 'ABN', 'E-ABN');
        $this->compte(21, 'ailleurs');
        $this->affecter(21, $secretaire, 'ABN', 'E-ABN2');
        DB::connection('master')->table('RH_USER')->insert([
            'Id' => 22, 'Login' => 'rattache', 'Nom' => 'R', 'Prenom' => 'X', 'Email' => 'r@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false, 'Etab' => 'E-ABN',
        ]);

        $this->adminSociete('ABN');
        $this->withSession(['etablissement_code' => 'E-ABN']);

        $logins = collect($this->getJson('/api/v1/utilisateurs?etablissement=courant')->assertOk()->json('data'))->pluck('login');
        $this->assertContains('ici', $logins);
        $this->assertContains('rattache', $logins);
        $this->assertNotContains('ailleurs', $logins);

        $this->getJson('/api/v1/utilisateurs/22')->assertOk();

        // Code explicite (établissement par défaut, non mémorisé en session).
        $this->withSession([]);
        $logins = collect($this->getJson('/api/v1/utilisateurs?etablissement=E-ABN2')->assertOk()->json('data'))->pluck('login');
        $this->assertSame(['ailleurs'], $logins->values()->all());
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

    // --- Séparation des tâches : la saisie des notes échappe aux administrateurs ---

    public function test_un_admin_etablissement_ne_peut_pas_saisir_les_notes(): void
    {
        $roleId = $this->role('admin-etablissement', 'Admin Établissement');
        $rh = $this->compte(10, 'dir');
        $this->affecter(10, $roleId);

        // Il passe tout le reste par son statut d'administrateur...
        $this->assertTrue($rh->aLaPermission('creer_devoirs'));
        // ...mais pas la saisie des notes, qui revient à l'enseignant.
        $this->assertFalse($rh->aLaPermission('saisir_notes'));
    }

    public function test_accorder_la_permission_a_un_administrateur_ne_lui_ouvre_pas_la_saisie(): void
    {
        $roleId = $this->role('admin-etablissement', 'Admin Établissement');
        // On la lui accorde explicitement depuis l'écran Permissions : cela ne suffit pas,
        // c'est une séparation des tâches et non un réglage.
        $this->accorder($roleId, ['saisir_notes']);
        $rh = $this->compte(10, 'dir');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->assertFalse($rh->aLaPermission('saisir_notes'));

        $this->postJson('/api/v1/notes/feuille', [
            'classe' => 'CM2A', 'matiere' => 'MAT', 'session' => 'S1',
            'notes' => [['matricule' => 'EL1', 'note' => 12]],
        ])->assertStatus(403);
    }

    public function test_un_super_admin_non_plus_ne_saisit_pas_les_notes(): void
    {
        $rh = $this->compte(1, 'boss', true);

        $this->assertTrue($rh->aLaPermission('gerer_utilisateurs'));
        $this->assertFalse($rh->aLaPermission('saisir_notes'));
    }

    /**
     * Le cahier journal suit la même séparation des tâches que les notes, et pour une
     * raison plus forte : il témoigne de ce qui a été enseigné, séance par séance. Un
     * cahier qu'un administrateur pourrait compléter ne témoignerait plus de rien.
     */
    public function test_un_administrateur_ne_peut_pas_ecrire_dans_le_cahier_journal(): void
    {
        $roleId = $this->role('admin-etablissement', 'Admin Établissement');
        // Accordée explicitement depuis l'écran Permissions : cela ne suffit pas.
        $this->accorder($roleId, ['saisir_cahier_journal']);
        $rh = $this->compte(10, 'dir');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->assertFalse($rh->aLaPermission('saisir_cahier_journal'));

        $this->postJson('/api/v1/cahier-textes', [
            'classe' => 'CM2A', 'mois' => 'Septembre', 'semaine' => 'S1', 'professeur' => 7,
        ])->assertStatus(403);
    }

    public function test_un_super_admin_non_plus_n_ecrit_pas_dans_le_cahier_journal(): void
    {
        $rh = $this->compte(1, 'boss', true);

        $this->assertTrue($rh->aLaPermission('gerer_utilisateurs'));
        $this->assertFalse($rh->aLaPermission('saisir_cahier_journal'));
    }

    /**
     * La lecture, elle, reste ouverte : le cahier est fait pour être consulté, et c'est
     * même l'intérêt de la direction. Seule l'écriture est réservée.
     */
    public function test_la_direction_consulte_le_cahier_journal_sans_pouvoir_l_ecrire(): void
    {
        $roleId = $this->role('admin-etablissement', 'Admin Établissement');
        $rh = $this->compte(10, 'dir');
        $this->affecter(10, $roleId);
        $this->actingAs($rh, 'sanctum');

        $this->getJson('/api/v1/cahier-textes?classe=CM2A')->assertOk();
        $this->getJson('/api/v1/cahier-textes/referentiels')->assertOk();
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
