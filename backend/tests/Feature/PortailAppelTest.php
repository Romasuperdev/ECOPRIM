<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Étape 2 des portails mobiles : l'appel en 30 secondes (absents écrits dans ECONOMAT,
 * retards dans NEXORA, parents prévenus) et les justificatifs d'absence.
 */
class PortailAppelTest extends TestCase
{
    private const ANNEE = '2025-2026';

    private string $aujourdhui;

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
        $this->aujourdhui = now()->toDateString();

        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $eco('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01',
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
            ['num' => 2, 'CodeClasse' => 'CP1B', 'LibelleClasse' => 'CP1 B', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
        ]);
        $eco('T_PROFESSEUR')->insert([
            ['Code' => 7, 'MatriculeProfesseur' => 'P7', 'NomProfesseur' => 'Traoré', 'PrenomProfesseur' => 'Moussa', 'LOGIN' => 'mtraore'],
        ]);
        $eco('T_CORPROFCLASSE')->insert([
            ['Code' => 1, 'CodeClasse' => 'CP1A', 'CodeMatiere' => 'MATH', 'CodeProfesseur' => 7, 'ANNEE' => self::ANNEE, 'Principale' => true],
        ]);
        $eco('T_ETUDIANT')->insert([
            ['Code' => 1, 'Matricule' => 'EL1', 'Nom' => 'Koné', 'Prenom' => 'Awa', 'Sexe' => 'F', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 3, 'Matricule' => 'EL3', 'Nom' => 'Bamba', 'Prenom' => 'Ali', 'Sexe' => 'M', 'CodeClasse' => 'CP1A', 'AnneeAcad' => self::ANNEE],
            ['Code' => 2, 'Matricule' => 'EL2', 'Nom' => 'Yao', 'Prenom' => 'Kofi', 'Sexe' => 'M', 'CodeClasse' => 'CP1B', 'AnneeAcad' => self::ANNEE],
        ]);
    }

    private function compte(int $id, string $login, ?string $role = null, array $enfants = [], bool $superAdmin = false): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => strtoupper($login), 'Prenom' => 'X',
            'Email' => $login.'@ecole.ci', 'MotDePasse' => Hash::make('x'),
            'SuperAdmin' => $superAdmin, 'Supprimer' => false,
        ]);
        if ($role) {
            $eco = DB::connection('ecoprim');
            $roleId = $eco->table('console_roles')->where('code', $role)->value('id')
                ?? $eco->table('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
            $aff = $eco->table('console_affectations')->insertGetId([
                'rh_user_id' => $id, 'societe_code' => 'S1', 'etablissement_code' => 'E1', 'role_id' => $roleId, 'actif' => true,
            ]);
            foreach ($enfants as $m) {
                $eco->table('console_affectation_eleves')->insert(['affectation_id' => $aff, 'eleve_matricule' => $m]);
            }
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

    private function appel(array $absents, array $retards = [], ?string $envoi = null, string $creneau = 'matin')
    {
        return $this->postJson('/api/v1/mon-espace/enseignant/appel', [
            'classe' => 'CP1A', 'date' => $this->aujourdhui, 'creneau' => $creneau,
            'absents' => $absents, 'retards' => $retards, 'envoi' => $envoi,
        ]);
    }

    private function absencesEconomat()
    {
        return DB::connection('economat')->table('T_ABSENCEELEVE');
    }

    private function notificationsDe(int $id)
    {
        return DB::connection('ecoprim')->table('portail_notifications')->where('rh_user_id', $id);
    }

    public function test_la_feuille_d_appel_liste_la_classe_tous_presents(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');

        $r = $this->getJson("/api/v1/mon-espace/enseignant/appel?classe=CP1A&date={$this->aujourdhui}&creneau=matin")
            ->assertOk()
            ->assertJsonPath('classe_libelle', 'CP1 A')
            ->assertJsonPath('appel', null)
            ->assertJsonCount(2, 'eleves');

        $this->assertSame(['present', 'present'], collect($r->json('eleves'))->pluck('statut')->all());
    }

    public function test_valider_l_appel_ecrit_l_absence_et_previent_le_parent(): void
    {
        $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->appel(['EL1'])->assertOk()
            ->assertJsonPath('absents', 1)
            ->assertJsonPath('effectif', 2)
            ->assertJsonPath('parents_prevenus', 1);

        $absence = $this->absencesEconomat()->where('Matricule', 'EL1')->first();
        $this->assertNotNull($absence);
        $this->assertSame('Matin', $absence->Heure);
        $this->assertSame('CP1A', $absence->CodeClasse);
        $this->assertFalse((bool) $absence->Justifier);

        $notif = $this->notificationsDe(20)->first();
        $this->assertSame('absence', $notif->type);
        $this->assertSame('Absence de Awa', $notif->titre);
        $this->assertStringContainsString('ce matin', $notif->corps);
        $this->assertSame('/mon-espace/enfants/EL1?onglet=absences', $notif->lien);

        // La feuille rouverte montre l'appel fait.
        $this->getJson("/api/v1/mon-espace/enseignant/appel?classe=CP1A&date={$this->aujourdhui}&creneau=matin")
            ->assertJsonPath('appel.absents', 1)
            ->assertJsonPath('eleves.1.statut', 'absent'); // Bamba, Koné : Koné en second
    }

    public function test_un_renvoi_hors_connexion_n_est_traite_qu_une_fois(): void
    {
        $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->appel(['EL1'], [], 'envoi-1')->assertJsonPath('deja_recu', false);
        $this->appel(['EL1'], [], 'envoi-1')->assertJsonPath('deja_recu', true);

        $this->assertSame(1, $this->absencesEconomat()->count());
        $this->assertSame(1, $this->notificationsDe(20)->count());
    }

    public function test_corriger_l_appel_retire_l_absence_creee_sans_reprevenir(): void
    {
        $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->appel(['EL1', 'EL3']);
        $this->appel(['EL3']); // EL1 était là finalement

        $this->assertSame(['EL3'], $this->absencesEconomat()->pluck('Matricule')->all());
        $this->assertSame(1, $this->notificationsDe(20)->count(), 'pas de nouvelle notification pour une correction');
        $this->assertDatabaseCount('appels', 1, 'ecoprim');
    }

    public function test_une_absence_saisie_par_le_secretariat_n_est_jamais_supprimee(): void
    {
        $this->absencesEconomat()->insert([
            'Code' => 50, 'Matricule' => 'EL1', 'CodeClasse' => 'CP1A', 'Heure' => 'Matin',
            'Date' => $this->aujourdhui, 'AnneeCour' => self::ANNEE, 'Justifier' => false,
        ]);
        $this->actingAs($this->enseignant(), 'sanctum');

        // Déjà cochée à l'ouverture de la feuille.
        $this->getJson("/api/v1/mon-espace/enseignant/appel?classe=CP1A&date={$this->aujourdhui}&creneau=matin")
            ->assertJsonPath('eleves.1.statut', 'absent');

        $this->appel(['EL1']);
        $this->assertSame(1, $this->absencesEconomat()->count(), 'reprise, pas doublée');

        $this->appel([]);
        $this->assertSame([50], $this->absencesEconomat()->pluck('Code')->map(fn ($c) => (int) $c)->all());
    }

    public function test_un_retard_reste_dans_nexora_et_previent_le_parent(): void
    {
        $this->parent();
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->appel([], ['EL1'])->assertJsonPath('retards', 1)->assertJsonPath('absents', 0);

        $this->assertSame(0, $this->absencesEconomat()->count());
        $this->assertSame('Retard de Awa', $this->notificationsDe(20)->value('titre'));

        // Le parent le voit dans l'assiduité de son enfant.
        $this->actingAs(RhUser::on('master')->find(20), 'sanctum');
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/assiduite')->assertOk()
            ->assertJsonCount(0, 'absences')
            ->assertJsonPath('retards.0.creneau', 'Matin');
    }

    public function test_l_appel_est_borne_a_ses_classes_et_a_ses_eleves(): void
    {
        $this->actingAs($this->enseignant(), 'sanctum');

        $this->postJson('/api/v1/mon-espace/enseignant/appel', [
            'classe' => 'CP1B', 'date' => $this->aujourdhui, 'creneau' => 'matin', 'absents' => ['EL2'], 'retards' => [],
        ])->assertForbidden();

        // EL2 n'est pas en CP1A.
        $this->appel(['EL2'])->assertUnprocessable();

        // Pas d'appel pour demain.
        $this->postJson('/api/v1/mon-espace/enseignant/appel', [
            'classe' => 'CP1A', 'date' => now()->addDay()->toDateString(), 'creneau' => 'matin', 'absents' => [], 'retards' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, $this->absencesEconomat()->count());
    }

    public function test_le_parent_justifie_l_enseignant_decide(): void
    {
        $parent = $this->parent();
        $enseignant = $this->enseignant();

        $this->actingAs($enseignant, 'sanctum');
        $this->appel(['EL1']);
        $code = (int) $this->absencesEconomat()->value('Code');

        // Le parent envoie un mot et une photo.
        $this->actingAs($parent, 'sanctum');
        $r = $this->post("/api/v1/mon-espace/parent/enfants/EL1/absences/{$code}/justificatif", [
            'message' => 'Fièvre, certificat joint.',
            'fichier' => UploadedFile::fake()->image('certificat.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('statut', 'en_attente');
        $id = $r->json('id');

        // Un second envoi pendant l'attente est refusé.
        $this->post("/api/v1/mon-espace/parent/enfants/EL1/absences/{$code}/justificatif",
            ['message' => 'Encore'], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->getJson('/api/v1/mon-espace/parent/enfants/EL1/assiduite')
            ->assertJsonPath('absences.0.justificatif.statut', 'en_attente');

        // L'enseignant est prévenu, voit le justificatif et la photo.
        $this->assertSame('justificatif', $this->notificationsDe(10)->value('type'));
        $this->actingAs($enseignant, 'sanctum');
        $this->getJson('/api/v1/mon-espace/enseignant/justificatifs')->assertOk()
            ->assertJsonPath('justificatifs.0.eleve', 'Awa Koné')
            ->assertJsonPath('justificatifs.0.a_fichier', true);
        $this->get("/api/v1/mon-espace/enseignant/justificatifs/{$id}/fichier")->assertOk();

        $this->postJson("/api/v1/mon-espace/enseignant/justificatifs/{$id}/decision", ['decision' => 'justifiee'])
            ->assertOk()->assertJsonPath('statut', 'justifiee');

        $this->assertTrue((bool) $this->absencesEconomat()->where('Code', $code)->value('Justifier'));
        $this->assertSame('Absence justifiée : Awa Koné', $this->notificationsDe(20)->orderByDesc('id')->value('titre'));

        // Déjà traité : une seconde décision est refusée.
        $this->postJson("/api/v1/mon-espace/enseignant/justificatifs/{$id}/decision", ['decision' => 'non_justifiee'])
            ->assertUnprocessable();
    }

    public function test_un_parent_ne_justifie_que_ses_enfants(): void
    {
        $this->absencesEconomat()->insert([
            'Code' => 60, 'Matricule' => 'EL2', 'CodeClasse' => 'CP1B', 'Heure' => 'Matin',
            'Date' => $this->aujourdhui, 'AnneeCour' => self::ANNEE, 'Justifier' => false,
        ]);
        $this->actingAs($this->parent(), 'sanctum');

        $this->postJson('/api/v1/mon-espace/parent/enfants/EL2/absences/60/justificatif', ['message' => 'x'])
            ->assertForbidden();
        // Absence d'un autre élève glissée sous le matricule de son propre enfant.
        $this->postJson('/api/v1/mon-espace/parent/enfants/EL1/absences/60/justificatif', ['message' => 'x'])
            ->assertNotFound();
        $this->getJson('/api/v1/mon-espace/parent/enfants/EL2/assiduite')->assertForbidden();
    }

    public function test_l_administration_decide_aussi(): void
    {
        $this->absencesEconomat()->insert([
            'Code' => 70, 'Matricule' => 'EL1', 'CodeClasse' => 'CP1A', 'Heure' => 'Matin',
            'Date' => $this->aujourdhui, 'AnneeCour' => self::ANNEE, 'Justifier' => false,
        ]);
        $this->actingAs($this->parent(), 'sanctum');
        $id = $this->postJson('/api/v1/mon-espace/parent/enfants/EL1/absences/70/justificatif', ['message' => 'Rendez-vous médical'])
            ->assertCreated()->json('id');

        $this->actingAs($this->compte(1, 'admin', null, [], true), 'sanctum');
        $this->getJson('/api/v1/justificatifs')->assertOk()->assertJsonCount(1, 'justificatifs');
        $this->postJson("/api/v1/justificatifs/{$id}/decision", ['decision' => 'non_justifiee', 'commentaire' => 'Merci de fournir un certificat.'])
            ->assertOk()->assertJsonPath('statut', 'non_justifiee');

        $this->assertFalse((bool) $this->absencesEconomat()->where('Code', 70)->value('Justifier'));
        $this->assertStringContainsString('certificat', $this->notificationsDe(20)->orderByDesc('id')->value('corps'));
    }
}
