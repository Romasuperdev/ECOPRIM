<?php

namespace Tests\Feature;

use App\Models\RhUser;
use App\Support\SchemaNotes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Saisie des notes dans T_NOTEENTETE + T_NOTEDETAILS.
 *
 * Deux choses sont éprouvées ici, et elles comptent autant l'une que l'autre :
 *   - la saisie elle-même — une feuille de classe qui crée son entête, pose ses notes, et
 *     les remplace au lieu de les dupliquer quand on la ressaisit ;
 *   - le REFUS — quand NEXORA ne reconnaît pas la structure de ces tables de production, il
 *     doit fermer la saisie plutôt que d'écrire à l'aveugle. C'est le comportement qui
 *     protège la base, et il mérite ses propres tests.
 */
class SaisieNoteTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        // La saisie des notes est fermée en dur aux administrateurs (séparation des tâches,
        // voir Permissions::INTERDITES_AUX_ADMINISTRATEURS). Ces tests éprouvent la
        // mécanique de la feuille, pas l'autorisation : ils agissent donc sous un compte
        // qui y a droit — un rôle « métier » à qui `saisir_notes` est accordé.
        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'prof', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);

        $eco2 = fn (string $t) => DB::connection('ecoprim')->table($t);
        // Un code de rôle neutre : `enseignant` confinerait le compte au portail restreint
        // (RhUser::typePortail), qui n'atteint pas les routes /notes du personnel.
        $roleId = $eco2('console_roles')->insertGetId(['code' => 'pedagogie', 'nom' => 'Pédagogie']);
        $eco2('console_role_permissions')->insert(['role_id' => $roleId, 'permission_code' => 'saisir_notes']);
        $eco2('console_affectations')->insert([
            'rh_user_id' => 1, 'societe_code' => 'S1', 'etablissement_code' => 'E1',
            'role_id' => $roleId, 'actif' => true,
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
            ['num' => 2, 'CodeClasse' => 'CP2A', 'LibelleClasse' => 'CP2 A', 'CodN' => 'CP2', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 11, 'Matricule' => 'E-11', 'Nom' => 'Kouassi', 'Prenom' => 'Awa',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 12, 'Matricule' => 'E-12', 'Nom' => 'Bamba', 'Prenom' => 'Ali',
                'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 13, 'Matricule' => 'E-13', 'Nom' => 'Autre', 'Prenom' => 'Classe',
                'CodeClasse' => 'CP2A', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    private function criteres(array $sup = []): array
    {
        return array_merge([
            'classe' => 'CP1A', 'matiere' => 'MATH', 'session' => 'S1',
            'type' => 'Devoir', 'bareme' => 20, 'coefficient' => 2,
        ], $sup);
    }

    private function enregistrer(array $notes, array $sup = [])
    {
        return $this->postJson('/api/v1/notes/feuille', $this->criteres($sup) + ['notes' => $notes]);
    }

    private function details()
    {
        return DB::connection('economat')->table('T_NOTEDETAILS')->orderBy('Code')->get();
    }

    // ---- Découverte de structure -------------------------------------------------

    public function test_les_colonnes_reelles_sont_rapprochees_des_roles_metier(): void
    {
        $entete = SchemaNotes::correspondance(SchemaNotes::ENTETE);

        $this->assertSame('Code', $entete['id']);
        $this->assertSame('CodeClasse', $entete['classe']);
        $this->assertSame('CodeMatiere', $entete['matiere']);
        $this->assertSame('CodeSession', $entete['session']);
        $this->assertSame('NoteSur', $entete['bareme']);

        $detail = SchemaNotes::correspondance(SchemaNotes::DETAIL);

        // Le piège du rapprochement : `Code` est la clé, `CodeNote` le lien vers l'entête,
        // `Note` la valeur. Les trois commencent pareil et ne doivent pas se confondre.
        $this->assertSame('Code', $detail['id']);
        $this->assertSame('CodeNote', $detail['entete']);
        $this->assertSame('Note', $detail['note']);
        $this->assertSame('Matricule', $detail['eleve']);
    }

    public function test_une_colonne_ne_sert_qu_a_un_seul_role(): void
    {
        foreach ([SchemaNotes::ENTETE, SchemaNotes::DETAIL] as $table) {
            $colonnes = array_values(SchemaNotes::correspondance($table));
            $this->assertSame($colonnes, array_unique($colonnes), "Colonne réutilisée dans $table");
        }
    }

    public function test_la_structure_est_consultable_depuis_l_application(): void
    {
        $r = $this->getJson('/api/v1/notes/structure')->assertOk();

        $this->assertTrue($r->json('saisie_possible'));
        $this->assertContains('CodeMatiere', $r->json('entete.colonnes_reelles'));
        $this->assertSame([], $r->json('detail.roles_manquants'));
    }

    // ---- Refus : fermé par défaut ------------------------------------------------

    public function test_sans_table_de_details_la_saisie_est_refusee(): void
    {
        Schema::connection('economat')->drop('T_NOTEDETAILS');
        SchemaNotes::oublier();

        $this->assertFalse(SchemaNotes::saisiePossible());

        $r = $this->enregistrer([['matricule' => 'E-11', 'note' => 15]])->assertStatus(409);
        $this->assertStringContainsString('T_NOTEDETAILS', $r->json('message'));
        // Rien n'a été écrit dans l'entête non plus : le refus est antérieur à l'écriture.
        $this->assertSame(0, DB::connection('economat')->table('T_NOTEENTETE')->count());
    }

    public function test_une_colonne_essentielle_introuvable_ferme_la_saisie(): void
    {
        // On remplace la table des détails par une structure méconnaissable : plus de lien
        // vers l'entête. NEXORA ne doit pas tenter sa chance.
        Schema::connection('economat')->drop('T_NOTEDETAILS');
        Schema::connection('economat')->create('T_NOTEDETAILS', function ($t) {
            $t->integer('Code');
            $t->string('Champ1')->nullable();
            $t->string('Champ2')->nullable();
        });
        SchemaNotes::oublier();

        $this->assertContains('entete', SchemaNotes::manquants(SchemaNotes::DETAIL));
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15]])->assertStatus(409);
    }

    // ---- Saisie ------------------------------------------------------------------

    public function test_la_feuille_liste_tous_les_eleves_de_la_classe_meme_sans_note(): void
    {
        $r = $this->getJson('/api/v1/notes/feuille?'.http_build_query($this->criteres()))->assertOk();

        // Classement par nom : Bamba avant Kouassi.
        $this->assertSame(['E-12', 'E-11'], collect($r->json('eleves'))->pluck('matricule')->all());
        $this->assertNull($r->json('eleves.0.note'));
        $this->assertNull($r->json('entete'));
    }

    public function test_enregistrer_cree_l_entete_et_les_notes(): void
    {
        $this->enregistrer([
            ['matricule' => 'E-11', 'note' => 15],
            ['matricule' => 'E-12', 'note' => 11.5],
        ])->assertOk()->assertJson(['creees' => 2, 'modifiees' => 0]);

        $entete = DB::connection('economat')->table('T_NOTEENTETE')->first();
        $this->assertSame('CP1A', $entete->CodeClasse);
        $this->assertSame('MATH', $entete->CodeMatiere);
        $this->assertSame('S1', $entete->CodeSession);
        $this->assertSame(self::ANNEE, $entete->CodeAnnee);
        $this->assertEquals(2, $entete->Coefficient);
        $this->assertEquals(20, $entete->NoteSur);

        $details = $this->details();
        $this->assertCount(2, $details);
        $this->assertEquals($entete->Code, $details[0]->CodeNote);
        $this->assertEqualsWithDelta(15.0, (float) $details[0]->Note, 0.01);
    }

    public function test_ressaisir_la_meme_feuille_remplace_les_notes_sans_les_dupliquer(): void
    {
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15]])->assertOk();
        $this->enregistrer([['matricule' => 'E-11', 'note' => 17]])
            ->assertOk()->assertJson(['creees' => 0, 'modifiees' => 1]);

        // Une note en double serait comptée deux fois dans la moyenne d'ECONOMAT.
        $this->assertCount(1, $this->details());
        $this->assertEqualsWithDelta(17.0, (float) $this->details()[0]->Note, 0.01);
        $this->assertSame(1, DB::connection('economat')->table('T_NOTEENTETE')->count());
    }

    public function test_la_feuille_relue_rend_les_notes_deja_saisies(): void
    {
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15, 'appreciation' => 'Bien']])->assertOk();

        $r = $this->getJson('/api/v1/notes/feuille?'.http_build_query($this->criteres()))->assertOk();

        $this->assertNotNull($r->json('entete'));
        $eleves = collect($r->json('eleves'))->keyBy('matricule');
        $this->assertEqualsWithDelta(15.0, (float) $eleves['E-11']['note'], 0.01);
        $this->assertSame('Bien', $eleves['E-11']['appreciation']);
        // L'autre élève de la classe reste à saisir : la feuille ne l'invente pas.
        $this->assertNull($eleves['E-12']['note']);
    }

    public function test_deux_evaluations_distinctes_ne_partagent_pas_leur_entete(): void
    {
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15]])->assertOk();
        $this->enregistrer([['matricule' => 'E-11', 'note' => 8]], ['session' => 'S2'])->assertOk();

        $this->assertSame(2, DB::connection('economat')->table('T_NOTEENTETE')->count());
        $this->assertCount(2, $this->details());
    }

    public function test_un_absent_n_a_pas_de_note(): void
    {
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15, 'absent' => true]])->assertOk();

        $ligne = $this->details()[0];
        $this->assertTrue((bool) $ligne->Absent);
        // La note envoyée malgré l'absence ne doit pas rester : elle pèserait sur la moyenne.
        $this->assertNull($ligne->Note);
    }

    // ---- Garde-fous de saisie ----------------------------------------------------

    public function test_une_note_superieure_au_bareme_est_refusee(): void
    {
        $this->enregistrer([['matricule' => 'E-11', 'note' => 25]])
            ->assertStatus(422)->assertJsonValidationErrors('notes.0.note');

        $this->assertCount(0, $this->details());
    }

    public function test_le_bareme_de_la_feuille_fait_foi(): void
    {
        // Sur 10, un 15 est une faute de frappe ; sur 20 il est valide.
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15]], ['bareme' => 10])->assertStatus(422);
        $this->enregistrer([['matricule' => 'E-11', 'note' => 8]], ['bareme' => 10])->assertOk();
    }

    public function test_on_ne_note_pas_un_eleve_d_une_autre_classe(): void
    {
        $this->enregistrer([
            ['matricule' => 'E-11', 'note' => 15],
            ['matricule' => 'E-13', 'note' => 12],
        ])->assertStatus(422)->assertJsonValidationErrors('notes.1.matricule');

        // Le refus porte sur toute la feuille : aucune note partielle n'est enregistrée.
        $this->assertCount(0, $this->details());
    }

    public function test_une_annee_cloturee_interdit_la_saisie(): void
    {
        $this->postJson('/api/v1/contexte/annee', ['annee' => '2024-2025'])->assertOk();

        // 423 Locked : c'est le verrou d'année, pas un refus de structure.
        $this->enregistrer([['matricule' => 'E-11', 'note' => 15]])->assertStatus(423);
        $this->assertCount(0, $this->details());
    }

    public function test_la_saisie_exige_une_authentification(): void
    {
        app('auth')->forgetGuards();
        $this->app['auth']->guard('sanctum')->forgetUser();

        $this->postJson('/api/v1/notes/feuille', $this->criteres() + [
            'notes' => [['matricule' => 'E-11', 'note' => 15]],
        ])->assertStatus(401);
    }
}
