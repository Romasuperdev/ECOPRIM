<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Étape 3 des portails mobiles : évaluations notées de l'enseignant — création, saisie,
 * brouillon invisible des parents, publication qui les prévient.
 */
class PortailNotesTest extends TestCase
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
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);

        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $eco('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01',
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
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa', 'LOGIN' => 'mtraore'],
        ]);
        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE, 'Principale' => true],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'EL1', 'Nom' => 'Koné', 'Prenom' => 'Awa', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 3, 'Matricule' => 'EL3', 'Nom' => 'Bamba', 'Prenom' => 'Ali', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 2, 'Matricule' => 'EL2', 'Nom' => 'Yao', 'Prenom' => 'Kofi', 'CodeClasse' => 'CP1B', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    private function compte(int $id, string $login, string $role, array $enfants = []): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);
        $eco = DB::connection('ecoprim');
        $roleId = $eco->table('console_roles')->where('code', $role)->value('id')
            ?? $eco->table('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
        $aff = $eco->table('console_affectations')->insertGetId([
            'rh_user_id' => $id, 'societe_code' => 'S1', 'etablissement_code' => 'E1', 'role_id' => $roleId, 'actif' => true,
        ]);
        foreach ($enfants as $m) {
            $eco->table('console_affectation_eleves')->insert(['affectation_id' => $aff, 'eleve_matricule' => $m]);
        }

        return $rh;
    }

    private function enseignant(): RhUser
    {
        return $this->compte(10, 'mtraore', RhUser::ROLE_ENSEIGNANT);
    }

    private function parent(): RhUser
    {
        return $this->compte(20, 'parent1', RhUser::ROLE_PARENT, ['EL1']);
    }

    private function creer(array $extra = [])
    {
        return $this->postJson('/api/v1/mon-espace/enseignant/evaluations-notees', $extra + [
            'classe' => 'CP1A', 'matiere' => 'MATH', 'titre' => 'Calcul mental n°3',
            'type' => 'Interrogation', 'session' => 'S1', 'date' => '2025-10-06', 'bareme' => 20,
        ]);
    }

    private function saisir(int $entete, array $notes, bool $publier = false)
    {
        return $this->putJson("/api/v1/mon-espace/enseignant/evaluations-notees/{$entete}/notes", [
            'notes' => $notes, 'publier' => $publier,
        ]);
    }

    /** La vue V_NOTECLASSE n'est qu'une table en test : on y reflète ce qui a été saisi. */
    private function refleterDansLaVue(int $entete): void
    {
        foreach (DB::connection('economat')->table('T_NOTEDETAILS')->where('CodeNote', $entete)->get() as $d) {
            DB::connection('economat')->table('V_NOTECLASSE')->insert([
                'Code' => $entete, 'Matricule' => $d->Matricule, 'Nom' => 'x', 'Prenom' => 'x', 'Note' => $d->Note,
                'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques', 'TypeNote' => 'Interrogation',
                'CodeClasse' => 'CP1A', 'CodeSession' => 'S1', 'CodeAnnee' => self::ANNEE, 'Coefficient' => 1,
            ]);
        }
    }

    public function test_creer_une_evaluation_ouvre_une_feuille_en_brouillon(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');

        $r = $this->creer()->assertCreated()
            ->assertJsonPath('titre', 'Calcul mental n°3')
            ->assertJsonPath('statut', 'brouillon')
            ->assertJsonPath('bareme', 20)
            ->assertJsonCount(2, 'eleves');

        $entete = DB::connection('economat')->table('T_NOTEENTETE')->first();
        $this->assertSame((int) $entete->Code, $r->json('entete'));
        $this->assertSame('CP1A', $entete->CodeClasse);
        $this->assertSame('2025-10-06', substr((string) $entete->DateNote, 0, 10));

        // Même matière, même type, même jour : ECONOMAT n'y verrait qu'une évaluation.
        $this->creer(['titre' => 'Autre'])->assertUnprocessable()->assertJsonValidationErrors('date');
        // Un autre type le même jour reste possible.
        $this->creer(['titre' => 'Dictée', 'type' => 'Devoir'])->assertCreated();
    }

    public function test_le_brouillon_reste_invisible_des_parents_jusqu_a_la_publication(): void
    {
        $parent = $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');
        $entete = $this->creer()->json('entete');

        $this->saisir($entete, [
            ['matricule' => 'EL1', 'note' => 15], ['matricule' => 'EL3', 'note' => 8],
        ])->assertOk()->assertJsonPath('feuille.statut', 'brouillon')->assertJsonPath('parents_prevenus', 0);
        $this->refleterDansLaVue($entete);

        $this->assertSame(0, DB::connection('ecoprim')->table('portail_notifications')->count());

        $this->actingAs($parent, 'sanctum');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/notes')->assertOk()->assertJsonCount(0, 'notes');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/moyennes')->assertOk()->assertJsonPath('moyenne_generale', null);

        // Publication : visible et parents prévenus.
        $this->actingAs(RhUser::on('master')->find(10), 'sanctum');
        $this->saisir($entete, [
            ['matricule' => 'EL1', 'note' => 15], ['matricule' => 'EL3', 'note' => 8],
        ], true)->assertOk()->assertJsonPath('feuille.statut', 'publiee')->assertJsonPath('parents_prevenus', 1);

        $notif = DB::connection('ecoprim')->table('portail_notifications')->where('rh_user_id', 20)->first();
        $this->assertSame('note', $notif->type);
        $this->assertSame('Nouvelle note : Calcul mental n°3', $notif->titre);
        $this->assertSame('Awa : 15/20 en Mathématiques.', $notif->corps);

        $this->actingAs($parent, 'sanctum');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/notes')->assertOk()
            ->assertJsonPath('notes.0.titre', 'Calcul mental n°3')
            ->assertJsonPath('notes.0.note', 15)
            ->assertJsonPath('notes.0.bareme', 20);
        $this->assertNotNull($this->getJson('/api/v1/mon-espace/parent/enfants/EL1/moyennes')->json('moyenne_generale'));

        // Corriger une note publiée ne renvoie pas de notification.
        $this->actingAs(RhUser::on('master')->find(10), 'sanctum');
        $this->saisir($entete, [['matricule' => 'EL1', 'note' => 16]], true)->assertJsonPath('parents_prevenus', 0);
        $this->assertSame(1, DB::connection('ecoprim')->table('portail_notifications')->count());
    }

    public function test_absent_a_l_evaluation_efface_la_note_et_le_parent_le_sait(): void
    {
        $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');
        $entete = $this->creer()->json('entete');

        $this->saisir($entete, [['matricule' => 'EL1', 'note' => 12]]);
        $this->saisir($entete, [['matricule' => 'EL1', 'absent' => true]], true)
            ->assertJsonPath('feuille.eleves.1.absent', true)   // Bamba, Koné
            ->assertJsonPath('feuille.eleves.1.note', null);

        $this->assertNull(DB::connection('economat')->table('T_NOTEDETAILS')->where('Matricule', 'EL1')->value('Note'));
        $this->assertStringContainsString('absent(e)', DB::connection('ecoprim')->table('portail_notifications')->value('corps'));
    }

    public function test_garde_fous_bareme_matiere_et_classe(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');
        $entete = $this->creer()->json('entete');

        $this->saisir($entete, [['matricule' => 'EL1', 'note' => 25]])->assertUnprocessable();
        $this->saisir($entete, [['matricule' => 'EL2', 'note' => 10]])->assertUnprocessable(); // autre classe

        $this->creer(['matiere' => 'FR'])->assertForbidden();             // matière d'un collègue
        $this->creer(['classe' => 'CP1B'])->assertForbidden();            // classe qu'il n'a pas
        $this->getJson('/api/v1/mon-espace/enseignant/evaluations-notees?classe=CP1B')->assertForbidden();
    }

    public function test_la_liste_montre_aussi_les_evaluations_saisies_avant(): void
    {
        DB::connection('economat')->table('T_NOTEENTETE')->insert([
            'Code' => 90, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeSession' => 'S1',
            'CodeAnnee' => self::ANNEE, 'TypeNote' => 'Composition', 'DateNote' => '2025-09-30', 'Coefficient' => 1,
        ]);
        DB::connection('economat')->table('T_NOTEDETAILS')->insert([
            ['Code' => 1, 'CodeNote' => 90, 'Matricule' => 'EL1', 'Note' => 14],
            ['Code' => 2, 'CodeNote' => 90, 'Matricule' => 'EL3', 'Note' => 10],
        ]);
        $this->actingAs($this->enseignant(), 'sanctum');
        $this->creer();

        $r = $this->getJson('/api/v1/mon-espace/enseignant/evaluations-notees?classe=CP1A')->assertOk()
            ->assertJsonCount(2, 'evaluations');
        $ancienne = collect($r->json('evaluations'))->firstWhere('entete', 90);
        $this->assertSame('publiee', $ancienne['statut']);
        $this->assertSame(2, $ancienne['notes']);
        $this->assertEqualsWithDelta(12.0, $ancienne['moyenne_sur_20'], 0.01);
        $this->assertSame('brouillon', collect($r->json('evaluations'))->firstWhere('titre', 'Calcul mental n°3')['statut']);

        // L'ouvrir la rattache, publiée : elle était déjà visible des parents.
        $this->getJson('/api/v1/mon-espace/enseignant/evaluations-notees/90')->assertOk()
            ->assertJsonPath('statut', 'publiee')
            ->assertJsonPath('eleves.1.note', 14);
    }
}
