<?php

namespace Tests\Feature\Console;

use App\Models\Etablissement;
use App\Models\Societe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sécurité de la Console au niveau HTTP : les FormRequest::authorize() de périmètre
 * (StoreEtablissementRequest, StoreAffectationRequest) doivent refuser toute création
 * hors du périmètre de l'utilisateur, au-delà du scope global déjà testé.
 */
class ConsolePerimetreHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function adminSociete(Societe $societe): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin Société');
        \App\Models\Affectation::factory()->create([
            'user_id' => $user->id,
            'societe_id' => $societe->id,
            'role_id' => Role::findByName('Admin Société', 'web')->id,
        ]);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_super_admin_cree_un_etablissement_sous_nimporte_quelle_societe(): void
    {
        $societe = Societe::factory()->create();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson('/api/v1/etablissements', [
            'societe_id' => $societe->id, 'code' => 'ETB-SA', 'nom' => 'École du centre',
        ])->assertCreated();

        $this->assertDatabaseHas('etablissements', ['code' => 'ETB-SA', 'societe_id' => $societe->id]);
    }

    public function test_admin_societe_cree_un_etablissement_dans_sa_societe(): void
    {
        $a = Societe::factory()->create();
        Sanctum::actingAs($this->adminSociete($a));

        $this->postJson('/api/v1/etablissements', [
            'societe_id' => $a->id, 'code' => 'ETB-A1', 'nom' => 'École A1',
        ])->assertCreated();
    }

    public function test_admin_societe_ne_peut_pas_creer_hors_de_sa_societe(): void
    {
        $a = Societe::factory()->create();
        $b = Societe::factory()->create();
        Sanctum::actingAs($this->adminSociete($a));

        $this->postJson('/api/v1/etablissements', [
            'societe_id' => $b->id, 'code' => 'ETB-B1', 'nom' => 'École B1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('etablissements', ['code' => 'ETB-B1']);
    }

    public function test_role_sans_gouvernance_est_refuse(): void
    {
        $a = Societe::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('Enseignant');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/etablissements', [
            'societe_id' => $a->id, 'code' => 'ETB-X', 'nom' => 'École X',
        ])->assertForbidden();
    }

    public function test_admin_societe_ne_peut_pas_affecter_hors_de_sa_societe(): void
    {
        $a = Societe::factory()->create();
        $b = Societe::factory()->create();
        $cible = User::factory()->create();
        Sanctum::actingAs($this->adminSociete($a));

        $this->postJson('/api/v1/affectations', [
            'user_id' => $cible->id,
            'societe_id' => $b->id,
            'role_id' => Role::findByName('Enseignant', 'web')->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('affectations', ['user_id' => $cible->id, 'societe_id' => $b->id]);
    }

    public function test_admin_societe_affecte_dans_sa_societe(): void
    {
        $a = Societe::factory()->create();
        $cible = User::factory()->create();
        Sanctum::actingAs($this->adminSociete($a));

        $this->postJson('/api/v1/affectations', [
            'user_id' => $cible->id,
            'societe_id' => $a->id,
            'role_id' => Role::findByName('Enseignant', 'web')->id,
        ])->assertCreated();

        $this->assertDatabaseHas('affectations', ['user_id' => $cible->id, 'societe_id' => $a->id]);
    }
}
