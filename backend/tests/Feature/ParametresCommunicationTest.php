<?php

namespace Tests\Feature;

use App\Models\RhUser;
use App\Support\PerimetreEtablissement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Paramètres NEXORA adossés à ECONOMAT (documents élèves, passerelle SMS, SMTP)
 * et transmission d'informations par SMS.
 *
 * Les paramètres d'envoi sont réservés aux administrateurs de l'établissement VISÉ (choisi
 * en session) ; l'envoi et l'historique demandent `envoyer_communications`.
 */
class ParametresCommunicationTest extends TestCase
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

        DB::connection('economat')->table('BEtablissements')->insert([
            ['CodeEtablissement' => 'E1', 'Intitule' => 'École 1', 'CodeSociete' => 'S1'],
            ['CodeEtablissement' => 'E2', 'Intitule' => 'École 2', 'CodeSociete' => 'S2'],
        ]);

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        // Requêtes « stateful » : les paramètres dépendent de l'établissement en session.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->withSession(['etablissement_code' => 'E1']);
        $this->actingAs($rh, 'sanctum');
    }

    /** Un compte du personnel, affecté à un établissement par un rôle portant ces permissions. */
    private function compte(int $id, string $role, array $permissions = [], string $etab = 'E1'): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $role.$id, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $role.$id.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false, 'Etab' => $etab,
        ]);

        $eco = fn (string $t) => DB::connection('ecoprim')->table($t);
        $roleId = $eco('console_roles')->where('code', $role)->value('id')
            ?? $eco('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
        foreach ($permissions as $code) {
            $eco('console_role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_code' => $code]);
        }
        $eco('console_affectations')->insert([
            'rh_user_id' => $id, 'societe_code' => $etab === 'E1' ? 'S1' : 'S2', 'etablissement_code' => $etab,
            'role_id' => $roleId, 'actif' => true,
        ]);

        return $rh;
    }

    /** Change de compte connecté (et oublie le périmètre mémorisé du précédent). */
    private function connecter(RhUser $rh, ?string $etab = 'E1'): void
    {
        PerimetreEtablissement::oublier();
        $this->withSession(['etablissement_code' => $etab]);
        $this->actingAs($rh, 'sanctum');
    }

    // --- Documents élèves (T_PREREQUIS) ---

    public function test_creer_et_lister_un_document_eleve(): void
    {
        $this->postJson('/api/v1/parametres/prerequis', [
            'libelle' => 'Extrait de naissance', 'annee' => '2025-2026',
            'niveau' => 'CP1', 'type' => 'DOCUMENT', 'montant' => 0, 'a_inscription' => true,
        ])->assertCreated()
            ->assertJsonPath('libelle', 'Extrait de naissance')
            ->assertJsonPath('a_inscription', true);

        $this->assertDatabaseHas('T_PREREQUIS', ['LIBELLE' => 'Extrait de naissance', 'ANNEE' => '2025-2026', 'INSCR' => 1], 'economat');

        $this->getJson('/api/v1/parametres/prerequis?annee=2025-2026')->assertOk()
            ->assertJsonPath('data.0.libelle', 'Extrait de naissance');
    }

    public function test_libelle_et_annee_du_document_sont_obligatoires(): void
    {
        $this->postJson('/api/v1/parametres/prerequis', ['type' => 'DOCUMENT'])
            ->assertStatus(422)->assertJsonValidationErrors(['libelle', 'annee']);
    }

    public function test_modifier_un_document_eleve(): void
    {
        $r = $this->postJson('/api/v1/parametres/prerequis', ['libelle' => 'Photo', 'annee' => '2025-2026'])->assertCreated();
        $id = $r->json('id');

        $this->putJson("/api/v1/parametres/prerequis/{$id}", [
            'libelle' => 'Photo d’identité', 'annee' => '2025-2026', 'a_scolarite' => true,
        ])->assertOk()->assertJsonPath('libelle', 'Photo d’identité');

        $this->assertDatabaseHas('T_PREREQUIS', ['CODES' => $id, 'LIBELLE' => 'Photo d’identité', 'SCO' => 1], 'economat');
    }

    // --- Passerelle SMS (ECO_SMS_CONFIG) ---

    public function test_config_sms_vide_puis_enregistrement(): void
    {
        $this->getJson('/api/v1/parametres/sms')->assertOk()->assertJsonPath('id', null);

        $this->postJson('/api/v1/parametres/sms', [
            'environnement' => 'PROD', 'actif' => true, 'fournisseur' => 'Orange',
            'expediteur' => 'NEXORA', 'api_key' => 'cle-secrete',
        ])->assertOk()->assertJsonPath('actif', true)->assertJsonPath('fournisseur', 'Orange');

        $this->assertDatabaseHas('ECO_SMS_CONFIG', ['PROVIDER' => 'Orange', 'ENABLED' => 1], 'economat');
    }

    public function test_la_cle_api_sms_n_est_jamais_renvoyee_en_clair(): void
    {
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'api_key' => 'cle-secrete'])->assertOk();

        $r = $this->getJson('/api/v1/parametres/sms')->assertOk();
        $this->assertNotSame('cle-secrete', $r->json('api_key'));
        $this->assertTrue($r->json('api_key_definie'));
    }

    public function test_enregistrer_sans_cle_conserve_la_cle_existante(): void
    {
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'api_key' => 'cle-secrete'])->assertOk();

        // Deuxième enregistrement sans clé : elle ne doit pas être écrasée par du vide.
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'fournisseur' => 'Twilio'])->assertOk();

        $this->assertSame('cle-secrete', DB::connection('economat')->table('ECO_SMS_CONFIG')->value('API_KEY'));
    }

    // --- Messagerie SMTP (T_MAIL_DIFFUSION) ---

    public function test_config_mail_enregistrement_sans_exposer_le_mot_de_passe(): void
    {
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ecole.ci',
            'port_smtp' => 587, 'mot_de_passe' => 'motdepasse',
        ])->assertOk()->assertJsonPath('adresse', 'direction@ecole.ci');

        $r = $this->getJson('/api/v1/parametres/mail')->assertOk();
        $this->assertTrue($r->json('mot_de_passe_defini'));
        $this->assertArrayNotHasKey('mot_de_passe', $r->json());

        $this->assertDatabaseHas('T_MAIL_DIFFUSION', ['ADRESS_MAIL' => 'direction@ecole.ci', 'PORT_SMTP' => 587], 'economat');
    }

    // --- Communication ---

    public function test_envoi_sms_refuse_si_la_passerelle_est_inactive(): void
    {
        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'sms', 'destinataires' => ['0700000000'], 'message' => 'Test',
        ])->assertStatus(422);

        $this->assertDatabaseCount('T_SMS', 0, 'economat');
    }

    public function test_envoi_sms_depose_un_message_par_destinataire(): void
    {
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'actif' => true])->assertOk();

        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'sms',
            'destinataires' => ['0700000000', '0501020304'],
            'message' => 'Réunion des parents samedi à 9h.',
            'type' => 'INFO',
        ])->assertCreated()->assertJsonPath('envoyes', 2);

        $this->assertDatabaseCount('T_SMS', 2, 'economat');
        $this->assertDatabaseHas('T_SMS', [
            'Numero' => '0700000000', 'Message' => 'Réunion des parents samedi à 9h.', 'Users' => 'boss',
        ], 'economat');
    }

    public function test_historique_des_envois(): void
    {
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'actif' => true])->assertOk();
        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'sms', 'destinataires' => ['0700000000'], 'message' => 'Bulletin disponible',
        ])->assertCreated();

        $this->getJson('/api/v1/communication/historique')->assertOk()
            ->assertJsonPath('data.0.numero', '0700000000')
            ->assertJsonPath('data.0.message', 'Bulletin disponible');

        $this->getJson('/api/v1/communication/historique?q=introuvable')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_le_message_sms_est_borne_a_250_caracteres(): void
    {
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'actif' => true])->assertOk();

        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'sms', 'destinataires' => ['0700000000'], 'message' => str_repeat('a', 251),
        ])->assertStatus(422)->assertJsonValidationErrors('message');
    }

    // --- Sécurité des paramètres d'envoi ---

    public function test_un_membre_du_personnel_non_administrateur_ne_lit_ni_ne_modifie_les_parametres(): void
    {
        $this->connecter($this->compte(2, 'secretaire', ['modifier_parametres_ecole', 'envoyer_communications']));

        $this->getJson('/api/v1/parametres/mail')->assertForbidden();
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'pirate@exemple.com', 'serveur_smtp' => 'smtp.pirate.com', 'port_smtp' => 587,
        ])->assertForbidden();
        $this->getJson('/api/v1/parametres/sms')->assertForbidden();
        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'api_url' => 'https://pirate.com'])
            ->assertForbidden();

        $this->assertDatabaseCount('T_MAIL_DIFFUSION', 0, 'economat');
        $this->assertDatabaseCount('ECO_SMS_CONFIG', 0, 'economat');
    }

    public function test_l_admin_d_un_etablissement_regle_le_sien_mais_pas_celui_d_un_autre(): void
    {
        $admin = $this->compte(3, 'admin-etablissement', [], 'E1');

        $this->connecter($admin, 'E1');
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole1.ci', 'serveur_smtp' => 'smtp.ecole1.ci', 'port_smtp' => 587, 'mot_de_passe' => 'x',
        ])->assertOk()->assertJsonPath('etablissement_code', 'E1');

        $this->connecter($admin, 'E2');
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole2.ci', 'serveur_smtp' => 'smtp.ecole2.ci', 'port_smtp' => 587, 'mot_de_passe' => 'x',
        ])->assertForbidden();

        $this->assertDatabaseMissing('T_MAIL_DIFFUSION', ['code_etab' => 'E2'], 'economat');
    }

    public function test_sans_etablissement_choisi_les_parametres_sont_refuses(): void
    {
        $this->withSession(['etablissement_code' => null]);

        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ecole.ci', 'port_smtp' => 587,
        ])->assertStatus(422)->assertJsonValidationErrors('etablissement');
    }

    /** L'ancien `->when($etab, …)` visait « la dernière config » de n'importe quelle école. */
    public function test_enregistrer_ne_touche_jamais_la_configuration_d_un_autre_etablissement(): void
    {
        DB::connection('economat')->table('T_MAIL_DIFFUSION')->insert([
            'ID_MAIL_DIF' => 7, 'ADRESS_MAIL' => 'e2@ecole2.ci', 'SERVEUR_SMTP' => 'smtp.ecole2.ci',
            'PORT_SMTP' => 587, 'MOT_PASS' => 'secret-e2', 'code_etab' => 'E2',
        ]);

        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'e1@ecole1.ci', 'serveur_smtp' => 'smtp.ecole1.ci', 'port_smtp' => 587, 'mot_de_passe' => 'p1',
        ])->assertOk()->assertJsonPath('etablissement_code', 'E1');

        $this->assertDatabaseHas('T_MAIL_DIFFUSION', [
            'ID_MAIL_DIF' => 7, 'SERVEUR_SMTP' => 'smtp.ecole2.ci', 'MOT_PASS' => 'secret-e2', 'code_etab' => 'E2',
        ], 'economat');
        $this->assertDatabaseCount('T_MAIL_DIFFUSION', 2, 'economat');
    }

    public function test_changer_de_serveur_smtp_sans_ressaisir_le_mot_de_passe_est_refuse(): void
    {
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ecole.ci', 'port_smtp' => 587, 'mot_de_passe' => 'secret',
        ])->assertOk();

        // Le mot de passe conservé partirait vers le nouveau serveur.
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ailleurs.com', 'port_smtp' => 587,
        ])->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');

        // Même chose en changeant seulement de port.
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ecole.ci', 'port_smtp' => 465,
        ])->assertStatus(422)->assertJsonValidationErrors('mot_de_passe');

        $this->assertDatabaseHas('T_MAIL_DIFFUSION', ['SERVEUR_SMTP' => 'smtp.ecole.ci', 'PORT_SMTP' => 587], 'economat');

        // Même serveur, même port, sans mot de passe : conservé. Nouveau serveur + mot de passe : admis.
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'secretariat@ecole.ci', 'serveur_smtp' => 'SMTP.ecole.ci', 'port_smtp' => 587,
        ])->assertOk();
        $this->postJson('/api/v1/parametres/mail', [
            'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.nouveau.ci', 'port_smtp' => 465, 'mot_de_passe' => 'neuf',
        ])->assertOk();
        $this->assertDatabaseHas('T_MAIL_DIFFUSION', ['SERVEUR_SMTP' => 'smtp.nouveau.ci', 'MOT_PASS' => 'neuf'], 'economat');
    }

    public function test_un_port_smtp_hors_liste_ou_un_hote_douteux_est_refuse(): void
    {
        foreach ([22, 80, 3306, 6379, 8080] as $port) {
            $this->postJson('/api/v1/parametres/mail', [
                'adresse' => 'direction@ecole.ci', 'serveur_smtp' => 'smtp.ecole.ci', 'port_smtp' => $port, 'mot_de_passe' => 'x',
            ])->assertStatus(422)->assertJsonValidationErrors('port_smtp');
        }

        foreach (['smtp://smtp.ecole.ci', 'smtp.ecole.ci:25', 'smtp.ecole.ci/x', 'a b'] as $hote) {
            $this->postJson('/api/v1/parametres/mail', [
                'adresse' => 'direction@ecole.ci', 'serveur_smtp' => $hote, 'port_smtp' => 587, 'mot_de_passe' => 'x',
            ])->assertStatus(422)->assertJsonValidationErrors('serveur_smtp');
        }

        $this->assertDatabaseCount('T_MAIL_DIFFUSION', 0, 'economat');
    }

    public function test_changer_l_adresse_de_la_passerelle_sms_sans_ressaisir_la_cle_est_refuse(): void
    {
        $this->postJson('/api/v1/parametres/sms', [
            'environnement' => 'PROD', 'api_url' => 'https://api.operateur.ci/sms', 'api_key' => 'cle-secrete',
        ])->assertOk();

        $this->postJson('/api/v1/parametres/sms', [
            'environnement' => 'PROD', 'api_url' => 'https://pirate.example.com/collecte',
        ])->assertStatus(422)->assertJsonValidationErrors('api_key');

        $this->assertDatabaseHas('ECO_SMS_CONFIG', ['API_URL' => 'https://api.operateur.ci/sms'], 'economat');

        $this->postJson('/api/v1/parametres/sms', ['environnement' => 'PROD', 'api_url' => 'ftp://x.ci'])
            ->assertStatus(422)->assertJsonValidationErrors('api_url');
    }

    public function test_un_envoi_avec_une_ancienne_config_sur_un_port_interdit_n_envoie_rien(): void
    {
        Mail::fake();
        DB::connection('economat')->table('T_MAIL_DIFFUSION')->insert([
            'ID_MAIL_DIF' => 1, 'ADRESS_MAIL' => 'e1@ecole1.ci', 'SERVEUR_SMTP' => '10.0.0.5',
            'PORT_SMTP' => 6379, 'MOT_PASS' => 'secret', 'code_etab' => 'E1',
        ]);

        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'mail', 'destinataires' => ['parent@exemple.com'], 'message' => 'Test',
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    // --- Communication : permission, débit, périmètre ---

    public function test_envoyer_et_lire_l_historique_demandent_la_permission(): void
    {
        $this->connecter($this->compte(4, 'enseignant-titulaire', ['saisir_notes']));

        $this->postJson('/api/v1/communication/envoyer', [
            'canal' => 'sms', 'destinataires' => ['0700000000'], 'message' => 'Test',
        ])->assertForbidden();
        $this->getJson('/api/v1/communication/historique')->assertForbidden();
    }

    public function test_l_envoi_est_limite_en_debit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/communication/envoyer', [])->assertStatus(422);
        }

        $this->postJson('/api/v1/communication/envoyer', [])->assertStatus(429);
    }

    public function test_l_historique_ne_montre_que_les_envois_du_perimetre(): void
    {
        $agent = $this->compte(5, 'secretaire', ['envoyer_communications'], 'E1');
        $this->compte(6, 'secretaire', ['envoyer_communications'], 'E2');

        DB::connection('economat')->table('T_SMS')->insert([
            ['id' => 1, 'Date' => '2026-10-01', 'Numero' => '0701', 'Message' => 'Ecole 1', 'Users' => 'secretaire5'],
            ['id' => 2, 'Date' => '2026-10-02', 'Numero' => '0702', 'Message' => 'Ecole 2', 'Users' => 'secretaire6'],
            ['id' => 3, 'Date' => '2026-10-03', 'Numero' => '0703', 'Message' => 'Hors NEXORA', 'Users' => 'inconnu'],
        ]);

        $this->connecter($agent, 'E1');
        $numeros = collect($this->getJson('/api/v1/communication/historique')->assertOk()->json('data'))
            ->pluck('numero')->all();

        $this->assertSame(['0701'], $numeros);

        // Sans établissement choisi, un compte du personnel ne lit que ses propres envois.
        $this->connecter($agent, null);
        $numeros = collect($this->getJson('/api/v1/communication/historique')->assertOk()->json('data'))
            ->pluck('numero')->all();
        $this->assertSame(['0701'], $numeros);
    }

    public function test_le_super_admin_en_vue_generale_voit_tout_l_historique(): void
    {
        DB::connection('economat')->table('T_SMS')->insert([
            ['id' => 1, 'Date' => '2026-10-01', 'Numero' => '0701', 'Message' => 'A', 'Users' => 'x'],
            ['id' => 2, 'Date' => '2026-10-02', 'Numero' => '0702', 'Message' => 'B', 'Users' => 'y'],
        ]);
        $this->withSession(['etablissement_code' => null]);

        $this->getJson('/api/v1/communication/historique')->assertOk()->assertJsonCount(2, 'data');
    }
}
