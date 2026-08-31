<?php

namespace Tests\Feature\Pedagogie;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Absences élèves en lecture seule sur ECONOMAT.T_ABSENCEELEVE. */
class AbsenceReadTest extends TestCase
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

    public function test_absences_mappees_avec_eleve(): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->insert(['Code' => 50, 'Matricule' => 'E50', 'Nom' => 'Yao', 'Prenom' => 'Ama']);
        DB::connection('economat')->table('T_ABSENCEELEVE')->insert([
            'Code' => 1, 'Matricule' => 'E50', 'CodeClasse' => 'CP1-A', 'Date' => '2025-10-01',
            'Cause' => 'Maladie', 'CodeEleve' => 50, 'Justifier' => true,
        ]);

        $this->getJson('/api/v1/absences?classe_code=CP1-A')->assertOk()
            ->assertJsonFragment(['id' => 1, 'matricule' => 'E50', 'motif' => 'Maladie', 'justifiee' => true])
            ->assertJsonPath('data.0.eleve.nom', 'Yao');
    }
}
