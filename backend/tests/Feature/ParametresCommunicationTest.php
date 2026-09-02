<?php

namespace Tests\Feature;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Paramètres NEXORA adossés à ECONOMAT (documents élèves, passerelle SMS, SMTP)
 * et transmission d'informations par SMS.
 */
class ParametresCommunicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'boss', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'b@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        // Requêtes « stateful » : les paramètres dépendent de l'établissement en session.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
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
}
