<?php

namespace Tests\Feature\Console;

use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\Console\Societe;
use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Console modifiable : CRUD Sociétés / Établissements + règles d'affectation
 * (une seule société par utilisateur, anti-doublon). Identités lues dans RH_USER,
 * données Console écrites dans la base propre ecoprim (console_*).
 */
class ConsoleCrudTest extends TestCase
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

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@ecole.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
    }

    public function test_creer_et_lister_societe(): void
    {
        $this->postJson('/api/v1/societes', ['code' => 'ABN', 'nom' => 'Abidjan Nord', 'ville' => 'Abidjan'])
            ->assertCreated()
            ->assertJsonFragment(['code' => 'ABN', 'nom' => 'Abidjan Nord']);

        $this->assertDatabaseHas('console_societes', ['code' => 'ABN'], 'ecoprim');

        $this->getJson('/api/v1/societes')->assertOk()->assertJsonFragment(['code' => 'ABN']);
    }

    public function test_code_societe_unique(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        $this->postJson('/api/v1/societes', ['code' => 'ABN', 'nom' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_activer_desactiver_societe(): void
    {
        $s = Societe::create(['code' => 'ABN', 'nom' => 'Nord', 'actif' => true]);
        $this->postJson("/api/v1/societes/{$s->id}/desactiver")->assertOk();
        $this->assertFalse($s->fresh()->actif);
        $this->postJson("/api/v1/societes/{$s->id}/activer")->assertOk();
        $this->assertTrue($s->fresh()->actif);
    }

    public function test_etablissement_exige_societe_existante(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);

        $this->postJson('/api/v1/etablissements', ['code' => 'E1', 'intitule' => 'Alpha', 'societe_code' => 'ABN'])
            ->assertCreated();
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E1', 'societe_code' => 'ABN'], 'ecoprim');

        $this->postJson('/api/v1/etablissements', ['code' => 'E2', 'intitule' => 'Beta', 'societe_code' => 'INCONNU'])
            ->assertStatus(422)->assertJsonValidationErrors('societe_code');
    }

    public function test_affectation_limitee_a_une_seule_societe(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Societe::create(['code' => 'SUD', 'nom' => 'Sud']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'A', 'societe_code' => 'ABN']);
        Etablissement::create(['code' => 'E2', 'intitule' => 'B', 'societe_code' => 'SUD']);
        $role = Role::create(['code' => 'ADMIN', 'nom' => 'Admin']);

        // 1re affectation dans la société ABN : OK
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();

        // Même utilisateur vers un établissement d'une autre société : refusé
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E2', 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('etablissement_code');
    }

    public function test_affectation_anti_doublon(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'A', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'ADMIN', 'nom' => 'Admin']);

        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_utilisateur_detail_liste_affectations(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'École Alpha', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'DIR', 'nom' => 'Direction']);
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();

        $this->getJson('/api/v1/utilisateurs/1')->assertOk()
            ->assertJsonPath('societe_code', 'ABN')
            ->assertJsonPath('affectations.0.etablissement.intitule', 'École Alpha')
            ->assertJsonPath('affectations.0.role.nom', 'Direction');
    }

    public function test_commande_import_peuple_les_tables(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Abidjan Nord', 'VILLESOCIETE' => 'Abidjan'],
        ]);
        Schema::connection('economat')->create('BEtablissements', function ($t) {
            $t->string('CodeEtablissement');
            $t->string('Intitule')->nullable();
            $t->string('Adresse1')->nullable();
            $t->string('Pays')->nullable();
            $t->string('Ville')->nullable();
            $t->string('SiteWeb')->nullable();
            $t->string('Telephone')->nullable();
            $t->string('Email')->nullable();
            $t->string('CodeSociete')->nullable();
        });
        DB::connection('economat')->table('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'Orpheline', 'CodeSociete' => 'ZZZ'],
        ]);

        Artisan::call('console:importer');

        $this->assertDatabaseHas('console_societes', ['code' => 'ABN', 'nom' => 'Abidjan Nord'], 'ecoprim');
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E1', 'societe_code' => 'ABN'], 'ecoprim');
        // établissement rattaché à une société inconnue : ignoré (intégrité)
        $this->assertDatabaseMissing('console_etablissements', ['code' => 'E2'], 'ecoprim');
        $this->assertTrue(Role::count() >= 1);
    }

}
