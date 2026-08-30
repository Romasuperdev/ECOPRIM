<?php

namespace Tests\Feature\Pedagogie;

use App\Models\Niveau;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRUD témoin d'une ressource pédagogique simple (niveaux) : établit le patron de test
 * HTTP authentifié (création, validation, unicité, mise à jour, suppression douce).
 */
class NiveauCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_creation_niveau(): void
    {
        $this->postJson('/api/v1/niveaux', ['code' => 'CP1', 'libelle' => 'Cours Préparatoire 1'])
            ->assertCreated()
            ->assertJsonPath('code', 'CP1');

        $this->assertDatabaseHas('niveaux', ['code' => 'CP1', 'libelle' => 'Cours Préparatoire 1']);
    }

    public function test_code_requis(): void
    {
        $this->postJson('/api/v1/niveaux', ['libelle' => 'Sans code'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_code_unique(): void
    {
        Niveau::create(['code' => 'CE1', 'libelle' => 'Cours Élémentaire 1']);

        $this->postJson('/api/v1/niveaux', ['code' => 'CE1', 'libelle' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_liste_et_mise_a_jour(): void
    {
        $niveau = Niveau::create(['code' => 'CM2', 'libelle' => 'Cours Moyen 2']);

        $this->getJson('/api/v1/niveaux')->assertOk()->assertJsonFragment(['code' => 'CM2']);

        $this->putJson("/api/v1/niveaux/{$niveau->id}", ['code' => 'CM2', 'libelle' => 'CM2 modifié'])
            ->assertOk()
            ->assertJsonPath('libelle', 'CM2 modifié');
    }

    public function test_suppression(): void
    {
        $niveau = Niveau::create(['code' => 'CM1', 'libelle' => 'Cours Moyen 1']);

        $this->deleteJson("/api/v1/niveaux/{$niveau->id}")->assertNoContent();
        $this->assertSoftDeleted('niveaux', ['id' => $niveau->id]);
    }
}
