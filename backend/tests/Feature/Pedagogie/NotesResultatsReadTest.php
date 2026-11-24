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
        $this->assertSame('economat', $data['source']);
    }

    /**
     * ECONOMAT n'a pas encore lancé son calcul : les moyennes sont calculées depuis les
     * notes, au barème du primaire (points obtenus / points possibles, sur 20).
     */
    public function test_sans_moyennes_economat_elles_sont_calculees_depuis_les_notes(): void
    {
        $eco = fn ($t) => DB::connection('economat')->table($t);
        $eco('T_CLASSE')->insert(['num' => 1, 'CodeClasse' => 'CM1', 'LibelleClasse' => 'CM1']);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'E1', 'Nom' => 'Yao', 'Prenom' => 'Ama', 'CodeClasse' => 'CM1'],
            ['Code' => 2, 'Matricule' => 'E2', 'Nom' => 'Kone', 'Prenom' => 'Ali', 'CodeClasse' => 'CM1'],
            ['Code' => 3, 'Matricule' => 'E3', 'Nom' => 'Bamba', 'Prenom' => 'Ina', 'CodeClasse' => 'CM1'],
            ['Code' => 4, 'Matricule' => 'E4', 'Nom' => 'Zadi', 'Prenom' => 'Luc', 'CodeClasse' => 'CM1'],
        ]);

        $note = fn ($entete, $mat, $matiere, $n, $coef, $session = 'S1') => [
            'Code' => $entete, 'Matricule' => $mat, 'Nom' => 'x', 'Prenom' => 'x', 'Note' => $n,
            'CodeMatiere' => $matiere, 'LibelleMatiere' => $matiere, 'TypeNote' => 'Composition',
            'CodeClasse' => 'CM1', 'CodeSession' => $session, 'Coefficient' => $coef,
        ];
        $eco('V_NOTECLASSE')->insert([
            // Maths sur 50 (coefficient ECONOMAT 2,5), Dictée sur 20 (coefficient 1).
            $note(1, 'E1', 'MATH', 40, 2.5), $note(2, 'E1', 'DICT', 15, 1),
            // Doublon de la vue (élève inscrit deux années) : ne doit compter qu'une fois.
            $note(1, 'E1', 'MATH', 40, 2.5),
            $note(1, 'E2', 'MATH', 30, 2.5), $note(2, 'E2', 'DICT', 19, 1),
            $note(1, 'E3', 'MATH', 40, 2.5), $note(2, 'E3', 'DICT', 15, 1),
            // Autre session : ne se mélange pas à S1.
            $note(9, 'E1', 'MATH', 0, 2.5, 'S0'),
        ]);

        $r = $this->getJson('/api/v1/classes/CM1/moyennes')->assertOk()
            ->assertJsonPath('source', 'calcul')
            ->assertJsonPath('session', 'S1')
            ->assertJsonPath('sessions', ['S0', 'S1'])
            ->assertJsonPath('effectif', 4);

        $lignes = collect($r->json('classement'))->keyBy('matricule');
        // (40 + 15) / (50 + 20) × 20 = 15,71
        $this->assertEqualsWithDelta(15.71, $lignes['E1']['moyenne'], 0.001);
        $this->assertEqualsWithDelta(55, $lignes['E1']['total'], 0.001);
        $this->assertEqualsWithDelta(70, $lignes['E1']['total_max'], 0.001);
        // (30 + 19) / 70 × 20 = 14
        $this->assertEqualsWithDelta(14.0, $lignes['E2']['moyenne'], 0.001);
        // Égalité : même rang, le suivant saute.
        $this->assertSame('1', $lignes['E1']['rang']);
        $this->assertSame('1', $lignes['E3']['rang']);
        $this->assertSame('3', $lignes['E2']['rang']);
        // Élève sans note : présent, sans moyenne ni rang, en fin de liste.
        $this->assertNull($lignes['E4']['moyenne']);
        $this->assertNull($lignes['E4']['rang']);
        $this->assertSame('E4', $r->json('classement.3.matricule'));
        // Moyenne des élèves notés seulement : (15,71 + 14 + 15,71) / 3.
        $this->assertEqualsWithDelta(15.14, $r->json('moyenne_classe'), 0.001);

        // La session demandée est respectée.
        $s0 = $this->getJson('/api/v1/classes/CM1/moyennes?session=S0')->assertOk();
        $this->assertEqualsWithDelta(0.0, collect($s0->json('classement'))->firstWhere('matricule', 'E1')['moyenne'], 0.001);
    }
}
