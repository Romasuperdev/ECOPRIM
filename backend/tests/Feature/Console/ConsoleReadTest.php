<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Console (Sociétés/Établissements/Utilisateurs) en lecture seule sur dbmasterbacou. */
class ConsoleReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@ecole.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
    }

    public function test_societes_avec_statut_suspension(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Abidjan Nord', 'VILLESOCIETE' => 'Abidjan'],
            ['CODESOCIETE' => 'SUD', 'NOMSOCIETE' => 'Sud SARL', 'VILLESOCIETE' => 'San Pedro'],
        ]);
        DB::connection('master')->table('ECO_SOCIETE_SUSPENSION')->insert(['CODESOCIETE' => 'SUD', 'SUSPENDU' => true]);

        $this->getJson('/api/v1/societes')->assertOk()
            ->assertJsonFragment(['code' => 'ABN', 'nom' => 'Abidjan Nord', 'statut' => 'actif'])
            ->assertJsonFragment(['code' => 'SUD', 'statut' => 'inactif']);
    }

    public function test_etablissements(): void
    {
        DB::connection('master')->table('T_ETABLISSEMENT')->insert([
            'Num' => 5, 'CODE' => 'ETB1', 'RAISONSOCIALE' => 'École Alpha', 'TYPE' => 'primaire', 'STATUT' => 'actif',
        ]);

        $this->getJson('/api/v1/etablissements')->assertOk()
            ->assertJsonFragment(['id' => 5, 'code' => 'ETB1', 'nom' => 'École Alpha', 'type' => 'primaire', 'statut' => 'actif']);
    }

    public function test_utilisateurs_sans_mot_de_passe(): void
    {
        $r = $this->getJson('/api/v1/utilisateurs')->assertOk()
            ->assertJsonFragment(['login' => 'boss', 'actif' => true]);

        $ligne = $r->json('data.0');
        $this->assertArrayNotHasKey('MotDePasse', $ligne);
        $this->assertArrayNotHasKey('password', $ligne);
    }

    public function test_utilisateur_detail_avec_roles(): void
    {
        DB::connection('master')->table('users')->insert(['id' => 20, 'name' => 'X', 'email' => 'x@e.ci', 'password' => 'y']);
        DB::connection('master')->table('roles')->insert(['id' => 3, 'name' => 'Direction']);
        DB::connection('master')->table('role_user')->insert(['id' => 1, 'user_id' => 20, 'role_id' => 3]);
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 9, 'Login' => 'dir', 'Nom' => 'Yao', 'Prenom' => 'Ama', 'Email' => 'dir@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false, 'user_id' => 20,
        ]);

        $this->getJson('/api/v1/utilisateurs/9')->assertOk()
            ->assertJsonPath('login', 'dir')
            ->assertJsonFragment(['roles' => ['Direction']]);
    }
}
