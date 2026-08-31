<?php

namespace Tests\Feature\Pedagogie;

use App\Models\AnneeScolaire;
use App\Models\User;
use App\Support\AnneeScolaireGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Règle de gestion : une année scolaire clôturée devient en lecture seule,
 * seul un Super Admin peut forcer explicitement (?force=1).
 */
class AnneeScolaireGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function requete(?User $user, bool $force = false): Request
    {
        $request = Request::create('/', 'POST', $force ? ['force' => 1] : []);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function assertBloque(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Une HttpException 423 était attendue.');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }
    }

    public function test_id_null_ne_bloque_jamais(): void
    {
        AnneeScolaireGuard::assertModifiable(null, $this->requete(User::factory()->create()));
        $this->assertTrue(true);
    }

    public function test_annee_ouverte_est_modifiable(): void
    {
        $annee = AnneeScolaire::factory()->create(['cloturee' => false]);
        AnneeScolaireGuard::assertModifiable($annee->id, $this->requete(User::factory()->create()));
        $this->assertTrue(true);
    }

    public function test_annee_cloturee_bloque_un_utilisateur_standard(): void
    {
        $annee = AnneeScolaire::factory()->cloturee()->create();
        $user = User::factory()->create();
        $user->assignRole('Enseignant');

        $this->assertBloque(fn () => AnneeScolaireGuard::assertModifiable($annee->id, $this->requete($user)));
    }

    public function test_super_admin_sans_force_reste_bloque(): void
    {
        $annee = AnneeScolaire::factory()->cloturee()->create();
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $this->assertBloque(fn () => AnneeScolaireGuard::assertModifiable($annee->id, $this->requete($user, false)));
    }

    public function test_super_admin_avec_force_peut_modifier(): void
    {
        $annee = AnneeScolaire::factory()->cloturee()->create();
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        AnneeScolaireGuard::assertModifiable($annee->id, $this->requete($user, true));
        $this->assertTrue(true);
    }

    public function test_non_super_admin_avec_force_reste_bloque(): void
    {
        $annee = AnneeScolaire::factory()->cloturee()->create();
        $user = User::factory()->create();
        $user->assignRole('Direction');

        $this->assertBloque(fn () => AnneeScolaireGuard::assertModifiable($annee->id, $this->requete($user, true)));
    }
}
