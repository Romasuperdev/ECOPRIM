<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Calendrier scolaire (App\Models\Evenement, table ecoprim.evenements) : congés/vacances,
 * réunions parents-professeurs, sorties et activités pédagogiques — un seul modèle
 * d'événement, distingué par type.
 */
class EvenementTest extends TestCase
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

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
            ['CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true, 'DEBUT' => '2024-09-01'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2', 'ANNEE' => self::ANNEE],
        ]);
    }

    private function evenement(array $extra = []): array
    {
        return array_merge([
            'titre' => 'Vacances de la Toussaint', 'type' => 'vacances',
            'date_debut' => '2026-10-24', 'date_fin' => '2026-11-02',
        ], $extra);
    }

    public function test_les_referentiels_exposent_les_types_et_les_classes(): void
    {
        $r = $this->getJson('/api/v1/evenements/referentiels')->assertOk();

        $this->assertSame('CM2 A', $r->json('classes.0.libelle'));
        $this->assertContains(['code' => 'reunion', 'libelle' => 'Réunion parents-professeurs'], $r->json('types'));
    }

    public function test_creer_un_evenement_pour_tout_l_etablissement(): void
    {
        $r = $this->postJson('/api/v1/evenements', $this->evenement())->assertCreated();

        $this->assertSame('Vacances de la Toussaint', $r->json('titre'));
        $this->assertSame('Congés / Vacances scolaires', $r->json('type_libelle'));
        $this->assertSame('2026-10-24', $r->json('date_debut'));
        $this->assertSame('2026-11-02', $r->json('date_fin'));
        $this->assertNull($r->json('classe'));
        $this->assertSame(self::ANNEE, $r->json('annee'));

        $this->assertDatabaseHas('evenements', ['titre' => 'Vacances de la Toussaint', 'type' => 'vacances'], 'ecoprim');
    }

    public function test_creer_un_evenement_borne_a_une_classe(): void
    {
        $r = $this->postJson('/api/v1/evenements', $this->evenement([
            'titre' => 'Réunion parents CM2 A', 'type' => 'reunion', 'classe' => 'CM2A',
            'date_debut' => '2026-11-10', 'date_fin' => '2026-11-10', 'lieu' => 'Salle CM2 A',
        ]))->assertCreated();

        $this->assertSame('CM2 A', $r->json('classe_libelle'));
        $this->assertSame('Salle CM2 A', $r->json('lieu'));
    }

    public function test_champs_obligatoires(): void
    {
        $this->postJson('/api/v1/evenements', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titre', 'type', 'date_debut', 'date_fin']);
    }

    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/evenements', $this->evenement(['type' => 'fantome']))
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_la_date_de_fin_ne_peut_pas_preceder_la_date_de_debut(): void
    {
        $this->postJson('/api/v1/evenements', $this->evenement(['date_debut' => '2026-11-02', 'date_fin' => '2026-10-24']))
            ->assertStatus(422)->assertJsonValidationErrors('date_fin');
    }

    public function test_une_classe_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/evenements', $this->evenement(['classe' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('classe');
    }

    public function test_lister_et_filtrer_par_type(): void
    {
        $this->postJson('/api/v1/evenements', $this->evenement())->assertCreated();
        $this->postJson('/api/v1/evenements', $this->evenement(['titre' => 'Sortie au zoo', 'type' => 'sortie']))
            ->assertCreated();

        $r = $this->getJson('/api/v1/evenements?type=sortie')->assertOk();
        $this->assertCount(1, $r->json());
        $this->assertSame('Sortie au zoo', $r->json('0.titre'));
    }

    public function test_modifier_puis_supprimer_un_evenement(): void
    {
        $id = $this->postJson('/api/v1/evenements', $this->evenement())->assertCreated()->json('id');

        $this->putJson("/api/v1/evenements/{$id}", $this->evenement(['titre' => 'Vacances corrigées']))
            ->assertOk()->assertJsonPath('titre', 'Vacances corrigées');

        $this->deleteJson("/api/v1/evenements/{$id}")->assertNoContent();
        $this->assertDatabaseCount('evenements', 0, 'ecoprim');
    }

    public function test_une_annee_cloturee_verrouille_creation_modification_et_suppression(): void
    {
        $id = $this->postJson('/api/v1/evenements', $this->evenement())->assertCreated()->json('id');

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();
        $this->postJson('/api/v1/evenements', $this->evenement())->assertStatus(423);

        $this->putJson("/api/v1/evenements/{$id}", $this->evenement(['titre' => 'X']))->assertOk();
    }
}
