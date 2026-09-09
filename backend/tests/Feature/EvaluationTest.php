<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Évaluations planifiées (App\Models\Evaluation, table ecoprim.evaluations) : un devoir
 * ou une composition annoncée avant toute note, distinct de RapportController::evaluations()
 * qui n'agrège que des notes déjà saisies.
 */
class EvaluationTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        Artisan::call('migrate', [
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
            ['CODE' => 2, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025',
                'Activer' => false, 'ClotureDefinitive' => true, 'DEBUT' => '2024-09-01'],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MAT', 'LibelleMatiere' => 'Mathématiques'],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Kouassi', 'PrenomProfesseur' => 'Jean'],
        ]);
    }

    private function evaluation(array $extra = []): array
    {
        return array_merge([
            'titre' => 'Devoir de Mathématiques N°1', 'classe' => 'CM2A', 'matiere' => 'MAT',
            'enseignant' => 7, 'type' => 'Devoir surveillé', 'date' => '2026-10-15',
            'heure_debut' => '08:00', 'heure_fin' => '09:00', 'coefficient' => 2, 'note_maximale' => 20,
        ], $extra);
    }

    public function test_les_referentiels_exposent_classes_matieres_enseignants_et_types(): void
    {
        $r = $this->getJson('/api/v1/evaluations-planifiees/referentiels')->assertOk();

        $this->assertSame('CM2 A', $r->json('classes.0.libelle'));
        $this->assertSame('Mathématiques', $r->json('matieres.0.libelle'));
        $this->assertSame('Jean Kouassi', $r->json('enseignants.0.nom'));
        $this->assertContains('Devoir surveillé', $r->json('types'));
    }

    public function test_planifier_une_evaluation(): void
    {
        $r = $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation())->assertCreated();

        $this->assertSame('Devoir de Mathématiques N°1', $r->json('titre'));
        $this->assertSame('CM2 A', $r->json('classe_libelle'));
        $this->assertSame('Mathématiques', $r->json('matiere_libelle'));
        $this->assertSame('Jean Kouassi', $r->json('enseignant_nom'));
        $this->assertSame('2026-10-15', $r->json('date'));
        $this->assertSame('08:00', $r->json('heure_debut'));
        $this->assertEquals(2, $r->json('coefficient'));
        $this->assertEquals(20, $r->json('note_maximale'));
        $this->assertSame(self::ANNEE, $r->json('annee'));

        $this->assertDatabaseHas('evaluations', ['titre' => 'Devoir de Mathématiques N°1', 'classe_code' => 'CM2A'], 'ecoprim');
    }

    public function test_champs_obligatoires(): void
    {
        $this->postJson('/api/v1/evaluations-planifiees', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titre', 'classe', 'matiere', 'type', 'date', 'coefficient', 'note_maximale']);
    }

    public function test_une_classe_ou_une_matiere_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation(['classe' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('classe');

        $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation(['matiere' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('matiere');
    }

    public function test_l_enseignant_est_facultatif(): void
    {
        $r = $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation(['enseignant' => null]))
            ->assertCreated();

        $this->assertNull($r->json('enseignant'));
        $this->assertNull($r->json('enseignant_nom'));
    }

    public function test_lister_et_filtrer_par_classe(): void
    {
        $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation())->assertCreated();

        $r = $this->getJson('/api/v1/evaluations-planifiees?classe=CM2A')->assertOk();
        $this->assertCount(1, $r->json());

        $this->assertCount(0, $this->getJson('/api/v1/evaluations-planifiees?classe=AUTRE')->json());
    }

    public function test_modifier_puis_supprimer_une_evaluation(): void
    {
        $id = $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation())->assertCreated()->json('id');

        $this->putJson("/api/v1/evaluations-planifiees/{$id}", $this->evaluation(['titre' => 'Devoir corrigé']))
            ->assertOk()->assertJsonPath('titre', 'Devoir corrigé');

        $this->deleteJson("/api/v1/evaluations-planifiees/{$id}")->assertNoContent();
        $this->assertDatabaseCount('evaluations', 0, 'ecoprim');
    }

    public function test_une_annee_cloturee_verrouille_creation_modification_et_suppression(): void
    {
        $id = $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation())->assertCreated()->json('id');

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();
        $this->postJson('/api/v1/evaluations-planifiees', $this->evaluation())->assertStatus(423);

        // Le verrou porte sur l'année de l'évaluation elle-même (toujours 2025-2026, non
        // clôturée), pas sur l'année de travail actuellement affichée : elle reste modifiable.
        $this->putJson("/api/v1/evaluations-planifiees/{$id}", $this->evaluation(['titre' => 'X']))->assertOk();
    }
}
