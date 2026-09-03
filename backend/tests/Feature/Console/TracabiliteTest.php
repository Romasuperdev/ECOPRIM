<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use App\Support\Tracabilite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Traçabilité des utilisateurs — ECONOMAT.T_TRACABILITE.
 *
 * La structure réelle de cette table n'a pas été relevée sur la base de production : la
 * lecture découvre donc ses colonnes à l'exécution. Ces tests le vérifient sur DEUX
 * nommages différents, précisément pour ne pas dépendre de ce qu'on a imaginé.
 *
 * Le point sensible est le cloisonnement : un Admin Société ne doit voir que l'activité de
 * sa société, et ne rien voir du tout quand la table ne permet pas de trancher.
 */
class TracabiliteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();
        Tracabilite::oublier();

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
        ]);
        $eco('console_roles')->insert([
            ['id' => 1, 'code' => 'admin-societe', 'nom' => 'Admin Société'],
            ['id' => 2, 'code' => 'direction', 'nom' => 'Direction'],
        ]);
    }

    protected function tearDown(): void
    {
        Tracabilite::oublier();
        parent::tearDown();
    }

    private function compte(int $id, string $login, bool $super = false): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'Matricule' => 'M-'.$id,
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $super, 'Supprimer' => false,
        ]);
    }

    private function affecter(int $rhUserId, string $societe, string $etab, int $role = 1): void
    {
        DB::connection('ecoprim')->table('console_affectations')->insert([
            'rh_user_id' => $rhUserId, 'societe_code' => $societe,
            'etablissement_code' => $etab, 'role_id' => $role, 'actif' => true,
        ]);
    }

    private function superAdmin(): RhUser
    {
        $rh = $this->compte(1, 'boss', true);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    private function adminSociete(string $societe = 'ABN', string $etab = 'E-ABN'): RhUser
    {
        $rh = $this->compte(2, 'adminabn');
        $this->affecter(2, $societe, $etab);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    /** Nommage « attendu » : une colonne société explicite. */
    private function tableAvecSociete(): void
    {
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('CODE');
            $t->dateTime('DATEOPERATION')->nullable();
            $t->string('LOGIN')->nullable();
            $t->string('ACTION')->nullable();
            $t->string('NOMTABLE')->nullable();
            $t->string('DETAIL')->nullable();
            $t->string('POSTE')->nullable();
            $t->string('CODESOCIETE')->nullable();
        });
        Tracabilite::oublier();
    }

    private function tracer(array $lignes): void
    {
        DB::connection('economat')->table(Tracabilite::TABLE)->insert($lignes);
    }

    // ─── Découverte des colonnes ───

    public function test_les_colonnes_sont_reconnues_quel_que_soit_leur_nom(): void
    {
        $this->tableAvecSociete();
        $this->superAdmin();

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame('DATEOPERATION', $r->json('colonnes.date'));
        $this->assertSame('LOGIN', $r->json('colonnes.utilisateur'));
        $this->assertSame('ACTION', $r->json('colonnes.action'));
        $this->assertSame('NOMTABLE', $r->json('colonnes.objet'));
        $this->assertSame('CODESOCIETE', $r->json('colonnes.societe'));
    }

    public function test_un_nommage_different_est_reconnu_aussi(): void
    {
        // Preuve que la lecture ne dépend pas du nommage qu'on avait imaginé.
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('NUM');
            $t->dateTime('DATE_TRACE')->nullable();
            $t->string('UTILISATEUR')->nullable();
            $t->string('OPERATION')->nullable();
            $t->string('ECRAN')->nullable();
            $t->string('OBSERVATION')->nullable();
        });
        Tracabilite::oublier();
        $this->superAdmin();

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame('DATE_TRACE', $r->json('colonnes.date'));
        $this->assertSame('UTILISATEUR', $r->json('colonnes.utilisateur'));
        $this->assertSame('OPERATION', $r->json('colonnes.action'));
        $this->assertSame('ECRAN', $r->json('colonnes.objet'));
        $this->assertSame('OBSERVATION', $r->json('colonnes.detail'));
        // Ni société ni établissement dans ce nommage.
        $this->assertNull($r->json('colonnes.societe'));
    }

    public function test_une_table_absente_ne_fait_pas_planter_la_page(): void
    {
        $this->superAdmin();

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame([], $r->json('data'));
        $this->assertStringContainsString('introuvable', $r->json('message'));
    }

    // ─── Lecture et filtres ───

    public function test_le_super_admin_voit_toute_la_tracabilite(): void
    {
        $this->tableAvecSociete();
        $this->superAdmin();
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'adminabn',
                'ACTION' => 'Connexion', 'NOMTABLE' => 'RH_USER', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 09:00:00', 'LOGIN' => 'chezsud',
                'ACTION' => 'Modification', 'NOMTABLE' => 'T_ETUDIANT', 'CODESOCIETE' => 'SUD'],
        ]);

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame(2, $r->json('total'));
        $this->assertSame('Toutes les sociétés', $r->json('portee'));
        // Le plus récent d'abord.
        $this->assertSame('chezsud', $r->json('data.0.utilisateur'));
    }

    public function test_on_filtre_par_utilisateur_par_action_et_par_periode(): void
    {
        $this->tableAvecSociete();
        $this->superAdmin();
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'a', 'ACTION' => 'Connexion', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-05 08:00:00', 'LOGIN' => 'b', 'ACTION' => 'Suppression', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 3, 'DATEOPERATION' => '2026-09-10 08:00:00', 'LOGIN' => 'a', 'ACTION' => 'Modification', 'CODESOCIETE' => 'ABN'],
        ]);

        $this->assertSame(2, $this->getJson('/api/v1/tracabilite?utilisateur=a')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tracabilite?action=Suppr')->json('total'));
        $this->assertSame(2, $this->getJson('/api/v1/tracabilite?du=2026-09-05')->json('total'));
        $this->assertSame(1, $this->getJson('/api/v1/tracabilite?du=2026-09-02&au=2026-09-06')->json('total'));
    }

    public function test_les_colonnes_non_reconnues_restent_visibles(): void
    {
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('CODE');
            $t->string('LOGIN')->nullable();
            $t->string('CHAMP_MAISON')->nullable();
        });
        Tracabilite::oublier();
        $this->superAdmin();
        $this->tracer([['CODE' => 1, 'LOGIN' => 'a', 'CHAMP_MAISON' => 'valeur']]);

        // Rien n'est perdu : ce qu'on ne sait pas nommer part dans « autres ».
        $this->assertSame('valeur', $this->getJson('/api/v1/tracabilite')->json('data.0.autres.CHAMP_MAISON'));
    }

    // ─── Cloisonnement ───

    public function test_l_admin_societe_ne_voit_que_sa_societe(): void
    {
        $this->tableAvecSociete();
        $this->adminSociete('ABN');
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'x', 'ACTION' => 'A', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 08:00:00', 'LOGIN' => 'y', 'ACTION' => 'B', 'CODESOCIETE' => 'SUD'],
        ]);

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame(1, $r->json('total'));
        $this->assertSame('ABN', $r->json('data.0.societe'));
        $this->assertSame('societe', $r->json('strategie'));
        $this->assertSame('Groupe ABN', $r->json('portee'));
    }

    public function test_sans_colonne_societe_le_cloisonnement_passe_par_l_etablissement(): void
    {
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('CODE');
            $t->dateTime('DATEOPERATION')->nullable();
            $t->string('LOGIN')->nullable();
            $t->string('ACTION')->nullable();
            $t->string('CODEETABLISSEMENT')->nullable();
        });
        Tracabilite::oublier();
        $this->adminSociete('ABN');
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'x', 'ACTION' => 'A', 'CODEETABLISSEMENT' => 'E-ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 08:00:00', 'LOGIN' => 'y', 'ACTION' => 'B', 'CODEETABLISSEMENT' => 'E-SUD'],
        ]);

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame('etablissement', $r->json('strategie'));
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('E-ABN', $r->json('data.0.etablissement'));
    }

    public function test_a_defaut_le_cloisonnement_passe_par_les_comptes_de_la_societe(): void
    {
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('CODE');
            $t->dateTime('DATEOPERATION')->nullable();
            $t->string('LOGIN')->nullable();
            $t->string('ACTION')->nullable();
        });
        Tracabilite::oublier();

        $this->compte(10, 'chezabn');
        $this->affecter(10, 'ABN', 'E-ABN', 2);
        $this->compte(11, 'chezsud');
        $this->affecter(11, 'SUD', 'E-SUD', 2);
        $this->adminSociete('ABN');

        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'chezabn', 'ACTION' => 'A'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 08:00:00', 'LOGIN' => 'chezsud', 'ACTION' => 'B'],
            ['CODE' => 3, 'DATEOPERATION' => '2026-09-03 08:00:00', 'LOGIN' => 'adminabn', 'ACTION' => 'C'],
            ['CODE' => 4, 'DATEOPERATION' => '2026-09-04 08:00:00', 'LOGIN' => 'inconnu', 'ACTION' => 'D'],
        ]);

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        $this->assertSame('utilisateur', $r->json('strategie'));
        // Lui-même et le compte affecté chez ABN. Ni SUD, ni un login inconnu de la console.
        $this->assertSame(['adminabn', 'chezabn'],
            collect($r->json('data'))->pluck('utilisateur')->sort()->values()->all());
    }

    public function test_si_la_table_ne_permet_pas_de_cloisonner_l_admin_societe_ne_voit_rien(): void
    {
        // Aucune colonne société, établissement ni utilisateur : on ne peut pas trancher.
        Schema::connection('economat')->create(Tracabilite::TABLE, function ($t) {
            $t->integer('CODE');
            $t->dateTime('DATEOPERATION')->nullable();
            $t->string('ACTION')->nullable();
        });
        Tracabilite::oublier();
        $this->adminSociete('ABN');
        $this->tracer([['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'ACTION' => 'A']]);

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();

        // Fail closed : rien plutôt que l'activité de toutes les sociétés.
        $this->assertSame([], $r->json('data'));
        $this->assertSame('impossible', $r->json('strategie'));
        $this->assertStringContainsString('Rien ne s', $r->json('message'));

        // Le Super Admin, lui, voit tout : c'est son rôle.
        $this->actingAs($this->compte(9, 'boss9', true), 'sanctum');
        $this->assertSame(1, $this->getJson('/api/v1/tracabilite')->json('total'));
    }

    public function test_le_super_admin_qui_choisit_une_societe_s_y_limite(): void
    {
        $this->tableAvecSociete();
        $this->superAdmin();
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'x', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 08:00:00', 'LOGIN' => 'y', 'CODESOCIETE' => 'SUD'],
        ]);

        $this->postJson('/api/v1/console/societe', ['societe_code' => 'SUD'])->assertOk();

        $r = $this->getJson('/api/v1/tracabilite')->assertOk();
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('SUD', $r->json('data.0.societe'));
    }

    // ─── Traçabilité d'un compte ───

    public function test_la_tracabilite_d_un_compte_est_atteignable_depuis_sa_fiche(): void
    {
        $this->tableAvecSociete();
        $this->compte(10, 'chezabn');
        $this->affecter(10, 'ABN', 'E-ABN', 2);
        $this->adminSociete('ABN');
        $this->tracer([
            ['CODE' => 1, 'DATEOPERATION' => '2026-09-01 08:00:00', 'LOGIN' => 'chezabn', 'ACTION' => 'A', 'CODESOCIETE' => 'ABN'],
            ['CODE' => 2, 'DATEOPERATION' => '2026-09-02 08:00:00', 'LOGIN' => 'adminabn', 'ACTION' => 'B', 'CODESOCIETE' => 'ABN'],
        ]);

        $r = $this->getJson('/api/v1/tracabilite/utilisateurs/10')->assertOk();

        $this->assertSame('chezabn', $r->json('utilisateur.login'));
        $this->assertSame(1, $r->json('total'));
        $this->assertSame('chezabn', $r->json('data.0.utilisateur'));
        // On dit sous quels identifiants on a cherché : ECONOMAT n'utilise pas partout le même.
        $this->assertContains('M-10', $r->json('utilisateur.identifiants_cherches'));
    }

    public function test_la_tracabilite_d_un_compte_d_une_autre_societe_est_refusee(): void
    {
        $this->tableAvecSociete();
        $this->compte(11, 'chezsud');
        $this->affecter(11, 'SUD', 'E-SUD', 2);
        $this->adminSociete('ABN');

        // 404 : hors périmètre, l'existence du compte n'a pas à être confirmée.
        $this->getJson('/api/v1/tracabilite/utilisateurs/11')->assertNotFound();
    }

    public function test_un_compte_sans_acces_console_n_atteint_pas_la_tracabilite(): void
    {
        $this->tableAvecSociete();
        $rh = $this->compte(3, 'prof');
        $this->affecter(3, 'ABN', 'E-ABN', 2); // rôle Direction, pas admin société
        $this->actingAs($rh, 'sanctum');

        $this->getJson('/api/v1/tracabilite')->assertForbidden();
    }

    public function test_la_tracabilite_est_en_lecture_seule(): void
    {
        $this->tableAvecSociete();
        $this->superAdmin();

        // Aucune écriture n'est exposée : NEXORA restitue, il ne trace pas ici.
        // POST sur l'URI existante -> 405 ; DELETE sur un chemin qui n'existe pas -> 404.
        $this->postJson('/api/v1/tracabilite', [])->assertStatus(405);
        $this->deleteJson('/api/v1/tracabilite/1')->assertNotFound();
    }
}
