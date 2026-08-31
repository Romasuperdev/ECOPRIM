<?php

namespace Tests\Feature\Auth;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Authentification 100 % lecture seule contre dbmasterbacou (RH_USER + rôles).
 * Aucun compte local n'est créé : identité, rôles et périmètre sont lus dans dbmasterbacou.
 */
class RhUserAuthTest extends TestCase
{
    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
    }

    private function creerRh(array $a = []): array
    {
        $a = array_merge([
            'Id' => 1, 'Login' => 'jdupont', 'Nom' => 'Dupont', 'Prenom' => 'Jean',
            'Email' => 'jean@ecole.ci', 'Matricule' => 'MAT1',
            'MotDePasse' => Hash::make('secret'),
            'SuperAdmin' => false, 'Supprimer' => false, 'user_id' => null,
        ], $a);
        DB::connection('master')->table('RH_USER')->insert($a);

        return $a;
    }

    private function login(string $identifiant, string $password = 'secret')
    {
        return $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => $identifiant, 'password' => $password]);
    }

    public function test_login_valide_renvoie_identite_sans_ecrire(): void
    {
        $this->creerRh(['Email' => 'admin@ecole.ci']);

        $this->login('admin@ecole.ci')
            ->assertOk()
            ->assertJsonPath('email', 'admin@ecole.ci')
            ->assertJsonPath('name', 'Jean Dupont')
            ->assertJsonPath('roles', []);
    }

    public function test_bit_super_admin_donne_le_role(): void
    {
        $this->creerRh(['Email' => 'boss@ecole.ci', 'SuperAdmin' => true]);

        $this->login('boss@ecole.ci')
            ->assertOk()
            ->assertJsonFragment(['roles' => ['Super Admin']]);
    }

    public function test_roles_derives_de_role_user(): void
    {
        $this->creerRh(['Email' => 'dir@ecole.ci', 'user_id' => 10]);
        DB::connection('master')->table('users')->insert(['id' => 10, 'name' => 'Jean', 'email' => 'dir@ecole.ci', 'password' => 'x']);
        DB::connection('master')->table('roles')->insert(['id' => 5, 'code' => 'DIR', 'name' => 'Direction']);
        DB::connection('master')->table('role_user')->insert(['id' => 1, 'user_id' => 10, 'role_id' => 5]);

        $this->login('dir@ecole.ci')
            ->assertOk()
            ->assertJsonFragment(['roles' => ['Direction']]);
    }

    public function test_connexion_par_login_et_matricule(): void
    {
        $this->creerRh(['Login' => 'prof9', 'Matricule' => 'M9', 'Email' => 'p9@ecole.ci']);

        $this->login('prof9')->assertOk()->assertJsonPath('email', 'p9@ecole.ci');
        $this->login('M9')->assertOk()->assertJsonPath('email', 'p9@ecole.ci');
    }

    public function test_mot_de_passe_incorrect(): void
    {
        $this->creerRh(['Email' => 'a@ecole.ci']);
        $this->login('a@ecole.ci', 'faux')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_compte_supprime_refuse(): void
    {
        $this->creerRh(['Email' => 'vire@ecole.ci', 'Supprimer' => true]);
        $this->login('vire@ecole.ci')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_restriction_code_app(): void
    {
        config(['ecoprim.code_app' => 'ECOPRIM']);
        $this->creerRh(['Id' => 1, 'Email' => 'autre@ecole.ci', 'CodeApp' => 'AUTRE']);
        $this->login('autre@ecole.ci')->assertStatus(422);

        $this->creerRh(['Id' => 2, 'Login' => 'ok', 'Email' => 'ok@ecole.ci', 'CodeApp' => 'ECOPRIM']);
        $this->login('ok@ecole.ci')->assertOk();
    }

    public function test_me_renvoie_identite(): void
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 7, 'Login' => 'x', 'Nom' => 'Koffi', 'Prenom' => 'Ama', 'Email' => 'ama@ecole.ci',
            'MotDePasse' => Hash::make('secret'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email', 'ama@ecole.ci')
            ->assertJsonFragment(['roles' => ['Super Admin']]);
    }
}
