<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cahier de textes : une semaine (T_ENTETE_JOURNAL) par classe, une ligne par matière
 * affectée (T_CAHIER_JOURNAL) avec ce qui a été vu chaque jour.
 */
class CahierTextesTest extends TestCase
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
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques'],
            ['Code' => 2, 'CodeMatiere' => 'FR', 'LibelleMatiere' => 'Français'],
            ['Code' => 3, 'CodeMatiere' => 'SPORT', 'LibelleMatiere' => 'Éducation physique'],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa'],
        ]);
        // Seules MATH et FR sont affectées à CP1A : SPORT ne doit pas être proposée.
        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE],
            ['Code' => 2, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'FR', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE],
        ]);
    }

    private function entete(array $extra = []): array
    {
        return array_merge(['classe' => 'CP1A', 'mois' => 'Septembre', 'semaine' => '1'], $extra);
    }

    public function test_les_referentiels_exposent_les_mois_et_les_matieres_de_la_classe(): void
    {
        $r = $this->getJson('/api/v1/cahier-textes/referentiels?classe=CP1A')->assertOk();

        $this->assertContains('Septembre', $r->json('mois'));
        $codes = collect($r->json('matieres'))->pluck('code');
        $this->assertTrue($codes->contains('MATH'));
        $this->assertFalse($codes->contains('SPORT'));
        $this->assertSame('Moussa Traoré', $r->json('enseignants.0.nom'));
    }

    public function test_creer_un_cahier_avec_une_ligne_par_matiere_affectee(): void
    {
        $r = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated();

        $this->assertSame('CP1', $r->json('niveau'));
        $this->assertSame(1, $r->json('num_sem'));
        $this->assertCount(2, $r->json('lignes'));
        $this->assertSame('', $r->json('lignes.0.lundi'));

        $liste = $this->getJson('/api/v1/cahier-textes?classe=CP1A')->assertOk();
        $this->assertCount(1, $liste->json('entetes'));
    }

    public function test_deux_cahiers_pour_la_meme_classe_le_meme_mois_incrementent_num_sem(): void
    {
        $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated();

        $r = $this->postJson('/api/v1/cahier-textes', $this->entete(['semaine' => '2']))->assertCreated();

        $this->assertSame(2, $r->json('num_sem'));
    }

    public function test_un_doublon_classe_mois_semaine_est_refuse(): void
    {
        $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated();

        $this->postJson('/api/v1/cahier-textes', $this->entete())
            ->assertStatus(422)->assertJsonValidationErrors('semaine');
    }

    public function test_enregistrer_une_ligne_puis_la_modifier_ne_duplique_pas(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');

        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", [
            'matiere' => 'MATH', 'lundi' => 'Addition', 'mardi' => 'Soustraction',
        ])->assertOk()->assertJsonPath('lundi', 'Addition');

        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", [
            'matiere' => 'MATH', 'lundi' => 'Multiplication',
        ])->assertOk()->assertJsonPath('lundi', 'Multiplication');

        $this->assertDatabaseCount('T_CAHIER_JOURNAL', 1, 'economat');

        $lignes = $this->getJson("/api/v1/cahier-textes?classe=CP1A")->json('entetes.0.lignes');
        $this->assertSame('Multiplication', collect($lignes)->firstWhere('matiere', 'MATH')['lundi']);
    }

    public function test_une_matiere_non_affectee_a_la_classe_est_refusee(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');

        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", ['matiere' => 'SPORT', 'lundi' => 'Course'])
            ->assertStatus(422)->assertJsonValidationErrors('matiere');
    }

    public function test_une_ligne_deja_ecrite_reste_modifiable_si_l_affectation_disparait(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');
        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", ['matiere' => 'FR', 'lundi' => 'Lecture'])->assertOk();

        // L'affectation FR est retirée après coup.
        DB::connection('economat')->table('T_CORPROFCLASSE')->where('CodeMatiere', 'FR')->delete();

        // La ligne reste visible (orpheline) et modifiable.
        $lignes = $this->getJson('/api/v1/cahier-textes?classe=CP1A')->json('entetes.0.lignes');
        $this->assertTrue(collect($lignes)->contains(fn ($l) => $l['matiere'] === 'FR' && $l['lundi'] === 'Lecture'));

        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", ['matiere' => 'FR', 'lundi' => 'Grammaire'])
            ->assertOk()->assertJsonPath('lundi', 'Grammaire');
    }

    public function test_retirer_une_matiere_supprime_reellement_la_ligne(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');
        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", ['matiere' => 'MATH', 'lundi' => 'Addition'])->assertOk();

        $this->deleteJson("/api/v1/cahier-textes/{$id}/lignes/MATH")->assertNoContent();

        $this->assertDatabaseCount('T_CAHIER_JOURNAL', 0, 'economat');
    }

    public function test_supprimer_un_cahier_supprime_ses_lignes(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');
        $this->putJson("/api/v1/cahier-textes/{$id}/lignes", ['matiere' => 'MATH', 'lundi' => 'Addition'])->assertOk();

        $this->deleteJson("/api/v1/cahier-textes/{$id}")->assertNoContent();

        $this->assertDatabaseCount('T_ENTETE_JOURNAL', 0, 'economat');
        $this->assertDatabaseCount('T_CAHIER_JOURNAL', 0, 'economat');
    }

    public function test_modifier_l_entete_verifie_le_doublon_hors_de_lui_meme(): void
    {
        $id = $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated()->json('id');

        // On ne change que la semaine : ne doit pas se heurter à lui-même.
        $this->putJson("/api/v1/cahier-textes/{$id}", $this->entete(['semaine' => '1', 'prof' => 7]))
            ->assertOk()->assertJsonPath('prof_nom', 'Moussa Traoré');
    }

    public function test_une_annee_cloturee_verrouille_la_creation(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->postJson('/api/v1/cahier-textes', $this->entete())->assertStatus(423);
    }

    /** Le verrou porte sur l'année de la LIGNE, pas sur celle qu'on regarde. */
    public function test_une_annee_cloturee_verrouille_saisie_et_suppression_sur_ses_propres_lignes(): void
    {
        DB::connection('economat')->table('T_ENTETE_JOURNAL')->insert([
            'CodeEntete' => 5, 'Annee' => '2024-2025', 'Mois' => 'Mars', 'Semaine' => '5',
            'CodeClasse' => 'CP1A', 'CodeNiveau' => 'CP1', 'NumSem' => 1,
        ]);

        $this->putJson('/api/v1/cahier-textes/5/lignes', ['matiere' => 'MATH', 'lundi' => 'x'])->assertStatus(423);
        $this->deleteJson('/api/v1/cahier-textes/5')->assertStatus(423);
    }

    public function test_le_cahier_ne_montre_que_l_annee_de_travail(): void
    {
        $this->postJson('/api/v1/cahier-textes', $this->entete())->assertCreated();

        DB::connection('economat')->table('T_ENTETE_JOURNAL')->insert([
            'CodeEntete' => 99, 'Annee' => '2024-2025', 'Mois' => 'Mars', 'Semaine' => '5',
            'CodeClasse' => 'CP1A', 'CodeNiveau' => 'CP1', 'NumSem' => 1,
        ]);

        $this->assertCount(1, $this->getJson('/api/v1/cahier-textes?classe=CP1A')->json('entetes'));
    }
}
