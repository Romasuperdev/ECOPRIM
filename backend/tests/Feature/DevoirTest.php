<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Devoirs (App\Models\Devoir, table ecoprim.devoirs) : un travail donné à une classe,
 * avec date de remise — distinct du cahier de texte, qui ne consigne que ce qui a été
 * vu en classe.
 */
class DevoirTest extends TestCase
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

    private function devoir(array $extra = []): array
    {
        return array_merge([
            'titre' => 'Exercices sur les fractions', 'consigne' => 'Faire les exercices 1 à 5 page 32.',
            'classe' => 'CM2A', 'matiere' => 'MAT', 'enseignant' => 7, 'date_remise' => '2026-10-15',
        ], $extra);
    }

    public function test_les_referentiels_exposent_classes_matieres_et_enseignants(): void
    {
        $r = $this->getJson('/api/v1/devoirs/referentiels')->assertOk();

        $this->assertSame('CM2 A', $r->json('classes.0.libelle'));
        $this->assertSame('Mathématiques', $r->json('matieres.0.libelle'));
        $this->assertSame('Jean Kouassi', $r->json('enseignants.0.nom'));
    }

    public function test_creer_un_devoir(): void
    {
        $r = $this->postJson('/api/v1/devoirs', $this->devoir())->assertCreated();

        $this->assertSame('Exercices sur les fractions', $r->json('titre'));
        $this->assertSame('Faire les exercices 1 à 5 page 32.', $r->json('consigne'));
        $this->assertSame('CM2 A', $r->json('classe_libelle'));
        $this->assertSame('Mathématiques', $r->json('matiere_libelle'));
        $this->assertSame('Jean Kouassi', $r->json('enseignant_nom'));
        $this->assertSame('2026-10-15', $r->json('date_remise'));
        $this->assertSame(self::ANNEE, $r->json('annee'));

        $this->assertDatabaseHas('devoirs', ['titre' => 'Exercices sur les fractions', 'classe_code' => 'CM2A'], 'ecoprim');
    }

    public function test_champs_obligatoires(): void
    {
        $this->postJson('/api/v1/devoirs', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['titre', 'classe', 'matiere', 'date_remise']);
    }

    public function test_une_classe_ou_une_matiere_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/devoirs', $this->devoir(['classe' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('classe');

        $this->postJson('/api/v1/devoirs', $this->devoir(['matiere' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('matiere');
    }

    public function test_l_enseignant_et_la_consigne_sont_facultatifs(): void
    {
        $r = $this->postJson('/api/v1/devoirs', $this->devoir(['enseignant' => null, 'consigne' => null]))
            ->assertCreated();

        $this->assertNull($r->json('enseignant'));
        $this->assertNull($r->json('consigne'));
    }

    public function test_lister_et_filtrer_par_classe(): void
    {
        $this->postJson('/api/v1/devoirs', $this->devoir())->assertCreated();

        $r = $this->getJson('/api/v1/devoirs?classe=CM2A')->assertOk();
        $this->assertCount(1, $r->json());

        $this->assertCount(0, $this->getJson('/api/v1/devoirs?classe=AUTRE')->json());
    }

    public function test_modifier_puis_supprimer_un_devoir(): void
    {
        $id = $this->postJson('/api/v1/devoirs', $this->devoir())->assertCreated()->json('id');

        $this->putJson("/api/v1/devoirs/{$id}", $this->devoir(['titre' => 'Exercices corrigés']))
            ->assertOk()->assertJsonPath('titre', 'Exercices corrigés');

        $this->deleteJson("/api/v1/devoirs/{$id}")->assertNoContent();
        $this->assertDatabaseCount('devoirs', 0, 'ecoprim');
    }

    public function test_une_annee_cloturee_verrouille_creation_modification_et_suppression(): void
    {
        $id = $this->postJson('/api/v1/devoirs', $this->devoir())->assertCreated()->json('id');

        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();
        $this->postJson('/api/v1/devoirs', $this->devoir())->assertStatus(423);

        // Le verrou porte sur l'année du devoir lui-même (toujours 2025-2026, non
        // clôturée), pas sur l'année de travail actuellement affichée : il reste modifiable.
        $this->putJson("/api/v1/devoirs/{$id}", $this->devoir(['titre' => 'X']))->assertOk();
    }
}
