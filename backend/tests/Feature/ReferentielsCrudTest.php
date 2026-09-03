<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Référentiels des Paramètres : années scolaires, cycles, niveaux, classes, matières.
 *
 * Règle commune à tous : la suppression est réelle, mais refusée dès qu'une ligne s'y
 * rattache. ECONOMAT ne porte aucune clé étrangère — rien n'empêcherait techniquement de
 * supprimer une classe pleine d'élèves, et personne ne s'en apercevrait avant que les
 * dossiers pointent vers un code inexistant.
 */
class ReferentielsCrudTest extends TestCase
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
        $eco('T_CYCLE')->insert([
            ['Num' => 1, 'CodeCycle' => 'PRIM', 'LibelleCycle' => 'Primaire', 'Primaire' => true],
        ]);
        $eco('T_NIVEAU')->insert([
            ['Num' => 1, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'Cours préparatoire 1',
                'CodeCycle' => 'PRIM', 'ANNEE' => self::ANNEE, 'Ordre' => 1],
        ]);
    }

    // ─── Années scolaires ───

    public function test_creer_modifier_et_supprimer_une_annee(): void
    {
        $r = $this->postJson('/api/v1/annees-scolaires', [
            'code' => '2026', 'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
        ])->assertCreated();
        $id = $r->json('id');

        $this->putJson("/api/v1/annees-scolaires/{$id}", ['libelle' => '2026-2027 (révisée)'])
            ->assertOk()->assertJsonPath('libelle', '2026-2027 (révisée)');

        $this->deleteJson("/api/v1/annees-scolaires/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('T_ANNEEACADEMIQUE', ['CODE' => $id], 'economat');
    }

    public function test_un_libelle_d_annee_ne_peut_pas_etre_repris(): void
    {
        $this->postJson('/api/v1/annees-scolaires', ['code' => 'X', 'libelle' => self::ANNEE])
            ->assertStatus(422)->assertJsonValidationErrors('libelle');
    }

    public function test_activer_une_annee_desactive_les_autres(): void
    {
        $id = $this->postJson('/api/v1/annees-scolaires', [
            'code' => '2026', 'libelle' => '2026-2027', 'active' => true,
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('T_ANNEEACADEMIQUE', ['CODE' => $id, 'Activer' => true], 'economat');
        $this->assertDatabaseHas('T_ANNEEACADEMIQUE', ['CODE' => 1, 'Activer' => false], 'economat');
    }

    public function test_une_annee_cloturee_reste_modifiable_pour_pouvoir_etre_rouverte(): void
    {
        // Le verrou de clôture protège les données DANS une année, pas la définition de
        // l'année : sans cela, une clôture faite par erreur serait irréversible.
        $this->putJson('/api/v1/annees-scolaires/2', ['cloturee' => false])
            ->assertOk()->assertJsonPath('cloturee', false);
    }

    public function test_une_annee_qui_porte_des_donnees_ne_se_supprime_pas(): void
    {
        DB::connection('economat')->table('T_ETUDIANT')->insert([
            'Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'K', 'Prenom' => 'A', 'AnneeAcad' => self::ANNEE,
        ]);

        $r = $this->deleteJson('/api/v1/annees-scolaires/1')->assertStatus(409);
        $this->assertStringContainsString('élève inscrit', $r->json('message'));
        $this->assertDatabaseHas('T_ANNEEACADEMIQUE', ['CODE' => 1], 'economat');
    }

    // ─── Cycles ───

    public function test_creer_modifier_et_supprimer_un_cycle(): void
    {
        $this->postJson('/api/v1/cycles', ['code' => 'MAT', 'libelle' => 'Maternelle'])
            ->assertCreated()->assertJsonPath('code', 'MAT');

        $this->putJson('/api/v1/cycles/MAT', ['libelle' => 'Maternelle (rénovée)'])
            ->assertOk()->assertJsonPath('libelle', 'Maternelle (rénovée)');

        $this->deleteJson('/api/v1/cycles/MAT')->assertNoContent();
        $this->assertDatabaseMissing('T_CYCLE', ['CodeCycle' => 'MAT'], 'economat');
    }

    public function test_un_cycle_qui_porte_des_niveaux_ne_se_supprime_pas(): void
    {
        $r = $this->deleteJson('/api/v1/cycles/PRIM')->assertStatus(409);

        $this->assertStringContainsString('niveau', $r->json('message'));
        $this->assertDatabaseHas('T_CYCLE', ['CodeCycle' => 'PRIM'], 'economat');
    }

    public function test_un_code_de_cycle_ne_peut_pas_etre_repris(): void
    {
        $this->postJson('/api/v1/cycles', ['code' => 'PRIM', 'libelle' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // ─── Niveaux ───

    public function test_creer_modifier_et_supprimer_un_niveau(): void
    {
        $r = $this->postJson('/api/v1/niveaux', [
            'code' => 'CP2', 'libelle' => 'Cours préparatoire 2', 'cycle_code' => 'PRIM', 'ordre' => 2,
        ])->assertCreated()->assertJsonPath('code', 'CP2');
        $id = $r->json('id');

        // L'année vient du contexte, elle n'est pas saisie.
        $this->assertDatabaseHas('T_NIVEAU', ['Num' => $id, 'ANNEE' => self::ANNEE], 'economat');

        $this->putJson("/api/v1/niveaux/{$id}", ['ordre' => 3])->assertOk()->assertJsonPath('ordre', 3);
        $this->deleteJson("/api/v1/niveaux/{$id}")->assertNoContent();
    }

    public function test_un_niveau_rattache_a_un_cycle_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/niveaux', [
            'code' => 'CP2', 'libelle' => 'CP2', 'cycle_code' => 'FANTOME',
        ])->assertStatus(422)->assertJsonValidationErrors('cycle_code');
    }

    public function test_un_code_de_niveau_est_unique_dans_l_annee_mais_reutilisable_ailleurs(): void
    {
        $this->postJson('/api/v1/niveaux', ['code' => 'CP1', 'libelle' => 'Doublon', 'cycle_code' => 'PRIM'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // La même année existe dans une autre année : c'est normal, un CP1 par an.
        DB::connection('economat')->table('T_NIVEAU')->insert([
            'Num' => 9, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'CP1', 'ANNEE' => '2024-2025',
        ]);
        $this->assertDatabaseCount('T_NIVEAU', 2, 'economat');
    }

    public function test_un_niveau_qui_porte_des_classes_ne_se_supprime_pas(): void
    {
        DB::connection('economat')->table('T_CLASSE')->insert([
            'num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE,
        ]);

        $r = $this->deleteJson('/api/v1/niveaux/1')->assertStatus(409);
        $this->assertStringContainsString('classe', $r->json('message'));
    }

    // ─── Classes ───

    public function test_creer_modifier_et_supprimer_une_classe(): void
    {
        $r = $this->postJson('/api/v1/classes', [
            'code' => 'CP1A', 'nom' => 'CP1 A', 'niveau_code' => 'CP1',
        ])->assertCreated()->assertJsonPath('code', 'CP1A');

        $this->assertDatabaseHas('T_CLASSE', ['CodeClasse' => 'CP1A', 'ANNEE' => self::ANNEE], 'economat');

        $this->putJson('/api/v1/classes/CP1A', ['nom' => 'CP1 A bis'])
            ->assertOk()->assertJsonPath('nom', 'CP1 A bis');

        $this->deleteJson('/api/v1/classes/CP1A')->assertNoContent();
        $this->assertDatabaseMissing('T_CLASSE', ['CodeClasse' => 'CP1A'], 'economat');
    }

    public function test_une_classe_sur_un_niveau_inconnu_est_refusee(): void
    {
        $this->postJson('/api/v1/classes', ['code' => 'X', 'nom' => 'X', 'niveau_code' => 'FANTOME'])
            ->assertStatus(422)->assertJsonValidationErrors('niveau_code');
    }

    public function test_une_classe_qui_compte_des_eleves_ne_se_supprime_pas(): void
    {
        $this->postJson('/api/v1/classes', ['code' => 'CP1A', 'nom' => 'CP1 A', 'niveau_code' => 'CP1'])
            ->assertCreated();
        DB::connection('economat')->table('T_ETUDIANT')->insert([
            'Code' => 1, 'Matricule' => 'E-1', 'Nom' => 'K', 'Prenom' => 'A',
            'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE,
        ]);

        $r = $this->deleteJson('/api/v1/classes/CP1A')->assertStatus(409);
        $this->assertStringContainsString('élève', $r->json('message'));
        $this->assertDatabaseHas('T_CLASSE', ['CodeClasse' => 'CP1A'], 'economat');
    }

    public function test_les_classes_et_niveaux_d_une_annee_cloturee_sont_en_consultation_seule(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->postJson('/api/v1/classes', ['code' => 'X', 'nom' => 'X', 'niveau_code' => 'CP1'])
            ->assertStatus(423);
        $this->postJson('/api/v1/niveaux', ['code' => 'X', 'libelle' => 'X'])->assertStatus(423);
    }

    // ─── Matières ───

    public function test_creer_modifier_et_supprimer_une_matiere(): void
    {
        $r = $this->postJson('/api/v1/matieres', [
            'code' => 'MATH', 'libelle' => 'Mathématiques', 'cycle_code' => 'PRIM',
        ])->assertCreated()->assertJsonPath('code', 'MATH');
        $id = $r->json('id');

        $this->putJson("/api/v1/matieres/{$id}", ['libelle' => 'Maths'])
            ->assertOk()->assertJsonPath('libelle', 'Maths');

        $this->deleteJson("/api/v1/matieres/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('T_MATIERE', ['Code' => $id], 'economat');
    }

    public function test_une_matiere_affectee_ou_notee_ne_se_supprime_pas(): void
    {
        $id = $this->postJson('/api/v1/matieres', ['code' => 'MATH', 'libelle' => 'Mathématiques'])
            ->assertCreated()->json('id');

        DB::connection('economat')->table('T_CORPROFCLASSE')->insert([
            'Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH',
            'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE,
        ]);

        $r = $this->deleteJson("/api/v1/matieres/{$id}")->assertStatus(409);
        $this->assertStringContainsString('affectation', $r->json('message'));
    }

    public function test_une_matiere_n_est_pas_bornee_par_l_annee_de_travail(): void
    {
        // Une matière traverse les années : aucune raison de la verrouiller sur une clôture.
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        $this->postJson('/api/v1/matieres', ['code' => 'FR', 'libelle' => 'Français'])->assertCreated();
    }

    // ─── Documents élèves (T_PREREQUIS) ───

    public function test_retirer_un_document_parametre(): void
    {
        $id = $this->postJson('/api/v1/parametres/prerequis', [
            'libelle' => 'Extrait de naissance', 'code' => 'EXTNAIS', 'annee' => self::ANNEE,
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/v1/parametres/prerequis/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('T_PREREQUIS', ['CODES' => $id], 'economat');
    }

    public function test_un_document_deja_au_dossier_d_eleves_ne_se_retire_pas(): void
    {
        $id = $this->postJson('/api/v1/parametres/prerequis', [
            'libelle' => 'Extrait de naissance', 'code' => 'EXTNAIS', 'annee' => self::ANNEE,
        ])->assertCreated()->json('id');

        // T_PREREQUIS sert aussi de suivi par élève : cette ligne-là dépend du catalogue.
        DB::connection('economat')->table('T_PREREQUIS')->insert([
            'CODES' => 500, 'CODE' => 'EXTNAIS', 'LIBELLE' => 'Extrait de naissance',
            'ANNEE' => self::ANNEE, 'CODEELEVE' => 'E-1',
        ]);

        $r = $this->deleteJson("/api/v1/parametres/prerequis/{$id}")->assertStatus(409);
        $this->assertStringContainsString('dossier', $r->json('message'));
        $this->assertDatabaseHas('T_PREREQUIS', ['CODES' => $id], 'economat');
    }
}
