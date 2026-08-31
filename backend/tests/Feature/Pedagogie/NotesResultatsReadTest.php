<?php

namespace Tests\Feature\Pedagogie;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Notes (V_NOTECLASSE) et résultats/moyennes (V_MOYENNE_ELEVE_CLASSE) en lecture seule. */
class NotesResultatsReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'u', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'u@ecole.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
    }

    public function test_notes_par_classe(): void
    {
        DB::connection('economat')->table('V_NOTECLASSE')->insert([
            'Code' => 1, 'Matricule' => 'E1', 'Nom' => 'Yao', 'Prenom' => 'Ama', 'Note' => 14.5,
            'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Maths', 'TypeNote' => 'Devoir', 'CodeClasse' => 'CP1-A',
        ]);

        $this->getJson('/api/v1/notes?classe_code=CP1-A')->assertOk()
            ->assertJsonFragment(['matricule' => 'E1', 'nom' => 'Yao', 'note' => 14.5, 'matiere_libelle' => 'Maths', 'type_note' => 'Devoir']);
    }

    public function test_moyennes_classe_classement(): void
    {
        DB::connection('economat')->table('T_CLASSE')->insert(['num' => 1, 'CodeClasse' => 'CP1-A', 'LibelleClasse' => 'CP1 A']);
        DB::connection('economat')->table('V_MOYENNE_ELEVE_CLASSE')->insert([
            ['Code' => 1, 'Nom' => 'Yao', 'Prenom' => 'Ama', 'Moyenne' => 12, 'Rang' => '2', 'CodeEleve' => 10, 'Matricule' => 'E1', 'CodeClasse' => 'CP1-A'],
            ['Code' => 2, 'Nom' => 'Kone', 'Prenom' => 'Ali', 'Moyenne' => 15, 'Rang' => '1', 'CodeEleve' => 11, 'Matricule' => 'E2', 'CodeClasse' => 'CP1-A'],
        ]);

        $r = $this->getJson('/api/v1/classes/CP1-A/moyennes')->assertOk()
            ->assertJsonPath('classe', 'CP1 A')
            ->assertJsonPath('moyenne_classe', 13.5);

        $data = $r->json();
        $this->assertSame('E2', $data['classement'][0]['matricule']); // meilleure moyenne d'abord
    }
}
