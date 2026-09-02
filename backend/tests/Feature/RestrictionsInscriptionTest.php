<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Restrictions métier de l'inscription.
 *
 * Modèle retenu : T_ETUDIANT ne garde qu'UNE ligne par élève (ECONOMAT archive
 * l'historique dans T_HISTETUDIANT). Conséquences testées ici :
 *  - inscription / transfert entrant = création, le matricule doit être libre ;
 *  - réinscription / transfert sortant = mise à jour de la ligne existante ;
 *  - un élève ne peut être inscrit qu'UNE FOIS par année.
 */
class RestrictionsInscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');

        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2024', 'LibelleAnnee' => '2024-2025', 'Activer' => false,
                'ClotureDefinitive' => false, 'DEBUT' => '2024-09-01', 'FIN' => '2025-07-31'],
            ['CODE' => 2, 'CodeAnnee' => '2025', 'LibelleAnnee' => '2025-2026', 'Activer' => true,
                'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01', 'FIN' => '2026-07-31'],
        ]);
        DB::connection('economat')->table('T_CYCLE')->insert([
            ['Num' => 1, 'CodeCycle' => 'PRIM', 'LibelleCycle' => 'Primaire'],
        ]);
        DB::connection('economat')->table('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'CP1', 'CodeCycle' => 'PRIM'],
            ['Num' => 2, 'CodeNiveau' => 'CM2', 'LibelleNiveau' => 'CM2', 'CodeCycle' => 'PRIM'],
        ]);
        DB::connection('economat')->table('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => '2025-2026'],
            ['num' => 2, 'CodeClasse' => 'CM2A', 'LibelleClasse' => 'CM2 A', 'CodN' => 'CM2', 'ANNEE' => '2025-2026'],
        ]);
    }

    private function eleve(array $extra = []): array
    {
        return array_merge([
            'mouvement' => 'inscription', 'matricule' => 'M001',
            'nom' => 'Koné', 'prenom' => 'Aya', 'annee' => '2025-2026',
        ], $extra);
    }

    // --- Matricule ---

    public function test_le_matricule_est_obligatoire(): void
    {
        $charge = $this->eleve();
        unset($charge['matricule']);

        $this->postJson('/api/v1/inscriptions', $charge)
            ->assertStatus(422)->assertJsonValidationErrors('matricule');
    }

    public function test_un_matricule_deja_pris_ne_peut_pas_etre_reinscrit_comme_nouvelle_inscription(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated();

        $r = $this->postJson('/api/v1/inscriptions', $this->eleve(['prenom' => 'Autre']))
            ->assertStatus(422)->assertJsonValidationErrors('matricule');

        $this->assertStringContainsString('Réinscription', $r->json('errors.matricule.0'));
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
    }

    // --- Une inscription par an ---

    public function test_un_eleve_ne_peut_etre_inscrit_qu_une_fois_par_annee(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve())->assertCreated();

        // Réinscription dans l'année qu'il occupe déjà : refusée.
        $r = $this->postJson('/api/v1/inscriptions', $this->eleve(['mouvement' => 'reinscription']))
            ->assertStatus(422)->assertJsonValidationErrors('annee');

        $this->assertStringContainsString('une fois par année', $r->json('errors.annee.0'));
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
    }

    public function test_la_reinscription_met_a_jour_la_ligne_sans_la_dupliquer(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['annee' => '2024-2025']))->assertCreated();

        $this->postJson('/api/v1/inscriptions', $this->eleve([
            'mouvement' => 'reinscription', 'annee' => '2025-2026',
            'niveau_code' => 'CM2', 'cycle_code' => 'PRIM', 'classe_code' => 'CM2A',
        ]))->assertOk();

        // Toujours une seule ligne, passée à la nouvelle année.
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
        $this->assertDatabaseHas('T_ETUDIANT', [
            'Matricule' => 'M001', 'AnneeAcad' => '2025-2026', 'CodeClasse' => 'CM2A',
            'Reinscription' => 1, 'Inscription' => 0,
        ], 'economat');
    }

    public function test_reinscrire_un_matricule_inconnu_est_refuse(): void
    {
        $r = $this->postJson('/api/v1/inscriptions', $this->eleve(['mouvement' => 'reinscription']))
            ->assertStatus(422)->assertJsonValidationErrors('matricule');

        $this->assertStringContainsString('Inscription', $r->json('errors.matricule.0'));
        $this->assertDatabaseCount('T_ETUDIANT', 0, 'economat');
    }

    // --- Doublon d'identité ---

    public function test_meme_nom_prenom_et_date_de_naissance_est_refuse(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['date_naissance' => '2018-05-04']))->assertCreated();

        // Autre matricule, mais visiblement la même personne.
        $r = $this->postJson('/api/v1/inscriptions', $this->eleve([
            'matricule' => 'M002', 'date_naissance' => '2018-05-04',
        ]))->assertStatus(422)->assertJsonValidationErrors('nom');

        $this->assertStringContainsString('M001', $r->json('errors.nom.0'));
        $this->assertDatabaseCount('T_ETUDIANT', 1, 'economat');
    }

    public function test_deux_homonymes_de_dates_differentes_restent_possibles(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['date_naissance' => '2018-05-04']))->assertCreated();

        $this->postJson('/api/v1/inscriptions', $this->eleve([
            'matricule' => 'M002', 'date_naissance' => '2019-11-20',
        ]))->assertCreated();

        $this->assertDatabaseCount('T_ETUDIANT', 2, 'economat');
    }

    // --- Cohérence du rattachement scolaire ---

    public function test_la_classe_doit_relever_du_niveau_choisi(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve([
            'niveau_code' => 'CP1', 'cycle_code' => 'PRIM', 'classe_code' => 'CM2A',
        ]))->assertStatus(422)->assertJsonValidationErrors('classe_code');
    }

    public function test_la_classe_doit_appartenir_a_l_annee_visee(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve([
            'annee' => '2024-2025', 'classe_code' => 'CP1A',
        ]))->assertStatus(422)->assertJsonValidationErrors('classe_code');
    }

    public function test_une_classe_inconnue_est_refusee(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['classe_code' => 'FANTOME']))
            ->assertStatus(422)->assertJsonValidationErrors('classe_code');
    }

    public function test_le_niveau_doit_relever_du_cycle_choisi(): void
    {
        DB::connection('economat')->table('T_CYCLE')->insert([
            ['Num' => 2, 'CodeCycle' => 'SEC', 'LibelleCycle' => 'Secondaire'],
        ]);

        $this->postJson('/api/v1/inscriptions', $this->eleve(['niveau_code' => 'CP1', 'cycle_code' => 'SEC']))
            ->assertStatus(422)->assertJsonValidationErrors('niveau_code');
    }

    // --- Dates ---

    public function test_une_date_de_naissance_future_est_refusee(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['date_naissance' => now()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors('date_naissance');
    }

    public function test_la_date_d_inscription_doit_tomber_dans_l_annee_scolaire(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['date_inscription' => '2024-10-01']))
            ->assertStatus(422)->assertJsonValidationErrors('date_inscription');

        $this->postJson('/api/v1/inscriptions', $this->eleve(['date_inscription' => '2025-10-01']))
            ->assertCreated();
    }

    // --- Transferts ---

    public function test_un_transfert_entrant_exige_l_etablissement_d_origine(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['mouvement' => 'transfert_entrant']))
            ->assertStatus(422)->assertJsonValidationErrors('etab_origine');

        $this->postJson('/api/v1/inscriptions', $this->eleve([
            'mouvement' => 'transfert_entrant', 'etab_origine' => 'EPP Bouaké',
        ]))->assertCreated();
    }

    // --- Sexe ---

    public function test_le_sexe_est_borne_a_M_ou_F(): void
    {
        $this->postJson('/api/v1/inscriptions', $this->eleve(['sexe' => 'X']))
            ->assertStatus(422)->assertJsonValidationErrors('sexe');
    }
}
