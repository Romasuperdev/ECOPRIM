<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Authentification contre dbmasterbacou.RH_USER puis réplication locale
 * (AuthController::login + syncLocalUser). RH_USER est simulée sur une base
 * SQLite en mémoire branchée sur la connexion `master`.
 */
class RhUserSyncTest extends TestCase
{
    use RefreshDatabase;

    /** En-têtes qui font traiter la requête comme SPA « stateful » par Sanctum. */
    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->fakeMasterRhUser();
    }

    private function creerRhUser(array $attrs = []): array
    {
        $attrs = array_merge([
            'Login' => 'jdupont',
            'Nom' => 'Dupont',
            'Prenom' => 'Jean',
            'Email' => 'jean.dupont@ecole.ci',
            'Matricule' => 'MAT001',
            'MotDePasse' => Hash::make('secret'),
            'SuperAdmin' => false,
            'Supprimer' => false,
        ], $attrs);

        DB::connection('master')->table('RH_USER')->insert($attrs);

        return $attrs;
    }

    public function test_login_valide_cree_utilisateur_local_et_attribue_super_admin(): void
    {
        $this->creerRhUser(['Email' => 'admin@ecole.ci', 'SuperAdmin' => true]);

        $response = $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'admin@ecole.ci', 'password' => 'secret']);

        $response->assertOk()
            ->assertJsonPath('email', 'admin@ecole.ci')
            ->assertJsonFragment(['roles' => ['Super Admin']]);

        $this->assertDatabaseHas('users', ['email' => 'admin@ecole.ci', 'name' => 'Jean Dupont']);
        $this->assertTrue(User::where('email', 'admin@ecole.ci')->first()->hasRole('Super Admin'));
    }

    public function test_utilisateur_non_superadmin_ne_recoit_aucun_role(): void
    {
        $this->creerRhUser(['Email' => 'prof@ecole.ci', 'SuperAdmin' => false]);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'prof@ecole.ci', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('roles', []);

        $this->assertFalse(User::where('email', 'prof@ecole.ci')->first()->hasRole('Super Admin'));
    }

    public function test_mot_de_passe_incorrect_est_rejete(): void
    {
        $this->creerRhUser(['Email' => 'admin@ecole.ci']);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'admin@ecole.ci', 'password' => 'mauvais'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'admin@ecole.ci']);
    }

    public function test_email_inconnu_est_rejete(): void
    {
        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'inconnu@ecole.ci', 'password' => 'secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_repete_ne_duplique_pas_le_compte_local(): void
    {
        $this->creerRhUser(['Email' => 'admin@ecole.ci', 'SuperAdmin' => true]);

        $this->withHeaders($this->spa)->postJson('/api/v1/login', ['email' => 'admin@ecole.ci', 'password' => 'secret'])->assertOk();
        $this->withHeaders($this->spa)->postJson('/api/v1/login', ['email' => 'admin@ecole.ci', 'password' => 'secret'])->assertOk();

        $this->assertSame(1, User::where('email', 'admin@ecole.ci')->count());
    }
    public function test_connexion_par_login_fonctionne(): void
    {
        $this->creerRhUser(['Login' => 'prof99', 'Email' => 'prof99@ecole.ci']);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'prof99', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('email', 'prof99@ecole.ci');
    }

    public function test_connexion_par_matricule_fonctionne(): void
    {
        $this->creerRhUser(['Matricule' => 'MAT777', 'Email' => 'mat777@ecole.ci']);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'MAT777', 'password' => 'secret'])
            ->assertOk()
            ->assertJsonPath('email', 'mat777@ecole.ci');
    }

    public function test_compte_supprime_est_refuse(): void
    {
        $this->creerRhUser(['Email' => 'vire@ecole.ci', 'Supprimer' => true]);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'vire@ecole.ci', 'password' => 'secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'vire@ecole.ci']);
    }

    public function test_restriction_code_app(): void
    {
        config(['ecoprim.code_app' => 'ECOPRIM']);
        $this->creerRhUser(['Email' => 'autre@ecole.ci', 'CodeApp' => 'AUTRE']);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'autre@ecole.ci', 'password' => 'secret'])
            ->assertStatus(422);

        $this->creerRhUser(['Login' => 'ok', 'Email' => 'ok@ecole.ci', 'CodeApp' => 'ECOPRIM']);
        $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => 'ok@ecole.ci', 'password' => 'secret'])
            ->assertOk();
    }
}
