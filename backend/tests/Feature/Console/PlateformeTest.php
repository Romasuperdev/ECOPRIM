<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Console plateforme : applications, affectations, abonnements, paramètres système.
 *
 * CES DONNÉES SONT PARTAGÉES. Elles vivent dans `dbmasterbacou`, la même base que RH_USER,
 * qu'ECONOMAT et les autres applications de la suite utilisent aussi. ECOPRIM n'en est pas
 * propriétaire : la console les LIT, et c'est tout. Ce que ces tests éprouvent d'abord,
 * c'est donc cela — aucune route d'écriture n'existe.
 *
 * Ils éprouvent ensuite ce que l'écran doit savoir dire : une table absente de
 * l'installation, une table présente mais vide, et un secret qu'on n'affiche pas.
 */
class PlateformeTest extends TestCase
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
            ['CODESOCIETE' => 'AURIAK', 'NOMSOCIETE' => 'AURIAK TECHNOLOGY SARL'],
            ['CODESOCIETE' => 'CI', 'NOMSOCIETE' => 'IVOIRE NEGOCE SARL'],
        ]);
        $m('US_APPLICATIONS')->insert([
            ['CodeApp' => '001', 'Intitule' => 'BACOU CAISSES'],
            ['CodeApp' => '002', 'Intitule' => 'NEXORA ÉCOLE'],
        ]);
        $m('societe_utilisateur')->insert([
            ['id' => 1, 'user_id' => 1, 'societe_id' => 'AURIAK', 'EstAdminSociete' => true],
            ['id' => 2, 'user_id' => 2, 'societe_id' => 'CI', 'EstAdminSociete' => false],
        ]);
        $m('parametres_systeme')->insert([
            ['id' => 1, 'cle' => 'sms_api_key', 'valeur' => 'secret-a-ne-pas-diffuser',
                'description' => 'Clé API SMS', 'updated_at' => '2026-09-28 11:06:47'],
            ['id' => 2, 'cle' => 'mail_from', 'valeur' => null,
                'description' => 'Adresse expéditrice', 'updated_at' => '2026-09-28 11:06:47'],
        ]);
    }

    private function compte(int $id, string $login, bool $superAdmin): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $login.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin, 'Supprimer' => false,
            'CodeApp' => '001',
        ]);
    }

    // --- Lecture ----------------------------------------------------------------------

    public function test_les_applications_sont_listees_avec_le_nombre_de_comptes(): void
    {
        $this->compte(2, 'autre', false);

        $apps = collect($this->getJson('/api/v1/plateforme/applications')->assertOk()->json('applications'))
            ->keyBy('code');

        $this->assertSame('BACOU CAISSES', $apps['001']['libelle']);
        // Les deux comptes du test portent CodeApp = 001.
        $this->assertSame(2, $apps['001']['comptes']);
        $this->assertSame(0, $apps['002']['comptes']);
    }

    /**
     * Un compte peut porter un code d'application absent du catalogue. Le taire le ferait
     * disparaître des totaux, et personne ne saurait qu'il existe.
     */
    public function test_un_code_d_application_hors_catalogue_est_signale(): void
    {
        DB::connection('master')->table('RH_USER')
            ->insert(['Id' => 9, 'Login' => 'x', 'CodeApp' => 'ZZZ', 'Supprimer' => false]);

        $this->assertSame(
            ['ZZZ'],
            $this->getJson('/api/v1/plateforme/applications')->assertOk()->json('codes_hors_catalogue'),
        );
    }

    public function test_les_affectations_nomment_la_societe_et_l_utilisateur(): void
    {
        $this->compte(2, 'autre', false);

        $lignes = collect($this->getJson('/api/v1/plateforme/affectations')->assertOk()->json('affectations'))
            ->keyBy('societe_code');

        $this->assertSame('AURIAK TECHNOLOGY SARL', $lignes['AURIAK']['societe_nom']);
        $this->assertSame('boss', $lignes['AURIAK']['login']);
        $this->assertTrue($lignes['AURIAK']['admin_societe']);
        $this->assertFalse($lignes['CI']['admin_societe']);
    }

    /**
     * Une console n'a pas à diffuser ce qu'elle protège : on dit que le réglage est posé,
     * on ne rend pas sa valeur.
     */
    public function test_la_valeur_d_un_parametre_n_est_jamais_rendue(): void
    {
        $reponse = $this->getJson('/api/v1/plateforme/parametres')->assertOk();

        $reponse->assertDontSee('secret-a-ne-pas-diffuser');

        $params = collect($reponse->json('parametres'))->keyBy('cle');
        $this->assertTrue($params['sms_api_key']['renseigne']);
        $this->assertFalse($params['mail_from']['renseigne']);
    }

    /**
     * Les tables d'abonnement existent mais sont vides. L'écran doit distinguer « rien
     * encore » d'une panne — sans quoi on cherche une erreur là où il n'y a rien.
     */
    public function test_une_table_vide_se_distingue_d_une_panne(): void
    {
        $reponse = $this->getJson('/api/v1/plateforme/abonnements')->assertOk();

        $this->assertTrue($reponse->json('disponible'));
        $this->assertFalse($reponse->json('alimentee'));
        $this->assertSame([], $reponse->json('formules'));
    }

    public function test_une_table_absente_de_l_installation_se_dit(): void
    {
        Schema::connection('master')->drop('US_APPLICATIONS');

        $reponse = $this->getJson('/api/v1/plateforme/applications')->assertOk();

        $this->assertFalse($reponse->json('disponible'));
        $this->assertSame([], $reponse->json('applications'));
    }

    // --- Périmètre --------------------------------------------------------------------

    /**
     * Ces données dépassent le périmètre d'une société : seul le Super Admin les voit.
     */
    public function test_un_compte_non_super_admin_est_refuse(): void
    {
        $this->actingAs($this->compte(3, 'simple', false), 'sanctum');

        foreach (['applications', 'affectations', 'abonnements', 'parametres'] as $route) {
            $this->getJson("/api/v1/plateforme/{$route}")->assertStatus(403);
        }
    }

    /**
     * La console LIT la plateforme, elle ne l'écrit pas : ces tables appartiennent à la
     * suite entière. Aucune route d'écriture ne doit exister — c'est la garantie, et elle
     * se vérifie sur la table de routage plutôt que sur une intention.
     */
    public function test_aucune_route_d_ecriture_sur_la_plateforme(): void
    {
        $ecritures = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'plateforme/'))
            ->reject(fn ($r) => $r->methods() === ['GET', 'HEAD'])
            ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->values()->all();

        $this->assertSame([], $ecritures);
    }
}
