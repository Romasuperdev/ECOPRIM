<?php

namespace Tests\Feature;

use App\Models\CoefficientMatiere;
use App\Models\RhUser;
use App\Services\GrilleCoefficients;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Barèmes et coefficients des matières.
 *
 * CE QUI EST ÉPROUVÉ ICI, c'est la RÉSOLUTION — quelle ligne s'applique quand plusieurs
 * pourraient — et l'effet de la grille sur la SAISIE. Pas le calcul d'une moyenne : NEXORA
 * n'en calcule aucune, ECONOMAT s'en charge (voir RapportController). La grille agit en
 * inscrivant le bon barème et le bon coefficient dans T_NOTEENTETE, et c'est de là
 * qu'ECONOMAT tire sa moyenne.
 */
class CoefficientsTest extends TestCase
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

        $this->compte(1, 'direction', ['gerer_coefficients', 'saisir_notes']);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs(RhUser::on('master')->find(1), 'sanctum');

        $eco = fn (string $t) => DB::connection('economat')->table($t);

        $eco('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
                'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01'],
        ]);
        $eco('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'Cours préparatoire 1',
                'ANNEE' => self::ANNEE, 'Ordre' => 1],
            ['Num' => 2, 'CodeNiveau' => 'CE1', 'LibelleNiveau' => 'Cours élémentaire 1',
                'ANNEE' => self::ANNEE, 'Ordre' => 2],
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1',
                'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
            ['num' => 2, 'CodeClasse' => 'CP1B', 'LibelleClasse' => 'CP1 B', 'CodN' => 'CP1',
                'ANNEE' => self::ANNEE, 'CODEETABLISSEMENT' => 'E1'],
        ]);
        $eco('T_MATIERE')->insert([
            ['Code' => 1, 'CodeMatiere' => 'DICTEE', 'LibelleMatiere' => 'Dictée'],
            ['Code' => 2, 'CodeMatiere' => 'EVEIL', 'LibelleMatiere' => 'Éveil au milieu'],
            ['Code' => 3, 'CodeMatiere' => 'CHANT', 'LibelleMatiere' => 'Chant'],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 11, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    private function compte(int $id, string $role, array $permissions): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $role.$id, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $role.$id.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);

        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $roleId = $eco('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
        foreach ($permissions as $code) {
            $eco('console_role_permissions')->insert(['role_id' => $roleId, 'permission_code' => $code]);
        }
        $eco('console_affectations')->insert([
            'rh_user_id' => $id, 'societe_code' => 'S1', 'etablissement_code' => 'E1',
            'role_id' => $roleId, 'actif' => true,
        ]);

        return $rh;
    }

    private function poser(array $attributs): CoefficientMatiere
    {
        return CoefficientMatiere::create($attributs + [
            'etablissement_code' => null, 'annee' => null,
            'niveau_code' => null, 'classe_code' => null,
            'note_max' => null, 'actif' => true,
        ]);
    }

    private function grille(): GrilleCoefficients
    {
        return new GrilleCoefficients;
    }

    // --- Résolution -------------------------------------------------------------------

    public function test_la_grille_commune_s_applique_a_defaut_de_mieux(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);

        $grille = $this->grille()->pourClasse('CP1A', null, self::ANNEE);

        $this->assertSame(10.0, $grille['DICTEE']['coefficient']);
        $this->assertSame('defaut', $grille['DICTEE']['origine']);
        $this->assertFalse($grille['DICTEE']['surcharge']);
    }

    public function test_la_grille_de_l_etablissement_prime_sur_la_commune(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 20]);

        $grille = $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE);

        $this->assertSame(20.0, $grille['DICTEE']['coefficient']);
        $this->assertTrue($grille['DICTEE']['surcharge']);
        $this->assertSame('etablissement', $grille['DICTEE']['origine']);
    }

    public function test_la_grille_d_une_autre_ecole_ne_deborde_pas(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['etablissement_code' => 'E2', 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 99]);

        $grille = $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE);

        $this->assertSame(10.0, $grille['DICTEE']['coefficient']);
    }

    public function test_l_annee_prime_sur_toutes_annees(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['etablissement_code' => 'E1', 'annee' => self::ANNEE, 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 15]);

        $grille = $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE);

        $this->assertSame(15.0, $grille['DICTEE']['coefficient']);
    }

    /**
     * ECONOMAT note l'année tantôt en code, tantôt en libellé. Une grille posée sur l'une
     * doit valoir pour l'autre, sinon elle disparaîtrait au gré de l'écriture retenue.
     */
    public function test_l_annee_est_reconnue_sous_ses_deux_ecritures(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'annee' => '2025', 'niveau_code' => 'CP1',
            'matiere_code' => 'DICTEE', 'coefficient' => 15]);

        $grille = $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE);

        $this->assertSame(15.0, $grille['DICTEE']['coefficient']);
    }

    public function test_la_grille_d_une_classe_prime_sur_celle_du_niveau(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'annee' => self::ANNEE, 'niveau_code' => 'CP1',
            'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['niveau_code' => null, 'classe_code' => 'CP1A', 'matiere_code' => 'DICTEE', 'coefficient' => 30]);

        // La classe l'emporte, même posée sans établissement ni année.
        $this->assertSame(30.0, $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE)['DICTEE']['coefficient']);
        // La classe voisine, elle, garde la grille du niveau.
        $this->assertSame(10.0, $this->grille()->pourClasse('CP1B', 'E1', self::ANNEE)['DICTEE']['coefficient']);
    }

    public function test_une_matiere_non_enseignee_au_niveau_est_absente_de_la_grille(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['niveau_code' => 'CE1', 'matiere_code' => 'EVEIL', 'coefficient' => 50]);

        $grille = $this->grille()->pourClasse('CP1A', null, self::ANNEE);

        $this->assertArrayHasKey('DICTEE', $grille);
        // Éveil au milieu n'est pas enseigné en CP1 : la case est vide, pas à zéro.
        $this->assertArrayNotHasKey('EVEIL', $grille);
    }

    public function test_une_ligne_inactive_ne_s_applique_pas(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE',
            'coefficient' => 99, 'actif' => false]);

        $this->assertSame(10.0, $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE)['DICTEE']['coefficient']);
    }

    // --- Barème -----------------------------------------------------------------------

    public function test_le_bareme_vaut_le_coefficient_quand_il_n_est_pas_precise(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'EVEIL', 'coefficient' => 50]);

        $grille = $this->grille()->pourClasse('CP1A', null, self::ANNEE);

        $this->assertSame(50.0, $grille['EVEIL']['bareme']);
        $this->assertNull($grille['EVEIL']['note_max']);
    }

    public function test_un_bareme_different_du_coefficient_est_respecte(): void
    {
        // Une école qui note tout sur 20 puis pondère : barème 20, coefficient 3.
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 3, 'note_max' => 20]);

        $grille = $this->grille()->pourClasse('CP1A', null, self::ANNEE);

        $this->assertSame(3.0, $grille['DICTEE']['coefficient']);
        $this->assertSame(20.0, $grille['DICTEE']['bareme']);
    }

    /**
     * « Comptée pour rien » n'est pas « notée sur 0 » : rendre 0 comme barème ferait
     * refuser toute note, puisque la saisie interdit de dépasser le barème.
     */
    public function test_un_coefficient_a_zero_ne_donne_pas_un_bareme_a_zero(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'CHANT', 'coefficient' => 0]);

        $grille = $this->grille()->pourClasse('CP1A', null, self::ANNEE);

        $this->assertSame(0.0, $grille['CHANT']['coefficient']);
        $this->assertNull($grille['CHANT']['bareme']);
        $this->assertNull($this->grille()->baremeDe('CP1A', 'CHANT', self::ANNEE));
    }

    public function test_une_classe_sans_grille_ne_rend_rien(): void
    {
        $this->assertSame([], $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE));
        $this->assertNull($this->grille()->baremeDe('CP1A', 'DICTEE', self::ANNEE));
    }

    // --- Effet sur la saisie des notes -------------------------------------------------

    public function test_la_feuille_de_notes_prend_le_bareme_de_la_grille(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'EVEIL',
            'coefficient' => 50]);

        $reponse = $this->withSession(['etablissement_code' => 'E1'])
            ->getJson('/api/v1/notes/feuille?classe=CP1A&matiere=EVEIL&session=S1')
            ->assertOk();

        $this->assertSame(50.0, (float) $reponse->json('bareme'));
        $this->assertTrue($reponse->json('bareme_de_la_grille'));
    }

    public function test_le_bareme_declare_l_emporte_sur_la_grille(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'EVEIL',
            'coefficient' => 50]);

        $reponse = $this->withSession(['etablissement_code' => 'E1'])
            ->getJson('/api/v1/notes/feuille?classe=CP1A&matiere=EVEIL&session=S1&bareme=20')
            ->assertOk();

        $this->assertSame(20.0, (float) $reponse->json('bareme'));
    }

    public function test_sans_grille_le_bareme_reste_a_vingt(): void
    {
        $reponse = $this->getJson('/api/v1/notes/feuille?classe=CP1A&matiere=DICTEE&session=S1')
            ->assertOk();

        $this->assertSame(20.0, (float) $reponse->json('bareme'));
        $this->assertFalse($reponse->json('bareme_de_la_grille'));
    }

    /**
     * Le cœur de la décision « ECONOMAT continue de calculer » : la grille n'agit qu'en
     * inscrivant le barème et le coefficient dans l'entête, d'où ECONOMAT tire sa moyenne.
     */
    public function test_le_bareme_et_le_coefficient_partent_dans_l_entete(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'EVEIL',
            'coefficient' => 5, 'note_max' => 50]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->postJson('/api/v1/notes/feuille', [
                'classe' => 'CP1A', 'matiere' => 'EVEIL', 'session' => 'S1', 'type' => 'Composition',
                'notes' => [['matricule' => 'E-11', 'note' => 42]],
            ])->assertOk();

        $entete = DB::connection('economat')->table('T_NOTEENTETE')->first();
        $this->assertEqualsWithDelta(5.0, (float) $entete->Coefficient, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $entete->NoteSur, 0.001);

        // 42/50 passe alors que 42/20 aurait été refusé : le barème de la grille s'applique.
        $this->assertEqualsWithDelta(42.0, (float) DB::connection('economat')
            ->table('T_NOTEDETAILS')->value('Note'), 0.001);
    }

    public function test_une_note_au_dela_du_bareme_de_la_grille_est_refusee(): void
    {
        $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1', 'matiere_code' => 'DICTEE',
            'coefficient' => 10]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->postJson('/api/v1/notes/feuille', [
                'classe' => 'CP1A', 'matiere' => 'DICTEE', 'session' => 'S1',
                'notes' => [['matricule' => 'E-11', 'note' => 15]],
            ])->assertStatus(422)->assertJsonValidationErrors('notes.0.note');
    }

    // --- API --------------------------------------------------------------------------

    public function test_la_grille_rend_les_matieres_les_niveaux_et_les_cellules(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);

        $reponse = $this->withSession(['etablissement_code' => 'E1'])
            ->getJson('/api/v1/coefficients')->assertOk();

        $this->assertSame(['CP1', 'CE1'], array_column($reponse->json('niveaux'), 'code'));
        $this->assertContains('DICTEE', array_column($reponse->json('matieres'), 'code'));
        $this->assertSame(10.0, (float) $reponse->json('cellules.DICTEE.CP1.coefficient'));
        $this->assertNull($reponse->json('cellules.EVEIL'));
    }

    public function test_l_enregistrement_en_masse_ecrit_pour_l_etablissement_de_travail(): void
    {
        $reponse = $this->withSession(['etablissement_code' => 'E1'])
            ->putJson('/api/v1/coefficients', [
                'lignes' => [
                    ['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10],
                    ['niveau_code' => 'CP1', 'matiere_code' => 'EVEIL', 'coefficient' => 5, 'note_max' => 50],
                ],
            ])->assertOk();

        $this->assertSame(2, $reponse->json('enregistrees'));

        $ligne = CoefficientMatiere::where('matiere_code', 'EVEIL')->first();
        $this->assertSame('E1', $ligne->etablissement_code);
        $this->assertSame(self::ANNEE, $ligne->annee);
        $this->assertSame(50.0, $ligne->note_max);
    }

    public function test_reenregistrer_met_a_jour_au_lieu_de_dupliquer(): void
    {
        $envoyer = fn (float $c) => $this->withSession(['etablissement_code' => 'E1'])
            ->putJson('/api/v1/coefficients', [
                'lignes' => [['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => $c]],
            ])->assertOk();

        $envoyer(10);
        $envoyer(20);

        $this->assertSame(1, CoefficientMatiere::where('matiere_code', 'DICTEE')->count());
        $this->assertSame(20.0, CoefficientMatiere::where('matiere_code', 'DICTEE')->value('coefficient'));
    }

    public function test_une_case_videe_retire_la_surcharge(): void
    {
        $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);
        $this->poser(['etablissement_code' => 'E1', 'annee' => self::ANNEE, 'niveau_code' => 'CP1',
            'matiere_code' => 'DICTEE', 'coefficient' => 20]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->putJson('/api/v1/coefficients', [
                'lignes' => [['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => null]],
            ])->assertOk();

        // La surcharge disparaît ; la grille commune reprend la main.
        $this->assertSame(10.0, $this->grille()->pourClasse('CP1A', 'E1', self::ANNEE)['DICTEE']['coefficient']);
    }

    public function test_une_ligne_doit_viser_un_niveau_ou_une_classe_mais_pas_les_deux(): void
    {
        $this->withSession(['etablissement_code' => 'E1'])
            ->putJson('/api/v1/coefficients', [
                'lignes' => [['niveau_code' => 'CP1', 'classe_code' => 'CP1A',
                    'matiere_code' => 'DICTEE', 'coefficient' => 10]],
            ])->assertStatus(422);
    }

    public function test_sans_etablissement_de_travail_on_n_enregistre_pas(): void
    {
        $this->putJson('/api/v1/coefficients', [
            'lignes' => [['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors('etablissement');
    }

    public function test_on_ne_supprime_pas_la_grille_commune(): void
    {
        $commune = $this->poser(['niveau_code' => 'CP1', 'matiere_code' => 'DICTEE', 'coefficient' => 10]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->deleteJson("/api/v1/coefficients/{$commune->id}")->assertStatus(403);

        $this->assertDatabaseHas('coefficients_matiere', ['id' => $commune->id], 'ecoprim');
    }

    public function test_on_ne_supprime_pas_la_surcharge_d_une_autre_ecole(): void
    {
        $autre = $this->poser(['etablissement_code' => 'E2', 'niveau_code' => 'CP1',
            'matiere_code' => 'DICTEE', 'coefficient' => 99]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->deleteJson("/api/v1/coefficients/{$autre->id}")->assertStatus(403);
    }

    public function test_sa_propre_surcharge_se_supprime(): void
    {
        $sienne = $this->poser(['etablissement_code' => 'E1', 'niveau_code' => 'CP1',
            'matiere_code' => 'DICTEE', 'coefficient' => 20]);

        $this->withSession(['etablissement_code' => 'E1'])
            ->deleteJson("/api/v1/coefficients/{$sienne->id}")->assertNoContent();

        $this->assertDatabaseMissing('coefficients_matiere', ['id' => $sienne->id], 'ecoprim');
    }

    public function test_l_ecran_est_reserve_a_qui_a_la_permission(): void
    {
        $this->actingAs($this->compte(2, 'secretaire', ['consulter_eleves']), 'sanctum');

        $this->getJson('/api/v1/coefficients')->assertStatus(403);
        $this->putJson('/api/v1/coefficients', ['lignes' => []])->assertStatus(403);
    }

    // --- Commande d'import -------------------------------------------------------------

    private function csv(string $contenu): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'coef').'.csv';
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    public function test_la_commande_pose_la_grille_commune(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nDICTEE;CP1;10;\nEVEIL;CP1;5;50\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier])->assertSuccessful();

        $this->assertSame(2, CoefficientMatiere::count());
        $ligne = CoefficientMatiere::where('matiere_code', 'EVEIL')->first();
        $this->assertNull($ligne->etablissement_code);
        $this->assertNull($ligne->annee);
        $this->assertSame(50.0, $ligne->note_max);
    }

    public function test_la_commande_est_idempotente(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nDICTEE;CP1;10;\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier])->assertSuccessful();
        $this->artisan("coefficients:import", ["fichier" => $fichier])->expectsOutputToContain('Mis à jour : 1')->assertSuccessful();

        $this->assertSame(1, CoefficientMatiere::count());
    }

    public function test_la_commande_cree_les_matieres_et_niveaux_absents_et_les_enumere(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nCONJUGAISON;CM2;10;\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier])
            ->expectsOutputToContain('CONJUGAISON')
            ->assertSuccessful();

        $this->assertTrue(DB::connection('economat')->table('T_MATIERE')
            ->where('CodeMatiere', 'CONJUGAISON')->exists());
        $this->assertTrue(DB::connection('economat')->table('T_NIVEAU')
            ->where('CodeNiveau', 'CM2')->exists());
    }

    public function test_avec_sans_creation_la_commande_rejette_et_dit_quoi(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nCONJUGAISON;CM2;10;\nDICTEE;CP1;10;\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier, "--sans-creation" => true])
            ->expectsOutputToContain('Rejetés    : 1')
            ->assertExitCode(2);

        $this->assertFalse(DB::connection('economat')->table('T_MATIERE')
            ->where('CodeMatiere', 'CONJUGAISON')->exists());
        // La ligne valide, elle, est passée.
        $this->assertSame(1, CoefficientMatiere::count());
    }

    public function test_la_commande_rejette_un_coefficient_absent(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nDICTEE;CP1;;\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier])->assertExitCode(2);

        $this->assertSame(0, CoefficientMatiere::count());
    }

    public function test_la_commande_refuse_un_fichier_sans_les_bonnes_colonnes(): void
    {
        $fichier = $this->csv("matiere,niveau,coefficient\nDICTEE,CP1,10\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier])->assertFailed();
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $fichier = $this->csv("matiere;niveau;coefficient;note_max\nDICTEE;CP1;10;\n");

        $this->artisan("coefficients:import", ["fichier" => $fichier, "--simulation" => true])->assertSuccessful();

        $this->assertSame(0, CoefficientMatiere::count());
    }
}
