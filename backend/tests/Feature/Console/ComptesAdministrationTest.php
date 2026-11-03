<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Comptes, rôles et administration : ce qu'un administrateur peut TOUCHER, au-delà de ce
 * qu'il peut voir.
 *
 * RH_USER est commun à toute la suite : un mot de passe changé ici vaut partout. D'où les
 * règles éprouvées ici — on n'affecte qu'un compte déjà dans son périmètre, on ne gère
 * jamais un administrateur de niveau égal ou supérieur, on ne change l'identité que d'un
 * compte entièrement à soi ; les rôles d'administration se reconnaissent à leur code ;
 * un établissement d'ECONOMAT ne change pas de société par la petite porte.
 */
class ComptesAdministrationTest extends TestCase
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
            ['id' => 2, 'code' => 'E-SUD', 'intitule' => 'École Sud', 'societe_code' => 'SUD', 'actif' => true],
            ['id' => 3, 'code' => 'E-ABN2', 'intitule' => 'École Nord 2', 'societe_code' => 'ABN', 'actif' => true],
        ]);
        $eco('console_roles')->insert([
            ['id' => 1, 'code' => RhUser::ROLE_ADMIN_SOCIETE, 'nom' => 'Admin Société'],
            ['id' => 2, 'code' => 'direction', 'nom' => 'Direction'],
            ['id' => 3, 'code' => RhUser::ROLE_ADMIN_ETABLISSEMENT, 'nom' => 'Admin Établissement'],
            ['id' => 4, 'code' => RhUser::ROLE_PARENT, 'nom' => 'Parent'],
            ['id' => 5, 'code' => 'surveillant', 'nom' => 'Surveillant'],
        ]);

        $economat = fn (string $t) => DB::connection('economat')->table($t);
        $economat('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01',
        ]);
        $economat('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E-ABN'],
            ['num' => 2, 'CodeClasse' => 'CP1S', 'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E-SUD'],
        ]);
        $economat('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'EL-NORD', 'Nom' => 'Koné', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 2, 'Matricule' => 'EL-SUD', 'Nom' => 'Yao', 'CodeClasse' => 'CP1S', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    // --- Outils ---

    private function compte(int $id, string $login, bool $superAdmin = false, array $extra = []): RhUser
    {
        return RhUser::on('master')->forceCreate(array_merge([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'MotDePasse' => Hash::make('origine'),
            'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ], $extra));
    }

    private function affecter(int $rhUserId, string $societe, string $etab, int $roleId): int
    {
        return DB::connection('ecoprim')->table('console_affectations')->insertGetId([
            'rh_user_id' => $rhUserId, 'societe_code' => $societe,
            'etablissement_code' => $etab, 'role_id' => $roleId, 'actif' => true,
        ]);
    }

    private function en(RhUser $rh): void
    {
        PerimetreEtablissement::oublier();
        $this->actingAs($rh, 'sanctum');
    }

    private function adminSociete(string $societe = 'ABN', string $etab = 'E-ABN', int $id = 2): RhUser
    {
        $rh = $this->compte($id, 'admin'.$id);
        $this->affecter($id, $societe, $etab, 1);

        return $rh;
    }

    private function adminEtablissement(string $societe, string $etab, int $id): RhUser
    {
        $rh = $this->compte($id, 'ae'.$id);
        $this->affecter($id, $societe, $etab, 3);

        return $rh;
    }

    private function motDePasseInchange(int $id): void
    {
        $hache = DB::connection('master')->table('RH_USER')->where('Id', $id)->value('MotDePasse');
        $this->assertTrue(Hash::check('origine', $hache), "Le mot de passe du compte {$id} a été changé.");
    }

    // --- 1. Prise de contrôle d'un compte étranger ---

    /**
     * Le scénario d'attaque complet : affecter un compte de la suite qui n'a rien à voir
     * avec soi, pour le rendre visible, puis réinitialiser son mot de passe.
     */
    public function test_affecter_un_compte_hors_perimetre_est_refuse(): void
    {
        $this->compte(40, 'comptable-ailleurs');           // aucun lien avec ABN
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 40, 'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ])->assertForbidden();

        $this->assertDatabaseMissing('console_affectations', ['rh_user_id' => 40], 'ecoprim');
        // Toujours invisible, donc rien à réinitialiser.
        $this->postJson('/api/v1/utilisateurs/40/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertNotFound();
        $this->motDePasseInchange(40);
    }

    public function test_un_compte_inexistant_ou_hors_perimetre_recoit_la_meme_reponse(): void
    {
        $this->compte(40, 'ailleurs');
        $this->en($this->adminSociete('ABN'));

        $existant = $this->postJson('/api/v1/affectations', ['rh_user_id' => 40, 'etablissement_code' => 'E-ABN', 'role_id' => 2]);
        $inexistant = $this->postJson('/api/v1/affectations', ['rh_user_id' => 999, 'etablissement_code' => 'E-ABN', 'role_id' => 2]);

        $existant->assertForbidden();
        $inexistant->assertForbidden();
        $this->assertSame($existant->json('message'), $inexistant->json('message'));
    }

    public function test_un_compte_cree_depuis_la_console_peut_ensuite_recevoir_d_autres_roles(): void
    {
        $this->en($this->adminSociete('ABN'));

        $id = $this->postJson('/api/v1/utilisateurs', [
            'login' => 'nouveau', 'mot_de_passe' => 'secret1', 'nom' => 'Nouveau',
            'etablissement_code' => 'E-ABN', 'role_id' => 5,
        ])->assertCreated()->json('id');

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => $id, 'etablissement_code' => 'E-ABN2', 'role_id' => 2,
        ])->assertCreated();
    }

    public function test_le_super_admin_affecte_librement(): void
    {
        $this->compte(40, 'ailleurs');
        $this->en($this->compte(1, 'boss', true));

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 40, 'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ])->assertCreated();
    }

    // --- 1 bis. Niveaux d'administration ---

    public function test_un_admin_etablissement_ne_gere_pas_un_admin_societe_visible_chez_lui(): void
    {
        $chef = $this->adminSociete('ABN', 'E-ABN', 50);   // Admin Société, affecté dans E-ABN
        $this->en($this->adminEtablissement('ABN', 'E-ABN', 30));

        $logins = collect($this->getJson('/api/v1/utilisateurs')->assertOk()->json('data'))->pluck('login')->all();
        $this->assertContains($chef->Login, $logins);   // visible…

        // … mais intouchable.
        $this->postJson('/api/v1/utilisateurs/50/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden();
        $this->putJson('/api/v1/utilisateurs/50', ['login' => $chef->Login, 'nom' => 'Détourné'])->assertForbidden();
        $this->postJson('/api/v1/utilisateurs/50/desactiver')->assertForbidden();
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 50, 'etablissement_code' => 'E-ABN', 'role_id' => 2])
            ->assertForbidden();
        $affectation = DB::connection('ecoprim')->table('console_affectations')->where('rh_user_id', 50)->value('id');
        $this->deleteJson('/api/v1/affectations/'.$affectation)->assertForbidden();

        $this->motDePasseInchange(50);
        $this->assertDatabaseHas('console_affectations', ['id' => $affectation], 'ecoprim');
    }

    public function test_un_admin_etablissement_ne_gere_pas_un_autre_admin_etablissement(): void
    {
        $this->adminEtablissement('ABN', 'E-ABN', 31);
        $this->en($this->adminEtablissement('ABN', 'E-ABN', 30));

        $this->postJson('/api/v1/utilisateurs/31/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden();
        $this->motDePasseInchange(31);
    }

    public function test_un_admin_societe_ne_gere_pas_un_autre_admin_societe(): void
    {
        // Admin Société de SUD, affecté aussi d'un rôle simple chez ABN : visible d'ABN.
        $this->adminSociete('SUD', 'E-SUD', 60);
        $this->affecter(60, 'ABN', 'E-ABN', 5);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/utilisateurs/60/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden();
        $this->motDePasseInchange(60);
    }

    public function test_un_admin_societe_gere_un_compte_simple_entierement_chez_lui(): void
    {
        $this->compte(41, 'surveillant');
        $this->affecter(41, 'ABN', 'E-ABN', 5);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/utilisateurs/41/reinitialiser-mot-de-passe', ['mot_de_passe' => 'nouveau1'])
            ->assertOk();
        $this->assertTrue(Hash::check('nouveau1', DB::connection('master')->table('RH_USER')->where('Id', 41)->value('MotDePasse')));
    }

    // --- 1 ter. Réinitialisation d'un compte partagé ---

    public function test_reinitialisation_refusee_pour_un_compte_partage_avec_un_autre_etablissement(): void
    {
        $this->compte(42, 'prof');
        $this->affecter(42, 'ABN', 'E-ABN', 5);
        $this->affecter(42, 'ABN', 'E-ABN2', 5);           // hors du périmètre de l'AE
        $this->en($this->adminEtablissement('ABN', 'E-ABN', 30));

        $r = $this->postJson('/api/v1/utilisateurs/42/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden();
        $this->assertStringContainsString('partagé', $r->json('message'));
        $this->motDePasseInchange(42);
    }

    public function test_reinitialisation_refusee_pour_un_compte_rattache_a_une_autre_societe_de_la_plateforme(): void
    {
        $this->compte(43, 'partage');
        $this->affecter(43, 'ABN', 'E-ABN', 5);
        DB::connection('master')->table('societe_utilisateur')->insert(['user_id' => 43, 'societe_id' => 'SUD']);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/utilisateurs/43/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'autre société'));
        $this->motDePasseInchange(43);
    }

    public function test_reinitialisation_refusee_pour_un_compte_d_une_autre_application(): void
    {
        $this->compte(44, 'multiapp', false, ['user_id' => 900]);
        $this->affecter(44, 'ABN', 'E-ABN', 5);
        DB::connection('master')->table('roles')->insert(['id' => 7, 'name' => 'Administrateur']);
        DB::connection('master')->table('role_user')->insert(['id' => 1, 'user_id' => 900, 'role_id' => 7]);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/utilisateurs/44/reinitialiser-mot-de-passe', ['mot_de_passe' => 'jetentre'])
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'autre application'));
        $this->motDePasseInchange(44);
    }

    // --- 2. Enfants d'un compte Parent ---

    public function test_un_matricule_d_un_autre_etablissement_est_refuse(): void
    {
        $this->compte(45, 'parent');
        $this->affecter(45, 'ABN', 'E-ABN', 5);
        $this->en($this->adminSociete('ABN'));

        // À l'affectation…
        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 45, 'etablissement_code' => 'E-ABN', 'role_id' => 4, 'eleves' => ['EL-SUD'],
        ])->assertStatus(422)->assertJsonValidationErrors('eleves');
        $this->assertDatabaseMissing('console_affectations', ['rh_user_id' => 45, 'role_id' => 4], 'ecoprim');

        // … à la modification de la liste…
        $affectation = $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 45, 'etablissement_code' => 'E-ABN', 'role_id' => 4, 'eleves' => ['EL-NORD'],
        ])->assertCreated()->json('id');
        $this->putJson("/api/v1/affectations/{$affectation}/eleves", ['eleves' => ['EL-NORD', 'EL-SUD']])
            ->assertStatus(422)->assertJsonValidationErrors('eleves');
        $this->assertDatabaseMissing('console_affectation_eleves', ['eleve_matricule' => 'EL-SUD'], 'ecoprim');

        // … et à la création du compte, sans laisser de compte orphelin.
        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'parent2', 'mot_de_passe' => 'secret1', 'nom' => 'P',
            'etablissement_code' => 'E-ABN', 'role_id' => 4, 'eleves' => ['EL-SUD'],
        ])->assertStatus(422)->assertJsonValidationErrors('eleves');
        $this->assertDatabaseMissing('RH_USER', ['Login' => 'parent2'], 'master');
    }

    public function test_le_super_admin_non_plus_ne_rattache_pas_l_eleve_d_un_autre_etablissement(): void
    {
        $this->compte(46, 'parent');
        $this->en($this->compte(1, 'boss', true));

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 46, 'etablissement_code' => 'E-ABN', 'role_id' => 4, 'eleves' => ['EL-SUD'],
        ])->assertStatus(422);
    }

    // --- 3. Rôles d'administration reconnus par leur code ---

    public function test_un_administrateur_ne_cree_aucun_role(): void
    {
        $this->en($this->adminEtablissement('ABN', 'E-ABN', 30));

        foreach ([
            ['code' => 'chef', 'nom' => 'ADMIN SOCIÉTÉ'],
            ['code' => 'chef2', 'nom' => 'admin-societe'],
            ['code' => 'Admin_Societe', 'nom' => 'Chef'],
            ['code' => 'chef3', 'nom' => "Administrateur de l'établissement"],
            ['code' => 'chef4', 'nom' => 'Super Admin'],
        ] as $tentative) {
            // Un administrateur ne crée plus aucun rôle : réservé au Super Admin.
            $this->postJson('/api/v1/roles', $tentative)->assertForbidden();
        }
        $this->postJson('/api/v1/roles', ['code' => 'coordinateur', 'nom' => 'Coordinateur'])->assertForbidden();
    }

    /**
     * Un homonyme déjà en base (créé avant la correction) ne donne aucun droit : le nom
     * n'est plus lu, ni en SQL ni en PHP.
     */
    public function test_un_role_deguise_existant_n_est_pas_reconnu(): void
    {
        $deguise = DB::connection('ecoprim')->table('console_roles')
            ->insertGetId(['code' => 'chef-abn', 'nom' => 'Admin Société', 'societe_code' => 'ABN']);
        $rh = $this->compte(47, 'imposteur');
        $this->affecter(47, 'ABN', 'E-ABN', $deguise);

        $this->assertSame([], $rh->societesAdministrees());
        $this->assertFalse($rh->peutAccederConsole());

        // Et un Admin Établissement peut le conférer : il ne confère rien d'administratif.
        $this->compte(48, 'cible');
        $this->affecter(48, 'ABN', 'E-ABN', 5);
        $this->en($this->adminEtablissement('ABN', 'E-ABN', 30));
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 48, 'etablissement_code' => 'E-ABN', 'role_id' => $deguise])
            ->assertCreated();
        $this->assertSame([], RhUser::find(48)->societesAdministrees());
    }

    public function test_un_code_d_administration_a_la_casse_pres_n_est_pas_reconnu(): void
    {
        $majuscules = DB::connection('ecoprim')->table('console_roles')
            ->insertGetId(['code' => 'ADMIN-SOCIETE', 'nom' => 'X', 'societe_code' => 'ABN']);
        $rh = $this->compte(49, 'casse');
        $this->affecter(49, 'ABN', 'E-ABN', $majuscules);

        $this->assertSame([], $rh->societesAdministrees());
    }

    // --- 4. Établissements et société ---

    public function test_reprendre_l_etablissement_d_une_autre_societe_est_refuse(): void
    {
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E-VOISIN', 'Intitule' => 'Voisin', 'CodeSociete' => 'SUD',
            'Adresse1' => 'Rue', 'Pays' => 'CI',
        ]);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/etablissements', [
            'code' => 'E-VOISIN', 'intitule' => 'À moi', 'adresse' => 'Rue', 'pays' => 'CI', 'societe_code' => 'ABN',
        ])->assertForbidden();

        $this->assertDatabaseMissing('console_etablissements', ['code' => 'E-VOISIN'], 'ecoprim');
        $this->assertDatabaseHas('BEtablissements', ['CodeEtablissement' => 'E-VOISIN', 'CodeSociete' => 'SUD'], 'economat');
    }

    public function test_reprendre_un_etablissement_de_sa_societe_reste_possible(): void
    {
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E-MIEN', 'Intitule' => 'Mien', 'CodeSociete' => 'ABN', 'Adresse1' => 'Rue', 'Pays' => 'CI',
        ]);
        $this->en($this->adminSociete('ABN'));

        $this->postJson('/api/v1/etablissements', [
            'code' => 'E-MIEN', 'intitule' => 'Mien', 'adresse' => 'Rue', 'pays' => 'CI', 'societe_code' => 'ABN',
        ])->assertCreated()->assertJsonPath('cree_dans_source', false);
    }

    /** La surcouche dit ABN, ECONOMAT dit SUD : c'est ECONOMAT qui fait foi. */
    public function test_modifier_ne_reecrit_pas_la_societe_d_economat(): void
    {
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E-ABN', 'Intitule' => 'École Nord', 'CodeSociete' => 'SUD', 'Adresse1' => 'Rue', 'Pays' => 'CI',
        ]);
        $this->en($this->adminSociete('ABN'));

        $this->putJson('/api/v1/etablissements/1', [
            'intitule' => 'École Nord', 'adresse' => 'Rue', 'pays' => 'CI', 'societe_code' => 'ABN',
        ])->assertForbidden();

        $this->assertDatabaseHas('BEtablissements', ['CodeEtablissement' => 'E-ABN', 'CodeSociete' => 'SUD'], 'economat');
    }

    public function test_seul_le_super_admin_change_la_societe_d_un_etablissement(): void
    {
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E-ABN', 'Intitule' => 'École Nord', 'CodeSociete' => 'ABN', 'Adresse1' => 'Rue', 'Pays' => 'CI',
        ]);
        $this->en($this->compte(1, 'boss', true));

        $this->putJson('/api/v1/etablissements/1', [
            'intitule' => 'École Nord', 'adresse' => 'Rue', 'pays' => 'CI', 'societe_code' => 'SUD',
        ])->assertOk();

        $this->assertDatabaseHas('BEtablissements', ['CodeEtablissement' => 'E-ABN', 'CodeSociete' => 'SUD'], 'economat');
    }

    public function test_economat_illisible_n_est_pas_pris_pour_absent(): void
    {
        Schema::connection('economat')->drop('BEtablissements');
        $this->en($this->adminSociete('ABN'));

        $this->putJson('/api/v1/etablissements/1', [
            'intitule' => 'Modifiée', 'adresse' => 'Rue', 'pays' => 'CI', 'societe_code' => 'ABN',
        ])->assertStatus(503);

        // Rien n'a été écrit dans la surcouche non plus.
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E-ABN', 'intitule' => 'École Nord'], 'ecoprim');
    }
}
