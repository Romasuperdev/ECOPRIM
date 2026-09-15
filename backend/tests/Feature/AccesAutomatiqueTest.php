<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Accès créés automatiquement (App\Services\AccesAutomatique) :
 *  - à l'inscription d'un élève, le compte de son parent ;
 *  - à la création d'une fiche enseignant, le compte de l'enseignant.
 *
 * Trois règles à tenir : un seul compte parent par élève (père/tuteur d'abord), le
 * téléphone reconnaît la personne (un parent, plusieurs enfants, un seul compte), et le
 * mot de passe n'est renvoyé qu'à la création — jamais stocké en clair.
 */
class AccesAutomatiqueTest extends TestCase
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

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false, 'Etab' => 'E1',
        ]);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($rh, 'sanctum');

        DB::connection('ecoprim')->table('console_societes')
            ->insert(['id' => 1, 'code' => 'S1', 'nom' => 'Groupe', 'actif' => true]);
        DB::connection('ecoprim')->table('console_etablissements')
            ->insert(['id' => 1, 'code' => 'E1', 'intitule' => 'École', 'societe_code' => 'S1', 'actif' => true]);

        $eco = fn (string $t) => DB::connection('economat')->table($t);
        $eco('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 1, 'CodeAnnee' => '2025', 'LibelleAnnee' => self::ANNEE,
            'Activer' => true, 'ClotureDefinitive' => false, 'DEBUT' => '2025-09-01',
        ]);
        $eco('T_CLASSE')->insert([
            ['num' => 1, 'CodeClasse' => 'CP1A', 'LibelleClasse' => 'CP1 A', 'CodN' => 'CP1', 'ANNEE' => self::ANNEE],
        ]);
    }

    private function inscrire(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/inscriptions', array_merge([
            'mouvement' => 'inscription', 'matricule' => 'EL1', 'nom' => 'Koné', 'prenom' => 'Awa',
            'annee' => self::ANNEE, 'classe_code' => 'CP1A',
            'pere_nom' => 'KONÉ', 'pere_prenom' => 'Jean', 'pere_telephone' => '07 08 09 10 11',
        ], $extra));
    }

    // --- Parent ---

    public function test_inscrire_un_eleve_cree_le_compte_du_pere(): void
    {
        $r = $this->inscrire()->assertCreated();

        $r->assertJsonPath('acces_parent.nouveau', true)
            ->assertJsonPath('acces_parent.login', '0708091011')   // numéro normalisé
            ->assertJsonPath('acces_parent.role', 'Parent')
            ->assertJsonPath('acces_parent.enfant_rattache', true);

        $this->assertNotEmpty($r->json('acces_parent.mot_de_passe'));

        $this->assertDatabaseHas('RH_USER', ['Login' => '0708091011', 'Contact' => '0708091011'], 'master');
        $this->assertDatabaseHas('console_affectation_eleves', ['eleve_matricule' => 'EL1'], 'ecoprim');
    }

    public function test_le_mot_de_passe_n_est_jamais_stocke_en_clair(): void
    {
        $motDePasse = $this->inscrire()->json('acces_parent.mot_de_passe');

        $hache = DB::connection('master')->table('RH_USER')->where('Login', '0708091011')->value('MotDePasse');
        $this->assertNotSame($motDePasse, $hache);
        $this->assertTrue(Hash::check($motDePasse, $hache));
    }

    public function test_un_parent_avec_deux_enfants_garde_un_seul_compte(): void
    {
        $premier = $this->inscrire()->assertCreated();
        $second = $this->inscrire(['matricule' => 'EL2', 'prenom' => 'Ali'])->assertCreated();

        $this->assertSame($premier->json('acces_parent.login'), $second->json('acces_parent.login'));
        // Deuxième enfant : le compte existe déjà, donc aucun mot de passe régénéré.
        $second->assertJsonPath('acces_parent.nouveau', false)
            ->assertJsonPath('acces_parent.mot_de_passe', null)
            ->assertJsonPath('acces_parent.enfant_rattache', true);

        $this->assertSame(1, DB::connection('master')->table('RH_USER')->where('Login', '0708091011')->count());
        $this->assertSame(1, DB::connection('ecoprim')->table('console_affectations')->count());
        $this->assertSame(2, DB::connection('ecoprim')->table('console_affectation_eleves')->count());
    }

    public function test_le_meme_numero_ecrit_autrement_reste_le_meme_parent(): void
    {
        $this->inscrire(['pere_telephone' => '07 08 09 10 11'])->assertCreated();
        $second = $this->inscrire(['matricule' => 'EL2', 'pere_telephone' => '07-08-09-10-11'])->assertCreated();

        $second->assertJsonPath('acces_parent.nouveau', false);
        $this->assertSame(1, DB::connection('master')->table('RH_USER')->where('Login', '0708091011')->count());
    }

    public function test_la_mere_prend_le_relais_si_le_pere_n_a_pas_de_telephone(): void
    {
        $this->inscrire([
            'pere_telephone' => null,
            'mere_nom' => 'YAO', 'mere_prenom' => 'Akissi', 'mere_telephone' => '0102030405',
        ])->assertCreated()
            ->assertJsonPath('acces_parent.login', '0102030405')
            ->assertJsonPath('acces_parent.nouveau', true);
    }

    public function test_sans_telephone_aucun_compte_n_est_cree(): void
    {
        $this->inscrire(['pere_telephone' => null])->assertCreated()
            ->assertJsonPath('acces_parent', null);

        $this->assertSame(0, DB::connection('ecoprim')->table('console_affectations')->count());
    }

    public function test_un_numero_incomplet_n_ouvre_pas_de_compte(): void
    {
        $this->inscrire(['pere_telephone' => '0700'])->assertCreated()
            ->assertJsonPath('acces_parent', null);
    }

    public function test_le_parent_voit_son_enfant_depuis_son_portail(): void
    {
        $this->inscrire()->assertCreated();

        $parent = RhUser::on('master')->where('Login', '0708091011')->firstOrFail();

        $this->assertSame('parent', $parent->typePortail());
        $this->assertSame(['EL1'], $parent->enfantsMatricules());
    }

    // --- Enseignant ---

    public function test_creer_un_enseignant_cree_son_compte_et_relie_sa_fiche(): void
    {
        $r = $this->postJson('/api/v1/enseignants', [
            'matricule' => 'P7', 'nom' => 'Traoré', 'prenom' => 'Moussa',
            'cellulaire' => '05 06 07 08 09', 'annee_code' => self::ANNEE,
        ])->assertCreated();

        $r->assertJsonPath('acces_enseignant.nouveau', true)
            ->assertJsonPath('acces_enseignant.login', '0506070809')
            ->assertJsonPath('acces_enseignant.role', 'Enseignant');
        $this->assertNotEmpty($r->json('acces_enseignant.mot_de_passe'));

        // Le portail Enseignant relie la fiche au compte par T_PROFESSEUR.LOGIN : sans ce
        // lien, l'enseignant se connecterait sur un portail vide.
        $this->assertDatabaseHas('T_PROFESSEUR', ['MatriculeProfesseur' => 'P7', 'LOGIN' => '0506070809'], 'economat');

        $enseignant = RhUser::on('master')->where('Login', '0506070809')->firstOrFail();
        $this->assertSame('enseignant', $enseignant->typePortail());
    }

    public function test_un_enseignant_sans_numero_n_a_pas_de_compte(): void
    {
        $this->postJson('/api/v1/enseignants', [
            'matricule' => 'P8', 'nom' => 'Sans', 'prenom' => 'Numéro', 'annee_code' => self::ANNEE,
        ])->assertCreated()->assertJsonPath('acces_enseignant', null);
    }

    public function test_le_role_est_cree_s_il_manque_au_catalogue(): void
    {
        $this->assertSame(0, DB::connection('ecoprim')->table('console_roles')->count());

        $this->inscrire()->assertCreated();

        $this->assertDatabaseHas('console_roles', ['code' => 'parent', 'nom' => 'Parent'], 'ecoprim');
    }
}
