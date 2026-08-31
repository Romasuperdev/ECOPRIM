<?php

namespace Tests\Feature\Pedagogie;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Classes (T_CLASSE), Enseignants (T_PROFESSEUR) et Élèves (T_ETUDIANT) en lecture seule.
 * Vérifie aussi que les données financières ne sont jamais exposées.
 */
class PedagogieReadTest extends TestCase
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

    public function test_classes_mappees_avec_niveau(): void
    {
        DB::connection('economat')->table('T_NIVEAU')->insert(['Num' => 1, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'CP1', 'Ordre' => 1]);
        DB::connection('economat')->table('T_CLASSE')->insert(['num' => 4, 'CodeClasse' => 'CP1-A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => '2025']);

        $this->getJson('/api/v1/classes')->assertOk()
            ->assertJsonFragment(['id' => 4, 'code' => 'CP1-A', 'nom' => 'CP1 A', 'niveau_code' => 'CP1'])
            ->assertJsonPath('data.0.niveau.libelle', 'CP1');
    }

    public function test_enseignants_sans_salaire(): void
    {
        DB::connection('economat')->table('T_PROFESSEUR')->insert([
            'Code' => 9, 'MatriculeProfesseur' => 'P9', 'NomProfesseur' => 'Kone', 'PrenomProfesseur' => 'Awa',
            'EmailProfesseur' => 'awa@ecole.ci', 'TypeProfesseur' => 'Titulaire', 'Matiere' => 'Maths', 'SalaireMensuel' => 500000,
        ]);

        $reponse = $this->getJson('/api/v1/enseignants')->assertOk()
            ->assertJsonFragment(['id' => 9, 'matricule' => 'P9', 'nom' => 'Kone', 'statut' => 'Titulaire']);

        $ligne = $reponse->json('data.0');
        $this->assertArrayNotHasKey('SalaireMensuel', $ligne);
        $this->assertArrayNotHasKey('Mdp', $ligne);
    }

    public function test_eleves_mappes_avec_classe_sans_finance(): void
    {
        DB::connection('economat')->table('T_CLASSE')->insert(['num' => 4, 'CodeClasse' => 'CP1-A', 'LibelleClasse' => 'CP1 A']);
        DB::connection('economat')->table('T_ETUDIANT')->insert([
            'Code' => 100, 'Matricule' => 'E100', 'Nom' => 'Yao', 'Prenom' => 'Ama', 'Sexe' => 'F',
            'CodeClasse' => 'CP1-A', 'AnneeAcad' => '2025', 'Etat' => 1,
            'NomPereTuteur' => 'Yao', 'PrenomPereTuteur' => 'Koffi', 'NomMere' => 'Aka', 'Scolarite' => 350000,
        ]);

        $reponse = $this->getJson('/api/v1/eleves')->assertOk()
            ->assertJsonFragment(['id' => 100, 'matricule' => 'E100', 'nom' => 'Yao', 'pere_nom' => 'Yao'])
            ->assertJsonPath('data.0.classe.nom', 'CP1 A');

        $ligne = $reponse->json('data.0');
        $this->assertArrayNotHasKey('Scolarite', $ligne);
        $this->assertArrayNotHasKey('TotalPaye', $ligne);
    }
}
