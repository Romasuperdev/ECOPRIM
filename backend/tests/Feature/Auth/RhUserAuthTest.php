<?php

namespace Tests\Feature\Auth;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Authentification 100 % lecture seule contre dbmasterbacou (RH_USER + rôles).
 * Aucun compte local n'est créé : identité, rôles et périmètre sont lus dans dbmasterbacou.
 */
class RhUserAuthTest extends TestCase
{
    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        // Base console isolée : la connexion lit désormais les rôles console du compte
        // (RhUser::typePortail) et refuse s'ils sont illisibles. Sans cela, ce test lisait
        // la vraie base `ecoprim`, ou échouait là où elle n'est pas joignable.
        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        Artisan::call('migrate', [
            '--database' => 'ecoprim', '--path' => 'database/migrations/console',
            '--realpath' => false, '--force' => true,
        ]);
    }

    private function creerRh(array $a = []): array
    {
        $a = array_merge([
            'Id' => 1, 'Login' => 'jdupont', 'Nom' => 'Dupont', 'Prenom' => 'Jean',
            'Email' => 'jean@ecole.ci', 'Matricule' => 'MAT1',
            'MotDePasse' => Hash::make('secret'),
            'SuperAdmin' => false, 'Supprimer' => false, 'user_id' => null,
            // Un compte sans établissement ne se connecte pas.
            'Etab' => 'E1',
        ], $a);
        DB::connection('master')->table('RH_USER')->insert($a);

        return $a;
    }

    private function login(string $identifiant, string $password = 'secret')
    {
        return $this->withHeaders($this->spa)
            ->postJson('/api/v1/login', ['email' => $identifiant, 'password' => $password]);
    }

    public function test_login_valide_renvoie_identite_sans_ecrire(): void
    {
        $this->creerRh(['Email' => 'admin@ecole.ci']);

        $this->login('admin@ecole.ci')
            ->assertOk()
            ->assertJsonPath('email', 'admin@ecole.ci')
            ->assertJsonPath('name', 'Jean Dupont')
            ->assertJsonPath('roles', []);
    }

    /** Un compte qui se connecte après un autre n'hérite pas de son contexte de travail. */
    public function test_la_connexion_oublie_le_contexte_du_compte_precedent(): void
    {
        $this->creerRh(['Email' => 'admin@ecole.ci']);

        $this->withSession([
            'etablissement_code' => 'E1', 'etablissement_nom' => 'École Alpha',
            'console_societe' => 'ABN', 'annee_travail' => '2024-2025',
        ]);
        $this->login('admin@ecole.ci')->assertOk();

        $this->assertNull(session('etablissement_code'));
        $this->assertNull(session('etablissement_nom'));
        $this->assertNull(session('console_societe'));
        $this->assertNull(session('annee_travail'));
    }

    public function test_bit_super_admin_donne_le_role(): void
    {
        $this->creerRh(['Email' => 'boss@ecole.ci', 'SuperAdmin' => true]);

        $this->login('boss@ecole.ci')
            ->assertOk()
            ->assertJsonFragment(['roles' => ['Super Admin']]);
    }

    public function test_roles_derives_de_role_user(): void
    {
        $this->creerRh(['Email' => 'dir@ecole.ci', 'user_id' => 10]);
        DB::connection('master')->table('users')->insert(['id' => 10, 'name' => 'Jean', 'email' => 'dir@ecole.ci', 'password' => 'x']);
        DB::connection('master')->table('roles')->insert(['id' => 5, 'code' => 'DIR', 'name' => 'Direction']);
        DB::connection('master')->table('role_user')->insert(['id' => 1, 'user_id' => 10, 'role_id' => 5]);

        $this->login('dir@ecole.ci')
            ->assertOk()
            ->assertJsonFragment(['roles' => ['Direction']]);
    }

    public function test_connexion_par_login_et_matricule(): void
    {
        $this->creerRh(['Login' => 'prof9', 'Matricule' => 'M9', 'Email' => 'p9@ecole.ci']);

        $this->login('prof9')->assertOk()->assertJsonPath('email', 'p9@ecole.ci');
        $this->login('M9')->assertOk()->assertJsonPath('email', 'p9@ecole.ci');
    }

    public function test_connexion_par_nom_d_utilisateur_ou_email_quelle_que_soit_la_casse(): void
    {
        $this->creerRh(['Login' => 'JDupont', 'Email' => 'Jean.Dupont@Ecole.ci']);

        // Le nom d'utilisateur, tel qu'enregistré puis en minuscules.
        $this->login('JDupont')->assertOk()->assertJsonPath('name', 'Jean Dupont');
        $this->login('jdupont')->assertOk()->assertJsonPath('name', 'Jean Dupont');

        // L'email, y compris recopié avec une casse ou des espaces différents.
        $this->login('Jean.Dupont@Ecole.ci')->assertOk()->assertJsonPath('name', 'Jean Dupont');
        $this->login('jean.dupont@ecole.ci')->assertOk()->assertJsonPath('name', 'Jean Dupont');
        $this->login('  jean.dupont@ecole.ci  ')->assertOk()->assertJsonPath('name', 'Jean Dupont');
    }

    public function test_le_nom_de_famille_n_est_pas_un_identifiant(): void
    {
        // Nom n'est pas unique dans RH_USER : l'accepter ferait entrer un homonyme
        // sur le compte d'un autre.
        $this->creerRh(['Id' => 1, 'Nom' => 'Kone', 'Login' => 'kone1', 'Email' => 'k1@ecole.ci', 'Matricule' => 'K1']);
        $this->creerRh(['Id' => 2, 'Nom' => 'Kone', 'Login' => 'kone2', 'Email' => 'k2@ecole.ci', 'Matricule' => 'K2']);

        $this->login('Kone')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_l_etablissement_se_trouve_aussi_par_le_nom_d_utilisateur(): void
    {
        $this->creerRh(['Login' => 'MmeTraore', 'Etab' => 'ETAB1']);
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'ETAB1', 'Intitule' => 'Groupe Scolaire Les Palmiers',
        ]);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/etablissement-du-compte', ['identifiant' => 'mmetraore'])
            ->assertOk()
            ->assertJsonPath('etablissement', 'Groupe Scolaire Les Palmiers');
    }

    public function test_mot_de_passe_incorrect(): void
    {
        $this->creerRh(['Email' => 'a@ecole.ci']);
        $this->login('a@ecole.ci', 'faux')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_compte_supprime_refuse(): void
    {
        $this->creerRh(['Email' => 'vire@ecole.ci', 'Supprimer' => true]);
        $this->login('vire@ecole.ci')->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_restriction_code_app(): void
    {
        config(['ecoprim.code_app' => 'ECOPRIM']);
        $this->creerRh(['Id' => 1, 'Email' => 'autre@ecole.ci', 'CodeApp' => 'AUTRE']);
        $this->login('autre@ecole.ci')->assertStatus(422);

        $this->creerRh(['Id' => 2, 'Login' => 'ok', 'Email' => 'ok@ecole.ci', 'CodeApp' => 'ECOPRIM']);
        $this->login('ok@ecole.ci')->assertOk();
    }

    public function test_me_renvoie_identite(): void
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 7, 'Login' => 'x', 'Nom' => 'Koffi', 'Prenom' => 'Ama', 'Email' => 'ama@ecole.ci',
            'MotDePasse' => Hash::make('secret'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('email', 'ama@ecole.ci')
            ->assertJsonFragment(['roles' => ['Super Admin']]);
    }

    // --- Établissement affiché sur la page de connexion ---

    private function etablissementDe(string $identifiant)
    {
        return $this->withHeaders($this->spa)
            ->postJson('/api/v1/etablissement-du-compte', ['identifiant' => $identifiant]);
    }

    public function test_l_etablissement_du_compte_est_renvoye_pour_la_page_de_connexion(): void
    {
        $this->creerRh(['Etab' => 'E1']);
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN',
        ]);

        // Reconnu par le login, l'email ou le matricule.
        foreach (['jdupont', 'jean@ecole.ci', 'MAT1'] as $identifiant) {
            $this->etablissementDe($identifiant)->assertOk()
                ->assertJsonPath('etablissement', 'École Alpha');
        }
    }

    public function test_a_defaut_d_intitule_le_code_est_renvoye(): void
    {
        $this->creerRh(['Etab' => 'INCONNU']);

        $this->etablissementDe('jdupont')->assertOk()
            ->assertJsonPath('etablissement', 'INCONNU');
    }

    public function test_aucune_information_pour_un_identifiant_inconnu_desactive_ou_sans_etablissement(): void
    {
        // Identifiant inconnu
        $this->etablissementDe('personne')->assertOk()->assertJsonPath('etablissement', null);

        // Compte sans établissement
        $this->creerRh(['Id' => 2, 'Login' => 'sansetab', 'Email' => 's@e.ci', 'Matricule' => 'MAT2', 'Etab' => null]);
        $this->etablissementDe('sansetab')->assertOk()->assertJsonPath('etablissement', null);

        // Compte désactivé, pourtant rattaché : même réponse, pas de fuite.
        $this->creerRh(['Id' => 3, 'Login' => 'parti', 'Email' => 'p@e.ci', 'Matricule' => 'MAT3', 'Etab' => 'E1', 'Supprimer' => true]);
        DB::connection('economat')->table('BEtablissements')->insert([
            'CodeEtablissement' => 'E1', 'Intitule' => 'École Alpha', 'CodeSociete' => 'ABN',
        ]);
        $this->etablissementDe('parti')->assertOk()->assertJsonPath('etablissement', null);
    }

    public function test_la_reponse_ne_contient_que_les_rattachements(): void
    {
        $this->creerRh(['Etab' => 'E1']);

        $corps = $this->etablissementDe('jdupont')->assertOk()->json();

        // Aucune donnée personnelle ne doit transiter par cet endpoint public : ni le nom
        // du titulaire, ni son rôle, ni la confirmation qu'il existe.
        $this->assertSame(['etablissement', 'societe'], array_keys($corps));
    }

    /**
     * Un Super Admin n'est rattaché à aucune société en particulier — et l'endpoint,
     * public, ne le dit pas : « Super Administrateur » désignait à quiconque tapait un
     * identifiant le compte qui ouvre toute la suite. Même réponse qu'un compte sans
     * rattachement.
     */
    public function test_le_super_admin_affiche_tous_les_etablissements(): void
    {
        $this->creerRh(['Login' => 'boss', 'SuperAdmin' => true, 'Etab' => '']);

        $corps = $this->etablissementDe('boss')->assertOk()
            ->assertJsonPath('etablissement', '(Tous les établissements)')
            ->assertJsonPath('societe', null)
            ->json();

        // Rien ne dit « Super Admin » en clair.
        $this->assertStringNotContainsString('Super', json_encode($corps, JSON_UNESCAPED_UNICODE));
    }

    public function test_un_compte_sans_etablissement_ne_peut_pas_se_connecter(): void
    {
        $this->creerRh(['Etab' => null]);

        $r = $this->login('jdupont')->assertStatus(403);
        $this->assertStringContainsString('aucun établissement', $r->json('message'));
        $this->assertGuest();
    }

    public function test_une_affectation_console_suffit_pour_se_connecter(): void
    {
        $this->creerRh(['Etab' => null]);
        $roleId = DB::connection('ecoprim')->table('console_roles')->insertGetId(['code' => 'secretaire', 'nom' => 'Secrétaire']);
        DB::connection('ecoprim')->table('console_affectations')->insert([
            'rh_user_id' => 1, 'societe_code' => 'ABN', 'etablissement_code' => 'E1', 'role_id' => $roleId, 'actif' => true,
        ]);

        $this->login('jdupont')->assertOk();
    }

    public function test_une_affectation_inactive_ne_suffit_pas(): void
    {
        $this->creerRh(['Etab' => null]);
        $roleId = DB::connection('ecoprim')->table('console_roles')->insertGetId(['code' => 'secretaire', 'nom' => 'Secrétaire']);
        DB::connection('ecoprim')->table('console_affectations')->insert([
            'rh_user_id' => 1, 'societe_code' => 'ABN', 'etablissement_code' => 'E1', 'role_id' => $roleId, 'actif' => false,
        ]);

        $this->login('jdupont')->assertStatus(403);
    }

    public function test_le_super_admin_se_connecte_sans_etablissement(): void
    {
        $this->creerRh(['SuperAdmin' => true, 'Etab' => null]);

        $this->login('jdupont')->assertOk();
    }

    public function test_un_compte_dont_on_retire_l_etablissement_perd_sa_session(): void
    {
        $this->creerRh();
        $this->login('jdupont')->assertOk();

        DB::connection('master')->table('RH_USER')->where('Id', 1)->update(['Etab' => null]);

        $this->withHeaders($this->spa)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_l_identifiant_est_obligatoire(): void
    {
        $this->etablissementDe('')->assertStatus(422)->assertJsonValidationErrors('identifiant');
    }

    // --- Limitation des tentatives ---

    /**
     * Un compte RH_USER ouvre toute la suite : /login ne doit pas se prêter à l'essai de
     * mots de passe en rafale. Cinq essais par minute pour un même identifiant depuis une
     * même adresse, puis 429 avec un message en français.
     */
    public function test_la_sixieme_tentative_de_connexion_est_refusee(): void
    {
        $this->creerRh(['Email' => 'cible@ecole.ci']);

        for ($i = 1; $i <= 5; $i++) {
            $this->login('cible@ecole.ci', 'mauvais'.$i)->assertStatus(422);
        }

        $r = $this->login('cible@ecole.ci', 'secret')->assertStatus(429);
        $this->assertMatchesRegularExpression('/^Trop de tentatives\. Réessayez dans \d+ secondes\.$/u', $r->json('message'));

        // Le même identifiant, autrement écrit, partage le compteur…
        $this->login('  CIBLE@ecole.ci ', 'secret')->assertStatus(429);

        // … mais un autre compte n'est pas bloqué par les essais faits sur celui-ci.
        $this->creerRh(['Id' => 2, 'Login' => 'autre', 'Email' => 'autre@ecole.ci', 'Matricule' => 'MAT2']);
        $this->login('autre@ecole.ci')->assertOk();
    }

    public function test_la_page_de_connexion_est_limitee_elle_aussi(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->etablissementDe('quelquun')->assertOk();
        }

        $this->etablissementDe('quelquun')->assertStatus(429)
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Trop de tentatives.'));
    }

    // --- Rôles console illisibles ---

    /**
     * « Aucune affectation » donne l'application complète (comptes historiques). Si une
     * lecture impossible valait « aucune affectation », un parent obtenait l'application
     * complète dès que la base console ne répondait pas.
     */
    public function test_roles_illisibles_la_connexion_est_refusee(): void
    {
        $this->creerRh(['Email' => 'parent@ecole.ci']);
        Schema::connection('ecoprim')->drop('console_affectations');

        $this->login('parent@ecole.ci')->assertStatus(503);
        $this->assertGuest('web');
    }

    public function test_roles_illisibles_aucune_route_n_est_ouverte(): void
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => 8, 'Login' => 'p', 'Nom' => 'Yao', 'Prenom' => 'Ama', 'Email' => 'p@ecole.ci',
            'MotDePasse' => Hash::make('secret'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
        Schema::connection('ecoprim')->drop('console_affectations');

        $this->assertSame(RhUser::PORTAIL_INDISPONIBLE, $rh->typePortail());

        $this->withHeaders($this->spa)->getJson('/api/v1/eleves')->assertForbidden();
        $this->withHeaders($this->spa)->getJson('/api/v1/mon-espace/parent/enfants')->assertForbidden();
    }
}
