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

        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true, 'DEBUT' => '2024-09-01'],
            ['CODE' => 2, 'CodeAnnee' => '2025', 'LibelleAnnee' => '2025-2026',
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
        ]);

        DB::connection('economat')->table('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN', 'Ville' => 'Abidjan'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École Bêta', 'CodeSociete' => 'ABN', 'Ville' => 'Bouaké'],
        ]);
    }

    private function connecte(bool $superAdmin, array $extra = []): RhUser
    {
        $rh = RhUser::on('master')->forceCreate(array_merge([
            'Id' => $superAdmin ? 1 : 2, 'Login' => $superAdmin ? 'boss' : 'agent',
            'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'x@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ], $extra));
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

    // --- Établissement de rattachement affiché sans choix explicite ---

    public function test_l_etablissement_de_rattachement_du_compte_est_propose_par_defaut(): void
    {
        $this->connecte(true, ['Etab' => 'E2']);

        $r = $this->getJson('/api/v1/contexte')->assertOk();

        // Aucun choix en session, mais le compte est rattaché : on l'affiche.
        $this->assertSame('E2', $r->json('etablissement_code'));
        $this->assertSame('École Bêta', $r->json('etablissement_nom'));
        $this->assertTrue($r->json('etablissement_par_defaut'));
    }

    public function test_un_choix_explicite_prime_sur_le_rattachement(): void
    {
        $this->connecte(true, ['Etab' => 'E2']);

        $this->postJson('/api/v1/contexte/etablissement', ['code' => 'E1'])->assertOk();

        $r = $this->getJson('/api/v1/contexte')->assertOk();
        $this->assertSame('E1', $r->json('etablissement_code'));
        $this->assertFalse($r->json('etablissement_par_defaut'));
    }

    // --- Année scolaire de consultation ---

    public function test_l_annee_active_est_proposee_par_defaut(): void
    {
        $this->connecte(true);

        $r = $this->getJson('/api/v1/contexte')->assertOk();

        $this->assertSame('2025-2026', $r->json('annee'));
        // La plus récente d'abord.
        $this->assertSame('2025-2026', $r->json('annees.0.libelle'));
        $this->assertCount(2, $r->json('annees'));
    }

    public function test_consulter_une_annee_precedente_meme_cloturee(): void
    {
        $this->connecte(true);

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk()
            ->assertJsonPath('annee', '2024-2025')
            ->assertJsonPath('cloturee', true);

        // Le choix est conservé d'un appel à l'autre.
        $this->getJson('/api/v1/contexte')->assertOk()->assertJsonPath('annee', '2024-2025');
    }

    public function test_une_annee_inconnue_est_refusee(): void
    {
        $this->connecte(true);

        $this->postJson('/api/v1/contexte/annee', ['annee' => '1999-2000'])
            ->assertStatus(422)->assertJsonValidationErrors('annee');
    }

    public function test_le_referentiel_expose_l_etat_de_chaque_annee(): void
    {
        $this->connecte(true);

        $annees = collect($this->getJson('/api/v1/contexte')->assertOk()->json('annees'));

        $this->assertTrue($annees->firstWhere('libelle', '2024-2025')['cloturee']);
        $this->assertFalse($annees->firstWhere('libelle', '2025-2026')['cloturee']);
        $this->assertTrue($annees->firstWhere('libelle', '2025-2026')['active']);
    }
}
