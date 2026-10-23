<?php

namespace Tests\Feature\Console;

use App\Models\Console\Societe;
use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Écritures de la console sur la plateforme, comme la console parcauto :
 *   - Sociétés : création dans US_SOCIETE ET reprise dans ECOPRIM ; modification des deux ;
 *     « supprimer » = désactiver (réactivable), jamais effacer la ligne partagée ;
 *   - Applications : CRUD du catalogue, refus de supprimer une application utilisée ;
 *   - Société - Applications : synchronisation de la liste cochée, détachement, « Retirer
 *     tout », et lecture des anciennes lignes qui désignent la société par son NUMAUTO.
 * Toutes réservées au Super Admin.
 */
class ConsolePlateformeEcritureTest extends TestCase
{
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
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');

        $m = fn (string $t) => DB::connection('master')->table($t);
        $m('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'AURIAK', 'NOMSOCIETE' => 'AURIAK TECHNOLOGY SARL', 'NUMAUTO' => 1, 'id' => 'AAAA-1111'],
            ['CODESOCIETE' => 'CI', 'NOMSOCIETE' => 'IVOIRE NEGOCE SARL', 'NUMAUTO' => 27, 'id' => 'BBBB-2222'],
        ]);
        $m('US_APPLICATIONS')->insert([
            ['CodeApp' => '001', 'Intitule' => 'BACOU CAISSES'],
            ['CodeApp' => '002', 'Intitule' => 'NEXORA ÉCOLE'],
            ['CodeApp' => '003', 'Intitule' => 'PARC AUTO'],
        ]);
    }

    private function compte(int $id, string $login, bool $superAdmin, ?string $codeApp = null): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $login.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin, 'Supprimer' => false,
            'CodeApp' => $codeApp,
        ]);
    }

    private function appsDe(string $code): array
    {
        return DB::connection('master')->table('US_SOCIETE_APPLICATION')
            ->where('CODESOCIETE', $code)->orderBy('CodeApp')->pluck('CodeApp')->all();
    }

    // --- Droits -------------------------------------------------------------------------

    public function test_un_compte_non_super_admin_ne_peut_rien_ecrire(): void
    {
        $this->actingAs($this->compte(2, 'simple', false), 'sanctum');

        $this->postJson('/api/v1/plateforme/societes', ['code' => 'NEW', 'nom' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/plateforme/societes/CI', ['nom' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/plateforme/societes/CI/desactiver')->assertForbidden();
        $this->postJson('/api/v1/plateforme/applications', ['code' => '009', 'intitule' => 'X'])->assertForbidden();
        $this->deleteJson('/api/v1/plateforme/applications/003')->assertForbidden();
        $this->getJson('/api/v1/plateforme/societes-applications')->assertForbidden();
        $this->putJson('/api/v1/plateforme/societes-applications/CI', ['applications' => []])->assertForbidden();

        $this->assertSame(2, DB::connection('master')->table('US_SOCIETE')->count());
        $this->assertSame(3, DB::connection('master')->table('US_APPLICATIONS')->count());
    }

    // --- Sociétés -----------------------------------------------------------------------

    public function test_creer_une_societe_ecrit_us_societe_et_la_reprend_dans_ecoprim(): void
    {
        $this->postJson('/api/v1/plateforme/societes', [
            'code' => 'MG', 'nom' => 'MENAN GROUP', 'email' => 'contact@menan.ci',
            'codification' => true, 'capital' => 1000000,
        ])->assertCreated()->assertJsonPath('code', 'MG')->assertJsonPath('actif', true);

        $ligne = DB::connection('master')->table('US_SOCIETE')->where('CODESOCIETE', 'MG')->first();
        $this->assertSame('MENAN GROUP', $ligne->NOMSOCIETE);
        $this->assertSame('contact@menan.ci', $ligne->EMAILSOCIETE);
        $this->assertSame(28, (int) $ligne->NUMAUTO);  // suite du compteur
        $this->assertNotEmpty($ligne->id);
        $this->assertSame(1000000, (int) $ligne->CAPITAL);

        // Reprise automatique : le reste d'ECOPRIM la voit aussitôt.
        $this->assertDatabaseHas('console_societes', ['code' => 'MG', 'nom' => 'MENAN GROUP', 'actif' => true], 'ecoprim');
        $this->getJson('/api/v1/societes')->assertOk()->assertJsonFragment(['code' => 'MG', 'repris' => true]);
    }

    public function test_creer_une_societe_valide_les_entrees(): void
    {
        $this->postJson('/api/v1/plateforme/societes', ['code' => 'CI', 'nom' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/plateforme/societes', ['code' => str_repeat('X', 18), 'nom' => 'Trop long'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/plateforme/societes', ['code' => 'A/B', 'nom' => 'Barre'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/plateforme/societes', ['code' => 'OK', 'nom' => str_repeat('N', 51), 'email' => 'pas-un-email'])
            ->assertStatus(422)->assertJsonValidationErrors(['nom', 'email']);
        $this->postJson('/api/v1/plateforme/societes', ['code' => 'OK', 'nom' => 'Ok', 'capital' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('capital');

        $this->assertSame(2, DB::connection('master')->table('US_SOCIETE')->count());
        $this->assertSame(0, Societe::count());
    }

    public function test_modifier_une_societe_ecrit_us_societe_et_la_copie_ecoprim(): void
    {
        Societe::create(['code' => 'CI', 'nom' => 'Ancien nom', 'ville' => 'Abidjan']);

        $this->putJson('/api/v1/plateforme/societes/CI', [
            'nom' => 'IVOIRE NEGOCE SA', 'email' => 'info@ivoire.ci', 'codification' => false, 'capital' => null,
            'code' => 'PIRATE', // le code ne se modifie pas : ignoré
        ])->assertOk()->assertJsonPath('nom', 'IVOIRE NEGOCE SA');

        $this->assertDatabaseHas('US_SOCIETE', ['CODESOCIETE' => 'CI', 'NOMSOCIETE' => 'IVOIRE NEGOCE SA', 'EMAILSOCIETE' => 'info@ivoire.ci'], 'master');
        $this->assertDatabaseMissing('US_SOCIETE', ['CODESOCIETE' => 'PIRATE'], 'master');
        // La copie suit, ses compléments propres restent.
        $this->assertDatabaseHas('console_societes', ['code' => 'CI', 'nom' => 'IVOIRE NEGOCE SA', 'ville' => 'Abidjan'], 'ecoprim');

        $this->getJson('/api/v1/plateforme/societes/CI')->assertOk()
            ->assertJsonPath('email', 'info@ivoire.ci')->assertJsonPath('codification', false);
        $this->putJson('/api/v1/plateforme/societes/INCONNUE', ['nom' => 'X'])->assertNotFound();
    }

    public function test_supprimer_une_societe_la_desactive_sans_l_effacer_puis_la_reactive(): void
    {
        // Pas encore reprise dans ECOPRIM : la désactivation la reprend au passage.
        $this->postJson('/api/v1/plateforme/societes/CI/desactiver')->assertOk()->assertJsonPath('actif', false);

        $this->assertSame(1, DB::connection('master')->table('US_SOCIETE')->where('CODESOCIETE', 'CI')->count());
        $this->assertDatabaseHas('console_societes', ['code' => 'CI', 'actif' => false], 'ecoprim');
        $this->getJson('/api/v1/societes')->assertOk()->assertJsonFragment(['code' => 'CI', 'actif' => false]);

        $this->postJson('/api/v1/plateforme/societes/CI/activer')->assertOk()->assertJsonPath('actif', true);
        $this->assertDatabaseHas('console_societes', ['code' => 'CI', 'actif' => true], 'ecoprim');

        $this->postJson('/api/v1/plateforme/societes/INCONNUE/desactiver')->assertNotFound();
    }

    // --- Applications -------------------------------------------------------------------

    public function test_creer_et_modifier_une_application(): void
    {
        $this->postJson('/api/v1/plateforme/applications', ['code' => '004', 'intitule' => 'GESTION CIMETIÈRE'])
            ->assertCreated()->assertJsonPath('code', '004');
        $this->assertDatabaseHas('US_APPLICATIONS', ['CodeApp' => '004', 'Intitule' => 'GESTION CIMETIÈRE'], 'master');

        $this->putJson('/api/v1/plateforme/applications/004', ['intitule' => 'CIMETIÈRE'])
            ->assertOk()->assertJsonPath('libelle', 'CIMETIÈRE');
        $this->assertDatabaseHas('US_APPLICATIONS', ['CodeApp' => '004', 'Intitule' => 'CIMETIÈRE'], 'master');
    }

    public function test_une_application_valide_ses_entrees(): void
    {
        // La table n'a pas de contrainte d'unicité : le contrôle est fait par la console.
        $this->postJson('/api/v1/plateforme/applications', ['code' => '001', 'intitule' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/plateforme/applications', ['code' => '005', 'intitule' => str_repeat('I', 51)])
            ->assertStatus(422)->assertJsonValidationErrors('intitule');
        $this->postJson('/api/v1/plateforme/applications', ['code' => '', 'intitule' => 'Sans code'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame(1, DB::connection('master')->table('US_APPLICATIONS')->where('CodeApp', '001')->count());
    }

    public function test_une_application_utilisee_ne_se_supprime_pas(): void
    {
        DB::connection('master')->table('US_SOCIETE_APPLICATION')->insert(['CODESOCIETE' => 'CI', 'CodeApp' => '001']);
        $this->compte(5, 'caissier', false, '002');

        $this->deleteJson('/api/v1/plateforme/applications/001')
            ->assertStatus(422)->assertJsonValidationErrors('application');
        $this->deleteJson('/api/v1/plateforme/applications/002')
            ->assertStatus(422)->assertJsonValidationErrors('application');

        $this->assertDatabaseHas('US_APPLICATIONS', ['CodeApp' => '001'], 'master');
        $this->assertDatabaseHas('US_APPLICATIONS', ['CodeApp' => '002'], 'master');
    }

    public function test_une_application_inutilisee_se_supprime(): void
    {
        $this->deleteJson('/api/v1/plateforme/applications/003')->assertNoContent();
        $this->assertDatabaseMissing('US_APPLICATIONS', ['CodeApp' => '003'], 'master');

        $this->deleteJson('/api/v1/plateforme/applications/999')->assertNotFound();
    }

    // --- Société - Applications ---------------------------------------------------------

    public function test_synchroniser_les_applications_d_une_societe(): void
    {
        $this->putJson('/api/v1/plateforme/societes-applications/CI', ['applications' => ['001', '002']])
            ->assertOk()->assertJson(['ajoutees' => 2, 'retirees' => 0]);
        $this->assertSame(['001', '002'], $this->appsDe('CI'));
        // societe_id reprend US_SOCIETE.id.
        $this->assertDatabaseHas('US_SOCIETE_APPLICATION', ['CODESOCIETE' => 'CI', 'CodeApp' => '001', 'societe_id' => 'BBBB-2222'], 'master');

        // Décocher 001, cocher 003 : une retirée, une ajoutée, 002 intacte.
        $this->putJson('/api/v1/plateforme/societes-applications/CI', ['applications' => ['002', '003']])
            ->assertOk()->assertJson(['ajoutees' => 1, 'retirees' => 1]);
        $this->assertSame(['002', '003'], $this->appsDe('CI'));

        // Liste vide = plus aucune application.
        $this->putJson('/api/v1/plateforme/societes-applications/CI', ['applications' => []])->assertOk();
        $this->assertSame([], $this->appsDe('CI'));
    }

    public function test_synchroniser_refuse_une_application_ou_une_societe_inconnue(): void
    {
        $this->putJson('/api/v1/plateforme/societes-applications/CI', ['applications' => ['001', 'ZZZ']])
            ->assertStatus(422)->assertJsonValidationErrors('applications');
        $this->assertSame([], $this->appsDe('CI'));

        $this->putJson('/api/v1/plateforme/societes-applications/INCONNUE', ['applications' => ['001']])
            ->assertNotFound();
    }

    /** Une ancienne ligne désigne la société par son NUMAUTO ('1') : on la retrouve quand même. */
    public function test_la_lecture_retrouve_la_societe_par_code_numauto_ou_id(): void
    {
        DB::connection('master')->table('US_SOCIETE_APPLICATION')->insert([
            ['CODESOCIETE' => '1', 'CodeApp' => '001', 'societe_id' => null],          // NUMAUTO d'AURIAK
            ['CODESOCIETE' => 'XXX', 'CodeApp' => '002', 'societe_id' => 'bbbb-2222'], // id de CI
            ['CODESOCIETE' => 'CI', 'CodeApp' => '003', 'societe_id' => null],         // code
        ]);

        $reponse = $this->getJson('/api/v1/plateforme/societes-applications')->assertOk();
        $societes = collect($reponse->json('societes'))->keyBy('code');

        $this->assertSame(['001'], array_column($societes['AURIAK']['applications'], 'code'));
        $this->assertSame('BACOU CAISSES', $societes['AURIAK']['applications'][0]['libelle']);
        $this->assertSame(['002', '003'], array_column($societes['CI']['applications'], 'code'));
        $this->assertCount(3, $reponse->json('applications'));

        // La synchronisation tient compte de l'ancienne ligne : pas de doublon pour 001.
        $this->putJson('/api/v1/plateforme/societes-applications/AURIAK', ['applications' => ['001']])
            ->assertOk()->assertJson(['ajoutees' => 0, 'retirees' => 0]);
    }

    public function test_detacher_une_application_puis_retirer_tout(): void
    {
        DB::connection('master')->table('US_SOCIETE_APPLICATION')->insert([
            ['CODESOCIETE' => '1', 'CodeApp' => '001'],   // ancienne ligne, par NUMAUTO
            ['CODESOCIETE' => 'AURIAK', 'CodeApp' => '002'],
            ['CODESOCIETE' => 'AURIAK', 'CodeApp' => '003'],
            ['CODESOCIETE' => 'CI', 'CodeApp' => '001'],
        ]);

        $this->deleteJson('/api/v1/plateforme/societes-applications/AURIAK/001')->assertNoContent();
        $this->assertDatabaseMissing('US_SOCIETE_APPLICATION', ['CODESOCIETE' => '1'], 'master');
        $this->deleteJson('/api/v1/plateforme/societes-applications/AURIAK/001')->assertNotFound();

        $this->deleteJson('/api/v1/plateforme/societes-applications/AURIAK')->assertOk()->assertJson(['retirees' => 2]);
        $this->assertSame([], $this->appsDe('AURIAK'));
        // Les autres sociétés ne sont pas touchées, ni le catalogue.
        $this->assertSame(['001'], $this->appsDe('CI'));
        $this->assertSame(3, DB::connection('master')->table('US_APPLICATIONS')->count());
    }
}
