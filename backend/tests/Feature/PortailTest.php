<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Portails restreints Enseignant et Parent : un compte RH_USER affecté du SEUL rôle
 * correspondant (console_affectations) n'a accès qu'à ses propres classes / enfants,
 * jamais à l'application complète — RhUser::typePortail() décide, PortailMiddleware
 * l'applique, chaque contrôleur de portail vérifie en plus la propriété de la ressource
 * visée avant de déléguer aux contrôleurs existants.
 */
class PortailTest extends TestCase
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
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa', 'LOGIN' => 'mtraore'],
        ]);
        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'EL1', 'Nom' => 'Koné', 'Prenom' => 'Awa', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 2, 'Matricule' => 'EL2', 'Nom' => 'Yao', 'Prenom' => 'Kofi', 'CodeClasse' => 'CP1B', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    // --- Comptes de test ---

    private function compte(int $id, string $login, bool $superAdmin = false): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'MotDePasse' => Hash::make('x'),
            'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ]);
    }

    private function roleId(string $code): int
    {
        return DB::connection('ecoprim')->table('console_roles')->insertGetId(['code' => $code, 'nom' => ucfirst($code)]);
    }

    private function affecter(int $rhUserId, int $roleId, array $extra = []): int
    {
        return DB::connection('ecoprim')->table('console_affectations')->insertGetId(array_merge([
            'rh_user_id' => $rhUserId, 'societe_code' => 'S1', 'etablissement_code' => 'E1',
            'role_id' => $roleId, 'actif' => true,
        ], $extra));
    }

    private function enseignant(): RhUser
    {
        $rh = $this->compte(10, 'mtraore');
        $this->affecter(10, $this->roleId(RhUser::ROLE_ENSEIGNANT));
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    private function parent(array $matricules = ['EL1']): RhUser
    {
        $rh = $this->compte(20, 'parent1');
        $affectationId = $this->affecter(20, $this->roleId(RhUser::ROLE_PARENT));
        foreach ($matricules as $m) {
            DB::connection('ecoprim')->table('console_affectation_eleves')
                ->insert(['affectation_id' => $affectationId, 'eleve_matricule' => $m]);
        }
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    private function staff(): RhUser
    {
        $rh = $this->compte(30, 'secretaire1');
        $this->affecter(30, $this->roleId('secretaire'));
        $this->actingAs($rh, 'sanctum');

        return $rh;
    }

    // --- Type de portail ---

    public function test_sans_affectation_le_compte_reste_staff(): void
    {
        $rh = $this->compte(1, 'ancien');
        $this->assertSame('staff', $rh->typePortail());
    }

    public function test_seul_le_role_enseignant_donne_le_portail_enseignant(): void
    {
        $rh = $this->enseignant();
        $this->assertSame('enseignant', $rh->typePortail());
    }

    public function test_seul_le_role_parent_donne_le_portail_parent(): void
    {
        $rh = $this->parent();
        $this->assertSame('parent', $rh->typePortail());
    }

    public function test_un_role_supplementaire_annule_le_portail_restreint(): void
    {
        $rh = $this->compte(11, 'mixte');
        $this->affecter(11, $this->roleId(RhUser::ROLE_ENSEIGNANT));
        $this->affecter(11, $this->roleId('direction'));

        $this->assertSame('staff', $rh->typePortail());
    }

    // --- Portes fermées / ouvertes selon le portail ---

    public function test_un_enseignant_n_accede_pas_a_l_application_complete(): void
    {
        $this->enseignant();
        $this->getJson('/api/v1/eleves')->assertStatus(403);
    }

    public function test_un_parent_n_accede_pas_a_l_application_complete(): void
    {
        $this->parent();
        $this->getJson('/api/v1/classes')->assertStatus(403);
    }

    public function test_un_compte_staff_n_accede_pas_aux_portails_restreints(): void
    {
        $this->staff();
        $this->getJson('/api/v1/mon-espace/enseignant/classes')->assertStatus(403);
        $this->getJson('/api/v1/mon-espace/parent/enfants')->assertStatus(403);
    }

    public function test_un_parent_n_accede_pas_au_portail_enseignant_et_inversement(): void
    {
        $this->parent();
        $this->getJson('/api/v1/mon-espace/enseignant/classes')->assertStatus(403);
    }

    // --- Portail Enseignant ---

    public function test_l_enseignant_ne_voit_que_ses_propres_classes(): void
    {
        $this->enseignant();

        $r = $this->getJson('/api/v1/mon-espace/enseignant/classes')->assertOk();
        $this->assertCount(1, $r->json('classes'));
        $this->assertSame('CP1A', $r->json('classes.0.classe'));
    }

    public function test_l_enseignant_peut_saisir_le_cahier_de_textes_de_sa_classe(): void
    {
        $this->enseignant();

        $this->postJson('/api/v1/mon-espace/enseignant/cahier-textes', [
            'classe' => 'CP1A', 'mois' => 'Septembre', 'semaine' => '1',
        ])->assertCreated();
    }

    public function test_l_enseignant_ne_peut_pas_toucher_une_classe_qu_il_n_enseigne_pas(): void
    {
        $this->enseignant();

        $this->postJson('/api/v1/mon-espace/enseignant/cahier-textes', [
            'classe' => 'CP1B', 'mois' => 'Septembre', 'semaine' => '1',
        ])->assertStatus(403);

        $this->getJson('/api/v1/mon-espace/enseignant/classes/CP1B/eleves')->assertStatus(403);
    }

    public function test_l_enseignant_ne_peut_pas_saisir_une_absence_hors_de_sa_classe(): void
    {
        $this->enseignant();

        // EL2 est dans CP1B, que ce professeur n'enseigne pas.
        $this->postJson('/api/v1/mon-espace/enseignant/absences', [
            'matricule' => 'EL2', 'date' => '2025-09-10',
        ])->assertStatus(403);
    }

    public function test_l_enseignant_peut_saisir_une_absence_dans_sa_classe(): void
    {
        $this->enseignant();

        $this->postJson('/api/v1/mon-espace/enseignant/absences', [
            'matricule' => 'EL1', 'date' => '2025-09-10',
        ])->assertCreated();
    }

    // --- Portail Parent ---

    public function test_le_parent_ne_voit_que_ses_enfants_rattaches(): void
    {
        $this->parent(['EL1']);

        $r = $this->getJson('/api/v1/mon-espace/parent/enfants')->assertOk();
        $this->assertCount(1, $r->json('enfants'));
        $this->assertSame('EL1', $r->json('enfants.0.matricule'));
    }

    public function test_le_parent_ne_peut_pas_consulter_un_enfant_qui_n_est_pas_le_sien(): void
    {
        $this->parent(['EL1']);

        $this->getJson('/api/v1/mon-espace/parent/enfants/EL2')->assertStatus(403);
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL2/absences')->assertStatus(403);
    }

    public function test_le_parent_consulte_le_cahier_de_textes_de_la_classe_de_son_enfant(): void
    {
        $this->parent(['EL1']);
        DB::connection('economat')->table('T_ENTETE_JOURNAL')->insert([
            'CodeEntete' => 1, 'Annee' => self::ANNEE, 'Mois' => 'Septembre', 'Semaine' => '1',
            'CodeClasse' => 'CP1A', 'CodeNiveau' => 'CP1', 'NumSem' => 1,
        ]);

        $r = $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/cahier-textes')->assertOk();
        $this->assertCount(1, $r->json('entetes'));
    }

    public function test_les_moyennes_du_parent_ne_montrent_que_son_enfant(): void
    {
        $this->parent(['EL1']);
        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $eco('V_MOYENNE_ELEVE_CLASSE')->insert([
            ['Code' => 1, 'Nom' => 'Koné', 'Prenom' => 'Awa', 'Moyenne' => 15.5, 'Rang' => '1', 'CodeEleve' => 1,
                'Matricule' => 'EL1', 'CodeClasse' => 'CP1A', 'CodeAnnee' => self::ANNEE],
            ['Code' => 2, 'Nom' => 'Autre', 'Prenom' => 'Élève', 'Moyenne' => 12.0, 'Rang' => '2', 'CodeEleve' => 3,
                'Matricule' => 'EL3', 'CodeClasse' => 'CP1A', 'CodeAnnee' => self::ANNEE],
        ]);

        $r = $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/moyennes')->assertOk();
        $this->assertSame(15.5, $r->json('moyenne_generale'));
        $this->assertSame(2, $r->json('effectif'));
        // Jamais le détail d'un autre élève de la classe.
        $this->assertArrayNotHasKey('classement', $r->json());
        $this->assertStringNotContainsString('EL3', $r->getContent());
    }

    // --- Gestion des enfants d'un compte Parent, depuis la fiche utilisateur (admin) ---

    public function test_une_affectation_parent_peut_etre_creee_avec_ses_enfants(): void
    {
        $superAdmin = $this->compte(1, 'boss', true);
        DB::connection('ecoprim')->table('console_societes')->insert(['id' => 1, 'code' => 'S1', 'nom' => 'Société', 'actif' => true]);
        DB::connection('ecoprim')->table('console_etablissements')
            ->insert(['id' => 1, 'code' => 'E1', 'intitule' => 'École', 'societe_code' => 'S1', 'actif' => true]);
        $roleId = $this->roleId(RhUser::ROLE_PARENT);
        $this->compte(20, 'parent1');
        $this->actingAs($superAdmin, 'sanctum');

        $r = $this->postJson('/api/v1/affectations', [
            'rh_user_id' => 20, 'etablissement_code' => 'E1', 'role_id' => $roleId,
            'eleves' => ['EL1', 'EL2'],
        ])->assertCreated();

        $this->assertCount(2, $r->json('eleves'));
        $this->assertDatabaseCount('console_affectation_eleves', 2, 'ecoprim');
    }
}
