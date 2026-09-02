<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Emplois du temps : grille par classe et contraintes bloquantes.
 *
 * Les trois conflits sont refusés : classe déjà occupée, salle déjà prise, enseignant
 * déjà en cours ailleurs. L'enseignant n'étant pas stocké dans T_EMPLOIDUTEMPS, le
 * conflit se déduit de T_CORPROFCLASSE.
 */
class EmploiDuTempsTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

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
        $eco('T_EMPJOUR')->insert([
            ['Code' => 1, 'Libelle' => 'Lundi'], ['Code' => 2, 'Libelle' => 'Mardi'],
        ]);
        $eco('T_HORAIRE')->insert([
            ['COD_HORAIRE' => 1, 'HEUR_DEBUT' => '08:00', 'HEUR_FIN' => '09:00', 'DUREEE' => '1h'],
            ['COD_HORAIRE' => 2, 'HEUR_DEBUT' => '09:00', 'HEUR_FIN' => '10:00', 'DUREEE' => '1h'],
        ]);
        $eco('T_SALLESCLASSE')->insert([
            ['CODE' => 1, 'CODESALLE' => 'S1', 'LIBELLESALLE' => 'Salle 1', 'NBREPLACE' => 40],
            ['CODE' => 2, 'CODESALLE' => 'S2', 'LIBELLESALLE' => 'Salle 2', 'NBREPLACE' => 35],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
            ['num' => 2, 'CodeClasse' => 'CP1B', 'LibelleClasse' => 'CP1 B', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques'],
            ['Code' => 2, 'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français'],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa'],
        ]);
        // Le même professeur enseigne les maths dans les DEUX classes.
        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE],
            ['Code' => 2, 'CodeClasse' => 'CP1B', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE],
        ]);
    }

    private function creneau(array $extra = []): array
    {
        return array_merge(['jour' => 1, 'heure' => 1, 'classe' => 'CP1A', 'matiere' => 'MATH', 'salle' => 'S1'], $extra);
    }

    public function test_la_trame_de_la_grille_est_exposee(): void
    {
        $r = $this->getJson('/api/v1/emplois-du-temps/referentiels')->assertOk();

        $this->assertSame('Lundi', $r->json('jours.0.libelle'));
        $this->assertSame('08:00 - 09:00', $r->json('heures.0.libelle'));
        $this->assertSame('Salle 1', $r->json('salles.0.libelle'));
    }

    public function test_poser_un_creneau_et_lire_la_grille(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated()
            ->assertJsonPath('matiere_libelle', 'Mathématiques')
            ->assertJsonPath('salle_libelle', 'Salle 1')
            // L'enseignant est déduit de son affectation, pas saisi.
            ->assertJsonPath('enseignant', 'Moussa Traoré');

        $r = $this->getJson('/api/v1/emplois-du-temps?classe=CP1A')->assertOk();
        $this->assertSame(self::ANNEE, $r->json('annee'));
        $this->assertFalse($r->json('annee_cloturee'));
        $this->assertCount(1, $r->json('creneaux'));
    }

    public function test_une_classe_ne_peut_avoir_deux_cours_au_meme_creneau(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated();

        $r = $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['matiere' => 'FR', 'salle' => 'S2']))
            ->assertStatus(422)->assertJsonValidationErrors('classe');

        $this->assertStringContainsString('déjà un cours', $r->json('errors.classe.0'));
    }

    public function test_une_salle_ne_peut_accueillir_deux_classes_au_meme_creneau(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated();

        // Autre classe, même salle, même créneau, matière sans conflit d'enseignant.
        $r = $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['classe' => 'CP1B', 'matiere' => 'FR']))
            ->assertStatus(422)->assertJsonValidationErrors('salle');

        $this->assertStringContainsString('Salle 1', $r->json('errors.salle.0'));
        $this->assertStringContainsString('CP1 A', $r->json('errors.salle.0'));
    }

    public function test_un_enseignant_ne_peut_etre_dans_deux_classes_au_meme_creneau(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated();

        // Autre classe, autre salle, mais MATH est enseignée par le même professeur.
        $r = $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['classe' => 'CP1B', 'salle' => 'S2']))
            ->assertStatus(422)->assertJsonValidationErrors('matiere');

        $this->assertStringContainsString('Moussa Traoré', $r->json('errors.matiere.0'));
        // Le message désigne la classe où l'enseignant est DÉJÀ retenu : c'est l'information utile.
        $this->assertStringContainsString('CP1 A', $r->json('errors.matiere.0'));
    }

    public function test_un_creneau_libre_a_une_autre_heure_est_accepte(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated();

        // Même professeur, même salle, mais à l'heure suivante : aucun conflit.
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['classe' => 'CP1B', 'heure' => 2]))
            ->assertCreated();

        $this->assertDatabaseCount('T_EMPLOIDUTEMPS', 2, 'economat');
    }

    public function test_modifier_un_creneau_ne_se_heurte_pas_a_lui_meme(): void
    {
        $id = $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated()->json('id');

        // On ne change que la salle : le créneau ne doit pas se déclarer en conflit avec lui-même.
        $this->putJson("/api/v1/emplois-du-temps/{$id}", $this->creneau(['salle' => 'S2']))
            ->assertOk()->assertJsonPath('salle', 'S2');
    }

    public function test_vider_une_case_supprime_reellement_le_creneau(): void
    {
        $id = $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated()->json('id');

        $this->deleteJson("/api/v1/emplois-du-temps/{$id}")->assertNoContent();

        // Exception assumée à la règle « jamais de DELETE » : la planification se réorganise.
        $this->assertDatabaseCount('T_EMPLOIDUTEMPS', 0, 'economat');
    }

    public function test_une_matiere_ou_une_salle_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['matiere' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('matiere');

        $this->postJson('/api/v1/emplois-du-temps', $this->creneau(['salle' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('salle');
    }

    public function test_la_grille_d_une_annee_cloturee_est_verrouillee(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertStatus(423);

        $r = $this->getJson('/api/v1/emplois-du-temps?classe=CP1A')->assertOk();
        $this->assertTrue($r->json('annee_cloturee'));
    }

    public function test_la_grille_ne_montre_que_l_annee_de_travail(): void
    {
        $this->postJson('/api/v1/emplois-du-temps', $this->creneau())->assertCreated();

        // Un créneau de l'année précédente ne doit pas apparaître.
        DB::connection('economat')->table('T_EMPLOIDUTEMPS')->insert([
            'CODE' => 99, 'CODEJOUR' => 2, 'CODEHEURE' => 2, 'CODECLASSE' => 'CP1A',
            'CODEMATIERE' => 'FR', 'ANNEE' => '2024-2025',
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/emplois-du-temps?classe=CP1A')->assertOk()->json('creneaux'));
    }
}
