<?php

namespace Tests\Feature\Console;

use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\Console\Societe;
use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Console modifiable : CRUD Sociétés / Établissements + règles d'affectation
 * (une seule société par utilisateur, anti-doublon). Identités lues dans RH_USER,
 * données Console écrites dans la base propre ecoprim (console_*).
 */
class ConsoleCrudTest extends TestCase
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
            '--database' => 'ecoprim',
            '--path' => 'database/migrations/console',
            '--realpath' => false,
            '--force' => true,
        ]);

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@ecole.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
    }

    public function test_un_code_absent_de_us_societe_est_refuse(): void
    {
        // ECOPRIM n'invente jamais de société : créer, c'est toujours reprendre un code
        // qui existe déjà dans US_SOCIETE.
        $this->postJson('/api/v1/societes', ['code' => 'INEXISTANT', 'nom' => 'Fantôme'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertDatabaseMissing('console_societes', ['code' => 'INEXISTANT'], 'ecoprim');
    }

    public function test_reprise_ne_touche_pas_us_societe(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            'CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nom officiel', 'NUMAUTO' => 7,
        ]);

        $this->postJson('/api/v1/societes', ['code' => 'ABN', 'nom' => 'Nom ECOPRIM'])
            ->assertCreated()
            ->assertJsonPath('code', 'ABN');

        // La ligne partagée est intacte : aucune écriture sur US_SOCIETE.
        $this->assertDatabaseHas('US_SOCIETE', ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nom officiel'], 'master');
        $this->assertSame(1, DB::connection('master')->table('US_SOCIETE')->where('CODESOCIETE', 'ABN')->count());
        // Le complément vit dans ECOPRIM.
        $this->assertDatabaseHas('console_societes', ['code' => 'ABN', 'nom' => 'Nom ECOPRIM'], 'ecoprim');
    }

    public function test_modifier_une_societe_ne_touche_pas_us_societe(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            'CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nom officiel', 'NUMAUTO' => 7,
        ]);
        $s = Societe::create(['code' => 'ABN', 'nom' => 'Nom ECOPRIM']);

        $this->putJson("/api/v1/societes/{$s->id}", ['code' => 'ABN', 'nom' => 'Nom corrigé'])->assertOk();

        $this->assertDatabaseHas('US_SOCIETE', ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nom officiel'], 'master');
        $this->assertDatabaseHas('console_societes', ['code' => 'ABN', 'nom' => 'Nom corrigé'], 'ecoprim');
    }

    public function test_code_trop_long_pour_us_societe_est_refuse(): void
    {
        // CODESOCIETE est un varchar(17) : un code plus long ne peut de toute façon pas
        // exister dans US_SOCIETE.
        $this->postJson('/api/v1/societes', ['code' => str_repeat('X', 18), 'nom' => 'Trop long'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_code_societe_unique(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert(['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nord', 'NUMAUTO' => 1]);
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);

        $this->postJson('/api/v1/societes', ['code' => 'ABN', 'nom' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_activer_desactiver_societe(): void
    {
        $s = Societe::create(['code' => 'ABN', 'nom' => 'Nord', 'actif' => true]);
        $this->postJson("/api/v1/societes/{$s->id}/desactiver")->assertOk();
        $this->assertFalse($s->fresh()->actif);
        $this->postJson("/api/v1/societes/{$s->id}/activer")->assertOk();
        $this->assertTrue($s->fresh()->actif);
    }

    /** Payload minimal valide : les colonnes NOT NULL de BEtablissements sont exigées. */
    private function etabValide(array $extra = []): array
    {
        return array_merge([
            'code' => 'E1', 'intitule' => 'École Alpha', 'adresse' => 'Cocody',
            'pays' => 'Côte d\'Ivoire', 'societe_code' => 'ABN',
        ], $extra);
    }

    public function test_creer_etablissement_l_enregistre_dans_betablissements(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);

        $this->postJson('/api/v1/etablissements', $this->etabValide())
            ->assertCreated()
            ->assertJsonPath('cree_dans_source', true);

        $this->assertDatabaseHas('BEtablissements', [
            'CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN',
        ], 'economat');
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E1', 'societe_code' => 'ABN'], 'ecoprim');
    }

    public function test_champs_not_null_de_betablissements_sont_exiges(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);

        $this->postJson('/api/v1/etablissements', ['code' => 'E1', 'intitule' => 'Alpha'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['adresse', 'pays', 'societe_code']);
    }

    public function test_liste_etablissements_affiche_betablissements_sans_surcouche(): void
    {
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E9', 'Intitule' => 'École Réelle', 'CodeSociete' => 'ABN',
            'Adresse1' => 'Yopougon', 'Pays' => 'CI', 'Ville' => 'Abidjan',
        ]);

        $this->getJson('/api/v1/etablissements')->assertOk()
            ->assertJsonFragment(['code' => 'E9', 'intitule' => 'École Réelle', 'source' => 'BEtablissements', 'repris' => false]);
    }

    public function test_fiche_etablissement_par_code(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Abidjan Nord']);
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E9', 'Intitule' => 'École Réelle', 'CodeSociete' => 'ABN',
            'Adresse1' => 'Yopougon', 'Pays' => 'CI', 'Ville' => 'Abidjan', 'Telephone' => '0700',
        ]);

        $this->getJson('/api/v1/etablissements/E9')->assertOk()
            ->assertJsonPath('code', 'E9')
            ->assertJsonPath('ville', 'Abidjan')
            ->assertJsonPath('societe.nom', 'Abidjan Nord');
    }

    public function test_modifier_etablissement_repercute_dans_betablissements(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        $this->postJson('/api/v1/etablissements', $this->etabValide())->assertCreated();
        $etab = Etablissement::where('code', 'E1')->firstOrFail();

        $this->putJson("/api/v1/etablissements/{$etab->id}", $this->etabValide(['intitule' => 'École Bêta']))
            ->assertOk();

        $this->assertDatabaseHas('BEtablissements', ['CodeEtablissement' => 'E1', 'Intitule' => 'École Bêta'], 'economat');
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E1', 'intitule' => 'École Bêta'], 'ecoprim');
    }

    public function test_desactivation_ne_supprime_rien_dans_betablissements(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        $this->postJson('/api/v1/etablissements', $this->etabValide())->assertCreated();
        $etab = Etablissement::where('code', 'E1')->firstOrFail();

        $this->postJson("/api/v1/etablissements/{$etab->id}/desactiver")->assertOk();

        $this->assertFalse($etab->fresh()->actif);
        // La ligne partagée existe toujours : aucune suppression.
        $this->assertDatabaseHas('BEtablissements', ['CodeEtablissement' => 'E1'], 'economat');
    }

    public function test_affectation_limitee_a_une_seule_societe(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Societe::create(['code' => 'SUD', 'nom' => 'Sud']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'A', 'societe_code' => 'ABN']);
        Etablissement::create(['code' => 'E2', 'intitule' => 'B', 'societe_code' => 'SUD']);
        $role = Role::create(['code' => 'ADMIN', 'nom' => 'Admin']);

        // 1re affectation dans la société ABN : OK
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();

        // Même utilisateur vers un établissement d'une autre société : refusé
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E2', 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('etablissement_code');
    }

    public function test_affectation_anti_doublon(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'A', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'ADMIN', 'nom' => 'Admin']);

        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertStatus(422)->assertJsonValidationErrors('role_id');
    }

    public function test_utilisateur_detail_liste_affectations(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'École Alpha', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'DIR', 'nom' => 'Direction']);
        $this->postJson('/api/v1/affectations', ['rh_user_id' => 1, 'etablissement_code' => 'E1', 'role_id' => $role->id])
            ->assertCreated();

        $this->getJson('/api/v1/utilisateurs/1')->assertOk()
            ->assertJsonPath('societe_code', 'ABN')
            ->assertJsonPath('affectations.0.etablissement.intitule', 'École Alpha')
            ->assertJsonPath('affectations.0.role.nom', 'Direction');
    }

    public function test_commande_import_peuple_les_tables(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Abidjan Nord', 'VILLESOCIETE' => 'Abidjan'],
        ]);
        DB::connection('economat')->table('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'Orpheline', 'CodeSociete' => 'ZZZ'],
        ]);

        Artisan::call('console:importer');

        $this->assertDatabaseHas('console_societes', ['code' => 'ABN', 'nom' => 'Abidjan Nord'], 'ecoprim');
        $this->assertDatabaseHas('console_etablissements', ['code' => 'E1', 'societe_code' => 'ABN'], 'ecoprim');
        // établissement rattaché à une société inconnue : ignoré (intégrité)
        $this->assertDatabaseMissing('console_etablissements', ['code' => 'E2'], 'ecoprim');
        $this->assertTrue(Role::count() >= 1);
    }

    public function test_import_societes_depuis_us_societe_sans_ecraser(): void
    {
        // Une société déjà présente dans ECOPRIM (éditée localement) + deux dans US_SOCIETE.
        Societe::create(['code' => 'ABN', 'nom' => 'Nom édité localement']);
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Abidjan Nord (source)', 'VILLESOCIETE' => null],
            ['CODESOCIETE' => 'SUD', 'NOMSOCIETE' => 'Sud SARL', 'VILLESOCIETE' => 'San Pedro'],
        ]);

        $this->postJson('/api/v1/societes/importer')->assertOk()
            ->assertJsonFragment(['importes' => 1, 'ignores' => 1]);

        // La société éditée localement n'est pas réécrite.
        $this->assertDatabaseHas('console_societes', ['code' => 'ABN', 'nom' => 'Nom édité localement'], 'ecoprim');
        // La nouvelle est importée.
        $this->assertDatabaseHas('console_societes', ['code' => 'SUD', 'nom' => 'Sud SARL'], 'ecoprim');
    }

    public function test_liste_affiche_us_societe_meme_sans_surcouche_ecoprim(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Abidjan Nord', 'VILLESOCIETE' => 'Abidjan'],
        ]);

        // Aucune société dans console_societes : la page doit tout de même les afficher.
        $this->getJson('/api/v1/societes')->assertOk()
            ->assertJsonFragment(['code' => 'ABN', 'nom' => 'Abidjan Nord', 'source' => 'US_SOCIETE', 'repris' => false]);
    }

    public function test_liste_fusionne_surcouche_ecoprim(): void
    {
        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'ABN', 'NOMSOCIETE' => 'Nom source', 'VILLESOCIETE' => 'Abidjan'],
        ]);
        Societe::create(['code' => 'ABN', 'nom' => 'Nom ECOPRIM', 'ville' => 'Bouaké']);

        // La surcouche ECOPRIM prend le dessus sur les valeurs de US_SOCIETE.
        $this->getJson('/api/v1/societes')->assertOk()
            ->assertJsonFragment(['code' => 'ABN', 'nom' => 'Nom ECOPRIM', 'ville' => 'Bouaké', 'repris' => true]);
    }

    public function test_creer_un_utilisateur_dans_rh_user(): void
    {
        $this->postJson('/api/v1/utilisateurs', [
            'login' => 'ndiaye', 'mot_de_passe' => 'secret123', 'nom' => 'Ndiaye', 'prenom' => 'Awa',
            'email' => 'awa@ecole.ci', 'profil' => 'Secretaire',
        ])->assertCreated()->assertJsonPath('login', 'ndiaye')->assertJsonPath('actif', true);

        $this->assertDatabaseHas('RH_USER', ['Login' => 'ndiaye', 'Nom' => 'Ndiaye'], 'master');

        // Mot de passe haché (bcrypt) et vérifiable par la connexion.
        $mdp = DB::connection('master')->table('RH_USER')->where('Login', 'ndiaye')->value('MotDePasse');
        $this->assertNotSame('secret123', $mdp);
        $this->assertTrue(Hash::check('secret123', $mdp));
    }

    public function test_login_utilisateur_doit_etre_unique(): void
    {
        $this->postJson('/api/v1/utilisateurs', ['login' => 'boss', 'mot_de_passe' => 'secret123', 'nom' => 'Doublon'])
            ->assertStatus(422)->assertJsonValidationErrors('login');
    }

    public function test_modifier_un_utilisateur_sans_changer_le_mot_de_passe(): void
    {
        $avant = DB::connection('master')->table('RH_USER')->where('Id', 1)->value('MotDePasse');

        $this->putJson('/api/v1/utilisateurs/1', ['login' => 'boss', 'nom' => 'Corrigé'])
            ->assertOk()->assertJsonPath('nom', 'Corrigé');

        $this->assertDatabaseHas('RH_USER', ['Id' => 1, 'Nom' => 'Corrigé'], 'master');
        // Mot de passe laissé vide : inchangé.
        $this->assertSame($avant, DB::connection('master')->table('RH_USER')->where('Id', 1)->value('MotDePasse'));
    }

    public function test_desactiver_un_utilisateur_ne_le_supprime_pas(): void
    {
        $this->postJson('/api/v1/utilisateurs/1/desactiver')->assertOk()->assertJsonPath('actif', false);

        // La ligne existe toujours dans la table partagée : marquée supprimée, jamais effacée.
        $this->assertDatabaseHas('RH_USER', ['Id' => 1, 'Supprimer' => 1], 'master');
        $this->assertSame(1, DB::connection('master')->table('RH_USER')->where('Id', 1)->count());

        $this->postJson('/api/v1/utilisateurs/1/activer')->assertOk()->assertJsonPath('actif', true);
    }
}
