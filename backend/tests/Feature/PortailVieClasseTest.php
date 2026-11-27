<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Étape 4 des portails mobiles : devoirs avec photo du tableau, annonces aux parents d'une
 * classe, et leur lecture côté parent.
 */
class PortailVieClasseTest extends TestCase
{
    private const ANNEE = '2025-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Fichiers joints dans un dossier jetable : jamais dans le storage du poste.
        $this->app->useStoragePath(sys_get_temp_dir().'/nexora-tests-'.uniqid());
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

    private function familles(): array
    {
        return [
            $this->compte(20, 'parent1', RhUser::ROLE_PARENT, ['EL1']),
            $this->compte(21, 'parent3', RhUser::ROLE_PARENT, ['EL3']),
            $this->compte(22, 'parent2', RhUser::ROLE_PARENT, ['EL2']), // autre classe
        ];
    }

    private function enseignant(): RhUser
    {
        return $this->compte(10, 'mtraore', RhUser::ROLE_ENSEIGNANT);
    }

    private function notifications(int $id)
    {
        return DB::connection('ecoprim')->table('portail_notifications')->where('rh_user_id', $id);
    }

    private function donnerDevoir(array $extra = [])
    {
        return $this->post('/api/v1/mon-espace/enseignant/devoirs-classe', $extra + [
            'classe' => 'CP1A', 'matiere' => 'MATH', 'titre' => 'Exercices 4 et 5 page 32',
            'consigne' => 'Faire sur le cahier de brouillon.', 'date_remise' => now()->addDays(2)->toDateString(),
        ], ['Accept' => 'application/json']);
    }

    public function test_un_devoir_avec_photo_previent_chaque_parent_vers_son_enfant(): void
    {
        [$p1, , $p2] = $this->familles();
        $this->actingAs($this->enseignant(), 'sanctum');

        $r = $this->donnerDevoir(['pieces' => [
            UploadedFile::fake()->image('tableau.jpg'), UploadedFile::fake()->image('fiche.png'),
        ]])->assertCreated()->assertJsonPath('mien', true)->assertJsonCount(2, 'pieces');
        $devoir = $r->json('id');
        $piece = $r->json('pieces.0.id');

        $this->assertCount(2, File::allFiles(storage_path('app/devoirs')));
        $this->get("/api/v1/mon-espace/enseignant/devoirs-classe/{$devoir}/pieces/{$piece}")->assertOk();

        $n1 = $this->notifications(20)->first();
        $this->assertSame('devoir', $n1->type);
        $this->assertSame('Nouveau devoir en Mathématiques', $n1->titre);
        $this->assertStringContainsString('Photo jointe', $n1->corps);
        $this->assertSame('/mon-espace/enfants/EL1?onglet=devoirs', $n1->lien);
        $this->assertSame('/mon-espace/enfants/EL3?onglet=devoirs', $this->notifications(21)->value('lien'));
        $this->assertSame(0, $this->notifications(22)->count(), "le parent d'une autre classe n'est pas prévenu");

        // Le parent lit le devoir et sa photo ; celui d'une autre classe, non.
        $this->actingAs($p1, 'sanctum');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/vie-de-classe')->assertOk()
            ->assertJsonPath('devoirs.0.titre', 'Exercices 4 et 5 page 32')
            ->assertJsonCount(2, 'devoirs.0.pieces');
        $this->get("/api/v1/mon-espace/parent/devoirs/{$devoir}/pieces/{$piece}")->assertOk();

        $this->actingAs($p2, 'sanctum');
        $this->getJson("/api/v1/mon-espace/parent/devoirs/{$devoir}/pieces/{$piece}")->assertForbidden();
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/vie-de-classe')->assertForbidden();
    }

    public function test_garde_fous_du_devoir(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->donnerDevoir(['matiere' => 'FR'])->assertForbidden();
        $this->donnerDevoir(['classe' => 'CP1B'])->assertForbidden();
        $this->donnerDevoir(['date_remise' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('date_remise');
        $this->donnerDevoir(['pieces' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 5))])
            ->assertUnprocessable()->assertJsonValidationErrors('pieces');
        $this->donnerDevoir(['pieces' => [UploadedFile::fake()->create('virus.exe', 10)]])
            ->assertUnprocessable();
    }

    public function test_on_ne_modifie_ou_retire_que_ses_propres_devoirs(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');
        // Devoir saisi par le secrétariat, sans enseignant.
        $autre = DB::connection('ecoprim')->table('devoirs')->insertGetId([
            'titre' => 'Lecture', 'classe_code' => 'CP1A', 'matiere_code' => 'MATH',
            'date_remise' => now()->addDay()->toDateString(), 'annee' => self::ANNEE,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->putJson("/api/v1/mon-espace/enseignant/devoirs-classe/{$autre}", ['titre' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/mon-espace/enseignant/devoirs-classe/{$autre}")->assertForbidden();

        $mien = $this->donnerDevoir(['pieces' => [UploadedFile::fake()->image('t.jpg')]])->json('id');
        $this->putJson("/api/v1/mon-espace/enseignant/devoirs-classe/{$mien}", ['titre' => 'Exercice 6'])
            ->assertOk()->assertJsonPath('titre', 'Exercice 6');
        $this->deleteJson("/api/v1/mon-espace/enseignant/devoirs-classe/{$mien}")->assertNoContent();

        $this->assertDatabaseMissing('devoirs', ['id' => $mien], 'ecoprim');
        $this->assertDatabaseCount('devoir_pieces', 0, 'ecoprim');
        $this->assertCount(0, File::allFiles(storage_path('app/devoirs')));
    }

    public function test_une_annonce_previent_les_parents_de_la_classe(): void
    {
        [$p1, , $p2] = $this->familles();
        $this->actingAs($this->enseignant(), 'sanctum');

        $r = $this->post('/api/v1/mon-espace/enseignant/annonces', [
            'classe' => 'CP1A', 'titre' => 'Sortie scolaire demain',
            'message' => 'Prévoir une casquette et une gourde.',
            'photo' => UploadedFile::fake()->image('affiche.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('parents_prevenus', 2)
            ->assertJsonPath('a_photo', true)
            ->assertJsonPath('mienne', true);
        $id = $r->json('id');

        $this->assertSame('CP1 A : Sortie scolaire demain', $this->notifications(20)->value('titre'));
        $this->assertSame('Prévoir une casquette et une gourde.', $this->notifications(21)->value('corps'));
        $this->assertSame(0, $this->notifications(22)->count());

        $this->actingAs($p1, 'sanctum');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/vie-de-classe')
            ->assertJsonPath('annonces.0.titre', 'Sortie scolaire demain')
            ->assertJsonPath('annonces.0.mienne', false);
        $this->get("/api/v1/mon-espace/parent/annonces/{$id}/photo")->assertOk();

        $this->actingAs($p2, 'sanctum');
        $this->getJson("/api/v1/mon-espace/parent/annonces/{$id}/photo")->assertForbidden();

        // L'auteur la retire ; la classe d'un autre lui reste fermée.
        $this->actingAs(RhUser::on('master')->find(10), 'sanctum');
        $this->post('/api/v1/mon-espace/enseignant/annonces', ['classe' => 'CP1B', 'titre' => 'x'], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->deleteJson("/api/v1/mon-espace/enseignant/annonces/{$id}")->assertNoContent();
        $this->assertDatabaseCount('annonces', 0, 'ecoprim');
    }
}
