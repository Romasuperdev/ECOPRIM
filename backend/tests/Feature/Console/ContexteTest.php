<?php

namespace Tests\Feature\Console;

use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\Console\Societe;
use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Contexte de travail (« Choisir un établissement », repris de BACOU) :
 * choix conservé en session, limité aux établissements accessibles à l'utilisateur.
 */
class ContexteTest extends TestCase
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
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);

        DB::connection('economat')->table('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN', 'Ville' => 'Abidjan'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École Bêta', 'CodeSociete' => 'ABN', 'Ville' => 'Bouaké'],
        ]);
    }

    private function connecte(bool $superAdmin): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $superAdmin ? 1 : 2, 'Login' => $superAdmin ? 'boss' : 'agent',
            'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'x@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ]);
        // Requêtes « stateful » (comme le SPA) pour que la session soit démarrée.
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173',
        ]);
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    public function test_super_admin_voit_tous_les_etablissements(): void
    {
        $this->connecte(true);

        $r = $this->getJson('/api/v1/contexte')->assertOk();
        $this->assertNull($r->json('etablissement_code'));
        $this->assertCount(2, $r->json('disponibles'));
    }

    public function test_utilisateur_ne_voit_que_ses_affectations(): void
    {
        $rh = $this->connecte(false);
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'École Alpha', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'ENS', 'nom' => 'Enseignant']);
        Affectation::create([
            'rh_user_id' => $rh->Id, 'societe_code' => 'ABN', 'etablissement_code' => 'E1', 'role_id' => $role->id,
        ]);

        $r = $this->getJson('/api/v1/contexte')->assertOk();
        $this->assertCount(1, $r->json('disponibles'));
        $this->assertSame('E1', $r->json('disponibles.0.code'));
    }

    public function test_choisir_puis_quitter_un_etablissement(): void
    {
        $this->connecte(true);

        $this->postJson('/api/v1/contexte/etablissement', ['code' => 'E1'])->assertOk()
            ->assertJsonPath('etablissement_code', 'E1')
            ->assertJsonPath('etablissement_nom', 'École Alpha');

        $this->getJson('/api/v1/contexte')->assertOk()->assertJsonPath('etablissement_code', 'E1');

        $this->deleteJson('/api/v1/contexte/etablissement')->assertOk()
            ->assertJsonPath('etablissement_code', null);

        $this->getJson('/api/v1/contexte')->assertOk()->assertJsonPath('etablissement_code', null);
    }

    public function test_impossible_de_choisir_un_etablissement_non_accessible(): void
    {
        $this->connecte(false); // aucune affectation

        $this->postJson('/api/v1/contexte/etablissement', ['code' => 'E1'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_un_etablissement_desactive_n_est_pas_proposable(): void
    {
        $this->connecte(true);
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'École Alpha', 'societe_code' => 'ABN', 'actif' => false]);

        $r = $this->getJson('/api/v1/contexte')->assertOk();
        $codes = collect($r->json('disponibles'))->pluck('code')->all();
        $this->assertNotContains('E1', $codes);

        $this->postJson('/api/v1/contexte/etablissement', ['code' => 'E1'])->assertStatus(422);
    }
}
