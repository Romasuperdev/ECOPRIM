<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Les deux sortes d'administrateur de la console.
 *
 *   - Le Super Admin administre la console générale (sociétés, rôles) ET la console de
 *     chaque société.
 *   - L'Admin Société n'administre que la société à laquelle il est affecté : la console
 *     générale lui est fermée, et il ne voit ni les établissements, ni les utilisateurs
 *     des autres sociétés.
 *
 * Le rôle et la société se lisent dans les affectations propres à NEXORA
 * (console_affectations + console_roles) : aucune écriture dans les tables partagées.
 */
class PerimetreConsoleTest extends TestCase
{
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
            '--database' => 'ecoprim',
            '--path' => 'database/migrations/console',
            '--realpath' => false,
            '--force' => true,
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
        ]);
        $eco('console_roles')->insert([
            ['id' => 1, 'code' => 'admin-societe', 'nom' => 'Admin Société'],
            ['id' => 2, 'code' => 'direction', 'nom' => 'Direction'],
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

    private function affecter(int $rhUserId, string $societe, string $etab, int $roleId = 1, array $extra = []): void
    {
        DB::connection('ecoprim')->table('console_affectations')->insert(array_merge([
            'rh_user_id' => $rhUserId, 'societe_code' => $societe,
            'etablissement_code' => $etab, 'role_id' => $roleId, 'actif' => true,
        ], $extra));
    }

    private function superAdmin(): RhUser
    {
        $rh = $this->compte(1, 'boss', true);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    private function adminSociete(string $societe = 'ABN', string $etab = 'E-ABN'): RhUser
    {
        $rh = $this->compte(2, 'admin'.strtolower($societe));
        $this->affecter(2, $societe, $etab);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    private function adminEtablissement(string $societe, string $etab, int $id = 30, string $login = 'affecte'): RhUser
    {
        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $roleId = $eco('console_roles')->where('code', 'admin-etablissement')->value('id');
        if (! $roleId) {
            $roleId = $eco('console_roles')->insertGetId(['code' => 'admin-etablissement', 'nom' => 'Admin Établissement']);
        }

        $rh = $this->compte($id, $login);
        $this->affecter($id, $societe, $etab, $roleId);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    // --- Qui est quoi ---

    public function test_le_super_admin_administre_toutes_les_societes(): void
    {
        $rh = $this->superAdmin();

        $this->assertTrue($rh->isSuperAdmin());
        $this->assertFalse($rh->estAdminSociete());
        $this->assertTrue($rh->peutAdministrerSociete('ABN'));
        $this->assertTrue($rh->peutAdministrerSociete('SUD'));
    }

    public function test_l_admin_societe_n_administre_que_la_sienne(): void
    {
        $rh = $this->adminSociete('ABN');

        $this->assertFalse($rh->isSuperAdmin());
        $this->assertTrue($rh->estAdminSociete());
        $this->assertSame(['ABN'], $rh->societesAdministrees());
        $this->assertTrue($rh->peutAdministrerSociete('ABN'));
        $this->assertFalse($rh->peutAdministrerSociete('SUD'));
    }

    public function test_une_affectation_sans_le_role_admin_societe_ne_donne_pas_la_console(): void
    {
        $rh = $this->compte(3, 'prof');
        // Rôle Direction : affecté à un établissement, mais pas administrateur.
        $this->affecter(3, 'ABN', 'E-ABN', 2);
        $this->actingAs($rh, 'sanctum');

        $this->assertSame([], $rh->societesAdministrees());
        $this->assertFalse($rh->peutAccederConsole());
        $this->getJson('/api/v1/etablissements')->assertForbidden();
    }

    public function test_une_affectation_echue_ou_inactive_ne_donne_plus_la_console(): void
    {
        $rh = $this->compte(4, 'ancien');
        $this->affecter(4, 'ABN', 'E-ABN', 1, ['actif' => false]);
        $this->affecter(4, 'SUD', 'E-SUD', 1, ['date_fin' => '2020-01-01']);
        $this->actingAs($rh, 'sanctum');

        $this->assertSame([], $rh->societesAdministrees());
        $this->assertFalse($rh->peutAccederConsole());
    }

    // --- Console générale ---

    public function test_la_console_generale_est_fermee_a_l_admin_societe(): void
    {
        $this->adminSociete();

        $this->getJson('/api/v1/societes')->assertForbidden();
        $this->postJson('/api/v1/societes', ['code' => 'X', 'nom' => 'X'])->assertForbidden();
    }

    public function test_la_console_generale_reste_ouverte_au_super_admin(): void
    {
        $this->superAdmin();

        $this->getJson('/api/v1/societes')->assertOk();
        $this->getJson('/api/v1/roles')->assertOk();
    }

    // --- Console d'une société ---

    public function test_l_admin_societe_ne_voit_que_les_etablissements_de_sa_societe(): void
    {
        $this->adminSociete('ABN');

        $r = $this->getJson('/api/v1/etablissements')->assertOk();

        $this->assertSame(['E-ABN'], collect($r->json('data'))->pluck('code')->all());
    }

    public function test_l_admin_societe_ne_peut_pas_ouvrir_un_etablissement_d_ailleurs(): void
    {
        $this->adminSociete('ABN');

        $this->getJson('/api/v1/etablissements/E-ABN')->assertOk();
        $this->getJson('/api/v1/etablissements/E-SUD')->assertForbidden();
    }

    public function test_l_admin_societe_ne_peut_pas_creer_un_etablissement_ailleurs(): void
    {
        $this->adminSociete('ABN');

        $commun = ['code' => 'E-NEW', 'intitule' => 'Nouvelle', 'adresse' => 'Rue 1', 'pays' => 'CI'];

        $this->postJson('/api/v1/etablissements', $commun + ['societe_code' => 'SUD'])
            ->assertForbidden();
        $this->postJson('/api/v1/etablissements', $commun + ['societe_code' => 'ABN'])
            ->assertCreated();
    }

    public function test_l_admin_societe_ne_peut_pas_deplacer_un_etablissement_hors_de_sa_societe(): void
    {
        $this->adminSociete('ABN');

        // Sortir son établissement vers une autre société reviendrait à le perdre de vue
        // tout en agissant dessus : refusé.
        $this->putJson('/api/v1/etablissements/1', [
            'intitule' => 'École Nord', 'adresse' => 'Rue 1', 'pays' => 'CI', 'societe_code' => 'SUD',
        ])->assertForbidden();

        $this->putJson('/api/v1/etablissements/2', [
            'intitule' => 'Piraté', 'adresse' => 'Rue 1', 'pays' => 'CI', 'societe_code' => 'SUD',
        ])->assertForbidden();
    }

    public function test_le_super_admin_bascule_d_une_societe_a_l_autre(): void
    {
        $this->superAdmin();

        // Vue générale par défaut : les deux sociétés.
        $r = $this->getJson('/api/v1/etablissements')->assertOk();
        $this->assertCount(2, $r->json('data'));

        $this->postJson('/api/v1/console/societe', ['societe_code' => 'SUD'])->assertOk()
            ->assertJsonPath('societe_code', 'SUD')
            ->assertJsonPath('societe_nom', 'Groupe Sud');

        $r = $this->getJson('/api/v1/etablissements')->assertOk();
        $this->assertSame(['E-SUD'], collect($r->json('data'))->pluck('code')->all());

        // Retour à la vue générale.
        $this->postJson('/api/v1/console/societe', ['societe_code' => ''])->assertOk()
            ->assertJsonPath('societe_code', null);
        $this->assertCount(2, $this->getJson('/api/v1/etablissements')->json('data'));
    }

    public function test_l_admin_societe_ne_peut_pas_basculer_ailleurs_ni_en_vue_generale(): void
    {
        $this->adminSociete('ABN');

        $this->postJson('/api/v1/console/societe', ['societe_code' => 'SUD'])->assertForbidden();
        $this->postJson('/api/v1/console/societe', ['societe_code' => ''])->assertForbidden();

        // Sa société reste la sienne, et le sélecteur est annoncé verrouillé.
        $this->getJson('/api/v1/console/contexte')->assertOk()
            ->assertJsonPath('societe_code', 'ABN')
            ->assertJsonPath('societe_verrouillee', true)
            ->assertJsonPath('admin_societe', true)
            ->assertJsonPath('super_admin', false)
            ->assertJsonCount(1, 'societes');
    }

    public function test_le_contexte_du_super_admin_propose_toutes_les_societes(): void
    {
        $this->superAdmin();

        $this->getJson('/api/v1/console/contexte')->assertOk()
            ->assertJsonPath('super_admin', true)
            ->assertJsonPath('societe_verrouillee', false)
            ->assertJsonPath('societe_code', null)
            ->assertJsonCount(2, 'societes');
    }

    // --- Utilisateurs ---

    public function test_l_admin_societe_ne_voit_que_les_utilisateurs_de_sa_societe(): void
    {
        $this->compte(10, 'chezabn');
        $this->affecter(10, 'ABN', 'E-ABN', 2);
        $this->compte(11, 'chezsud');
        $this->affecter(11, 'SUD', 'E-SUD', 2);
        $this->compte(12, 'sansaffectation');

        $this->adminSociete('ABN');

        $logins = collect($this->getJson('/api/v1/utilisateurs')->assertOk()->json('data'))
            ->pluck('login')->sort()->values()->all();

        // Lui-même et l'utilisateur affecté chez ABN. Ni celui de SUD, ni le non affecté.
        $this->assertSame(['adminabn', 'chezabn'], $logins);
    }

    public function test_l_admin_societe_ne_peut_pas_ouvrir_ni_desactiver_un_compte_d_ailleurs(): void
    {
        $this->compte(11, 'chezsud');
        $this->affecter(11, 'SUD', 'E-SUD', 2);
        $this->adminSociete('ABN');

        // 404 et non 403 : hors périmètre, l'existence du compte n'a pas à être confirmée.
        $this->getJson('/api/v1/utilisateurs/11')->assertNotFound();
        $this->postJson('/api/v1/utilisateurs/11/desactiver')->assertNotFound();

        // Le compte n'a pas été touché.
        $this->assertDatabaseHas('RH_USER', ['Id' => 11, 'Supprimer' => false], 'master');
    }

    public function test_le_compte_cree_par_un_admin_societe_est_affecte_dans_sa_societe(): void
    {
        $this->adminSociete('ABN');

        // Sans établissement ni rôle, la création est refusée : le compte serait invisible
        // à celui qui vient de le créer.
        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'nouveau', 'mot_de_passe' => 'secret1', 'nom' => 'Nouveau',
        ])->assertStatus(422)->assertJsonValidationErrors(['etablissement_code', 'role_id']);

        // Et il ne peut pas le créer dans une autre société.
        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'nouveau', 'mot_de_passe' => 'secret1', 'nom' => 'Nouveau',
            'etablissement_code' => 'E-SUD', 'role_id' => 2,
        ])->assertForbidden();

        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'nouveau', 'mot_de_passe' => 'secret1', 'nom' => 'Nouveau',
            'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('console_affectations', [
            'etablissement_code' => 'E-ABN', 'societe_code' => 'ABN', 'role_id' => 2,
        ], 'ecoprim');

        // Il apparaît bien dans sa liste.
        $logins = collect($this->getJson('/api/v1/utilisateurs')->json('data'))->pluck('login')->all();
        $this->assertContains('nouveau', $logins);
    }

    // --- Interface de console de l'Admin Société ---

    public function test_l_admin_societe_a_son_accueil_de_console_borne_a_sa_societe(): void
    {
        $this->compte(10, 'chezabn');
        $this->affecter(10, 'ABN', 'E-ABN', 2);
        $this->compte(11, 'chezsud');
        $this->affecter(11, 'SUD', 'E-SUD', 2);

        $this->adminSociete('ABN');

        $this->getJson('/api/v1/console/tableau-de-bord')->assertOk()
            ->assertJsonPath('vue_generale', false)
            ->assertJsonPath('societe_code', 'ABN')
            ->assertJsonPath('societe_nom', 'Groupe ABN')
            // Le nombre de sociétés ne lui est pas communiqué : il n'en administre qu'une.
            ->assertJsonPath('societes', null)
            ->assertJsonPath('etablissements', 1)
            // Lui-même et l'utilisateur affecté chez ABN, jamais celui de SUD.
            ->assertJsonPath('utilisateurs', 2)
            ->assertJsonPath('affectations', 2);
    }

    public function test_l_accueil_du_super_admin_couvre_tout_puis_suit_la_societe_choisie(): void
    {
        $this->superAdmin();

        $this->getJson('/api/v1/console/tableau-de-bord')->assertOk()
            ->assertJsonPath('vue_generale', true)
            ->assertJsonPath('societes', 2)
            ->assertJsonPath('etablissements', 2);

        $this->postJson('/api/v1/console/societe', ['societe_code' => 'SUD'])->assertOk();

        $this->getJson('/api/v1/console/tableau-de-bord')->assertOk()
            ->assertJsonPath('vue_generale', false)
            ->assertJsonPath('societe_nom', 'Groupe Sud')
            ->assertJsonPath('etablissements', 1);
    }

    public function test_l_admin_societe_gere_ses_propres_roles_mais_pas_le_catalogue_general(): void
    {
        $this->adminSociete('ABN');

        // Il voit le catalogue général (2 rôles : admin-societe, direction)…
        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(2);

        // … peut créer et supprimer un rôle propre à sa société…
        $cree = $this->postJson('/api/v1/roles', ['code' => 'coordinateur', 'nom' => 'Coordinateur'])
            ->assertCreated()
            ->assertJsonPath('societe_code', 'ABN');

        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(3);

        $this->deleteJson('/api/v1/roles/'.$cree->json('id'))->assertNoContent();

        // … mais ne peut pas toucher au catalogue général, commun à toutes les sociétés.
        $this->deleteJson('/api/v1/roles/2')->assertForbidden();
    }

    public function test_l_admin_etablissement_gere_aussi_les_roles_de_sa_societe(): void
    {
        $this->adminEtablissement('ABN', 'E-ABN');

        // Sa société se déduit de son établissement : même capacité qu'un Admin Société.
        $cree = $this->postJson('/api/v1/roles', ['code' => 'surveillant-abn', 'nom' => 'Surveillant'])
            ->assertCreated()
            ->assertJsonPath('societe_code', 'ABN');

        $this->deleteJson('/api/v1/roles/'.$cree->json('id'))->assertNoContent();

        // Ni lui ni un Admin Société d'ABN ne touchent au catalogue général.
        $this->deleteJson('/api/v1/roles/2')->assertForbidden();
    }

    public function test_l_admin_etablissement_ne_peut_pas_supprimer_un_role_d_une_autre_societe(): void
    {
        // 'SUD' existe déjà (setUp) : un rôle qui lui est propre, hors du périmètre d'un
        // admin d'ABN.
        $roleAilleurs = DB::connection('ecoprim')->table('console_roles')->insertGetId(['code' => 'x', 'nom' => 'X', 'societe_code' => 'SUD']);

        $this->adminEtablissement('ABN', 'E-ABN');

        $this->deleteJson('/api/v1/roles/'.$roleAilleurs)->assertForbidden();
    }

    public function test_le_super_admin_cree_un_role_general_en_vue_generale_et_scope_dans_une_societe(): void
    {
        $this->superAdmin();

        // Vue générale (aucune société choisie) : rôle du catalogue général.
        $this->postJson('/api/v1/roles', ['code' => 'g1', 'nom' => 'Général 1'])
            ->assertCreated()
            ->assertJsonPath('societe_code', null);

        // Une fois une société choisie, le rôle créé lui est propre.
        $this->postJson('/api/v1/console/societe', ['societe_code' => 'ABN'])->assertOk();
        $this->postJson('/api/v1/roles', ['code' => 's1', 'nom' => 'Propre à ABN'])
            ->assertCreated()
            ->assertJsonPath('societe_code', 'ABN');
    }

    // --- Page de connexion ---

    public function test_la_societe_de_rattachement_est_annoncee_avant_le_mot_de_passe(): void
    {
        $this->compte(20, 'chefabn');
        $this->affecter(20, 'ABN', 'E-ABN', 1);
        DB::connection('master')->table('RH_USER')->where('Id', 20)->update(['Etab' => 'E1']);
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN',
        ]);

        // Endpoint public : l'utilisateur voit à quoi il se connecte avant de saisir son
        // mot de passe, sans qu'aucune donnée personnelle ne transite.
        $corps = $this->postJson('/api/v1/etablissement-du-compte', ['identifiant' => 'chefabn'])
            ->assertOk()
            ->assertJsonPath('etablissement', 'École Alpha')
            ->assertJsonPath('societe', 'Groupe ABN')
            ->json();

        $this->assertSame(['etablissement', 'societe'], array_keys($corps));
    }

    public function test_l_admin_societe_ne_peut_pas_affecter_dans_une_autre_societe(): void
    {
        $this->compte(13, 'cible');
        $this->adminSociete('ABN');

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 13, 'etablissement_code' => 'E-SUD', 'role_id' => 2,
        ])->assertForbidden();

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 13, 'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ])->assertCreated();
    }

    // --- Admin Établissement : un cran en dessous de l'Admin Société ---

    public function test_l_admin_etablissement_n_administre_que_son_etablissement(): void
    {
        $rh = $this->adminEtablissement('ABN', 'E-ABN');

        $this->assertFalse($rh->isSuperAdmin());
        $this->assertFalse($rh->estAdminSociete());
        $this->assertTrue($rh->estAdminEtablissement());
        $this->assertSame(['E-ABN'], $rh->etablissementsAdministres());
        $this->assertTrue($rh->peutAdministrerEtablissement('E-ABN'));
        $this->assertFalse($rh->peutAdministrerEtablissement('E-SUD'));
        $this->assertTrue($rh->peutAccederConsole());
    }

    public function test_l_admin_etablissement_n_a_pas_acces_au_niveau_societe(): void
    {
        $this->adminEtablissement('ABN', 'E-ABN');

        // Il peut lire son propre établissement (pour le formulaire d'affectation)…
        $r = $this->getJson('/api/v1/etablissements')->assertOk();
        $this->assertSame(['E-ABN'], collect($r->json('data'))->pluck('code')->all());
        $this->getJson('/api/v1/etablissements/E-ABN')->assertOk();

        // … mais la gestion (CRUD des établissements) et la traçabilité restent au niveau
        // société. Créer un compte ou un rôle propre à sa société, en revanche, est de son
        // ressort (voir test_l_admin_etablissement_cree_un_compte_dans_son_etablissement et
        // test_l_admin_etablissement_gere_aussi_les_roles_de_sa_societe) : c'est le
        // quotidien d'un établissement, pas une prérogative de société.
        $this->postJson('/api/v1/etablissements', [
            'code' => 'E-NEW', 'intitule' => 'Nouvelle', 'adresse' => 'Rue 1', 'pays' => 'CI', 'societe_code' => 'ABN',
        ])->assertForbidden();
        $this->getJson('/api/v1/tracabilite')->assertForbidden();
    }

    public function test_l_admin_etablissement_cree_un_compte_dans_son_etablissement(): void
    {
        $this->adminEtablissement('ABN', 'E-ABN');

        $r = $this->postJson('/api/v1/utilisateurs', [
            'login' => 'nouveauprof', 'mot_de_passe' => 'secret123', 'nom' => 'Diallo',
            'etablissement_code' => 'E-ABN', 'role_id' => 2, // direction
        ])->assertCreated();

        $this->assertDatabaseHas('RH_USER', ['Login' => 'nouveauprof'], 'master');
        $this->assertDatabaseHas('console_affectations', [
            'rh_user_id' => $r->json('id'), 'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ], 'ecoprim');
    }

    public function test_l_admin_etablissement_ne_peut_pas_creer_un_compte_hors_de_son_etablissement(): void
    {
        DB::connection('ecoprim')->table('console_etablissements')->insert(
            ['id' => 3, 'code' => 'E-NEW', 'intitule' => 'Autre école', 'societe_code' => 'ABN', 'actif' => true]
        );
        $this->adminEtablissement('ABN', 'E-ABN');

        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'ailleurs', 'mot_de_passe' => 'secret123', 'nom' => 'X',
            'etablissement_code' => 'E-NEW', 'role_id' => 2,
        ])->assertForbidden();
    }

    public function test_l_admin_etablissement_ne_peut_pas_creer_un_compte_admin_societe(): void
    {
        $this->adminEtablissement('ABN', 'E-ABN');

        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'x', 'mot_de_passe' => 'secret123', 'nom' => 'X',
            'etablissement_code' => 'E-ABN', 'role_id' => 1, // admin-societe
        ])->assertForbidden();
    }

    public function test_l_admin_etablissement_lit_le_catalogue_et_les_utilisateurs_de_son_etablissement_seul(): void
    {
        DB::connection('ecoprim')->table('console_etablissements')->insert(
            ['id' => 3, 'code' => 'E-NEW', 'intitule' => 'Autre école', 'societe_code' => 'ABN', 'actif' => true]
        );
        $this->compte(10, 'chezabn');
        $this->affecter(10, 'ABN', 'E-ABN', 2);
        $this->compte(11, 'ailleursdanslasociete');
        $this->affecter(11, 'ABN', 'E-NEW', 2);

        $this->adminEtablissement('ABN', 'E-ABN');

        $this->getJson('/api/v1/roles')->assertOk();

        $logins = collect($this->getJson('/api/v1/utilisateurs')->assertOk()->json('data'))
            ->pluck('login')->sort()->values()->all();

        // Lui-même et l'utilisateur de E-ABN ; jamais celui de E-NEW, même société.
        $this->assertSame(['affecte', 'chezabn'], $logins);
    }

    public function test_l_admin_etablissement_affecte_un_role_dans_son_etablissement_seulement(): void
    {
        DB::connection('ecoprim')->table('console_etablissements')->insert(
            ['id' => 3, 'code' => 'E-NEW', 'intitule' => 'Autre école', 'societe_code' => 'ABN', 'actif' => true]
        );
        $this->compte(13, 'cible');
        $this->adminEtablissement('ABN', 'E-ABN');

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 13, 'etablissement_code' => 'E-NEW', 'role_id' => 2,
        ])->assertForbidden();

        $r = $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 13, 'etablissement_code' => 'E-ABN', 'role_id' => 2,
        ])->assertCreated();

        $this->deleteJson('/api/v1/affectations/'.$r->json('id'))->assertNoContent();
    }

    public function test_l_admin_etablissement_ne_peut_pas_conferer_le_role_admin_societe(): void
    {
        $this->compte(13, 'cible');
        $this->adminEtablissement('ABN', 'E-ABN');

        $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 13, 'etablissement_code' => 'E-ABN', 'role_id' => 1, // admin-societe
        ])->assertForbidden();
    }
}
