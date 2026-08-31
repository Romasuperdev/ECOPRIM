<?php

namespace Tests\Feature\Console;

use App\Models\Affectation;
use App\Models\Etablissement;
use App\Models\Societe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Contrôle d'accès par périmètre (trait BelongsToPerimetre + User::allowed*Ids).
 * Vérifie le fail-closed, le bypass Super Admin et l'isolation Admin Société /
 * Admin Établissement — la logique de sécurité la plus sensible de la Console.
 */
class PerimetreScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function utilisateurAvecAffectation(array $attrs, ?string $role = null): User
    {
        $user = User::factory()->create();
        if ($role) {
            $user->assignRole($role);
        }
        Affectation::factory()->create(array_merge([
            'user_id' => $user->id,
            'role_id' => Role::findByName($role ?? 'Enseignant', 'web')->id,
        ], $attrs));

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    public function test_super_admin_voit_tous_les_etablissements(): void
    {
        $a = Societe::factory()->create();
        $b = Societe::factory()->create();
        Etablissement::factory()->create(['societe_id' => $a->id]);
        Etablissement::factory()->create(['societe_id' => $a->id]);
        Etablissement::factory()->create(['societe_id' => $b->id]);

        $this->actingAs($this->superAdmin());

        $this->assertCount(3, Etablissement::all());
    }

    public function test_admin_societe_ne_voit_que_ses_etablissements(): void
    {
        $a = Societe::factory()->create();
        $b = Societe::factory()->create();
        $e1 = Etablissement::factory()->create(['societe_id' => $a->id]);
        $e2 = Etablissement::factory()->create(['societe_id' => $a->id]);
        Etablissement::factory()->create(['societe_id' => $b->id]);

        $user = $this->utilisateurAvecAffectation(['societe_id' => $a->id], 'Admin Société');
        $this->actingAs($user);

        $ids = Etablissement::pluck('id')->sort()->values()->all();
        $this->assertEquals([$e1->id, $e2->id], $ids);
    }

    public function test_admin_etablissement_ne_voit_que_le_sien(): void
    {
        $a = Societe::factory()->create();
        $e1 = Etablissement::factory()->create(['societe_id' => $a->id]);
        Etablissement::factory()->create(['societe_id' => $a->id]);

        $user = $this->utilisateurAvecAffectation(['etablissement_id' => $e1->id], 'Admin Établissement');
        $this->actingAs($user);

        $this->assertEquals([$e1->id], Etablissement::pluck('id')->all());
    }

    public function test_sans_affectation_ne_voit_rien_fail_closed(): void
    {
        $a = Societe::factory()->create();
        Etablissement::factory()->count(2)->create(['societe_id' => $a->id]);

        $user = User::factory()->create();
        $user->assignRole('Direction');
        $this->actingAs($user);

        $this->assertCount(0, Etablissement::all());
    }

    public function test_societe_desactivee_retire_le_perimetre(): void
    {
        $a = Societe::factory()->inactif()->create();
        Etablissement::factory()->count(2)->create(['societe_id' => $a->id]);

        $user = $this->utilisateurAvecAffectation(['societe_id' => $a->id], 'Admin Société');
        $this->actingAs($user);

        $this->assertSame([], $user->allowedSocieteIds());
        $this->assertCount(0, Etablissement::all());
    }

    public function test_etablissement_desactive_retire_le_perimetre(): void
    {
        $a = Societe::factory()->create();
        $e1 = Etablissement::factory()->inactif()->create(['societe_id' => $a->id]);

        $user = $this->utilisateurAvecAffectation(['etablissement_id' => $e1->id], 'Admin Établissement');
        $this->actingAs($user);

        $this->assertSame([], $user->allowedEtablissementIds());
        $this->assertCount(0, Etablissement::all());
    }

    public function test_affectation_inactive_ou_expiree_est_ignoree(): void
    {
        $a = Societe::factory()->create();
        Etablissement::factory()->create(['societe_id' => $a->id]);

        $user = User::factory()->create();
        Affectation::factory()->inactive()->create([
            'user_id' => $user->id, 'societe_id' => $a->id,
            'role_id' => Role::findByName('Admin Société', 'web')->id,
        ]);
        Affectation::factory()->expiree()->create([
            'user_id' => $user->id, 'societe_id' => $a->id,
            'role_id' => Role::findByName('Admin Société', 'web')->id,
        ]);
        $this->actingAs($user);

        $this->assertSame([], $user->allowedSocieteIds());
        $this->assertCount(0, Etablissement::all());
    }

    public function test_withoutPerimetre_contourne_le_scope(): void
    {
        $a = Societe::factory()->create();
        Etablissement::factory()->count(2)->create(['societe_id' => $a->id]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertCount(0, Etablissement::all());
        $this->assertCount(2, Etablissement::withoutPerimetre()->get());
    }

    public function test_scope_affectations_isole_par_societe(): void
    {
        $a = Societe::factory()->create();
        $b = Societe::factory()->create();
        $ea = Etablissement::factory()->create(['societe_id' => $a->id]);
        $eb = Etablissement::factory()->create(['societe_id' => $b->id]);

        // une affectation dans A (visible) et une dans B (hors périmètre)
        Affectation::factory()->create([
            'user_id' => User::factory()->create()->id, 'etablissement_id' => $ea->id,
            'role_id' => Role::findByName('Enseignant', 'web')->id,
        ]);
        Affectation::factory()->create([
            'user_id' => User::factory()->create()->id, 'etablissement_id' => $eb->id,
            'role_id' => Role::findByName('Enseignant', 'web')->id,
        ]);

        $adminA = $this->utilisateurAvecAffectation(['societe_id' => $a->id], 'Admin Société');
        $this->actingAs($adminA);

        // Son affectation propre (societe A) + celle de l'établissement de A, jamais celle de B.
        $etabIds = Affectation::with('etablissement')->get()
            ->pluck('etablissement_id')->filter()->unique()->values()->all();
        $this->assertNotContains($eb->id, $etabIds);
        $this->assertContains($ea->id, $etabIds);
    }

    public function test_resolution_perimetre_sans_recursion(): void
    {
        $a = Societe::factory()->create();
        $user = $this->utilisateurAvecAffectation(['societe_id' => $a->id], 'Admin Société');
        $this->actingAs($user);

        // Si le scope global rebouclait sur lui-même, ceci provoquerait un stack overflow.
        $this->assertSame([$a->id], $user->allowedSocieteIds());
        $this->assertIsArray($user->allowedEtablissementIds());
    }
}
