<?php

namespace Tests\Feature;

use App\Models\RhUser;
use App\Services\Notifications\Notifieur;
use App\Services\Notifications\WebPush;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Notifications des espaces Enseignant et Parent : la cloche (portail_notifications) et
 * l'abonnement des téléphones au push (portail_abonnements_push).
 */
class PortailNotificationsTest extends TestCase
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
    }

    private function compte(int $id, string $role, array $enfants = []): RhUser
    {
        $rh = RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => "u{$id}", 'Nom' => 'N', 'Prenom' => 'P', 'Email' => "u{$id}@ecole.ci",
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => false, 'Supprimer' => false,
        ]);
        $eco = DB::connection('ecoprim');
        $roleId = $eco->table('console_roles')->where('code', $role)->value('id')
            ?? $eco->table('console_roles')->insertGetId(['code' => $role, 'nom' => ucfirst($role)]);
        $affectation = $eco->table('console_affectations')->insertGetId([
            'rh_user_id' => $id, 'societe_code' => 'S1', 'etablissement_code' => 'E1',
            'role_id' => $roleId, 'actif' => true,
        ]);
        foreach ($enfants as $m) {
            $eco->table('console_affectation_eleves')->insert(['affectation_id' => $affectation, 'eleve_matricule' => $m]);
        }

        return $rh;
    }

    private function notifieur(): Notifieur
    {
        return app(Notifieur::class);
    }

    public function test_chaque_compte_ne_voit_que_ses_notifications(): void
    {
        $parent = $this->compte(20, RhUser::ROLE_PARENT, ['EL1']);
        $autre = $this->compte(21, RhUser::ROLE_PARENT, ['EL2']);
        $this->notifieur()->envoyer([20], 'absence', 'Awa est absente', 'Lundi matin', '/mon-espace/enfants/EL1');
        $this->notifieur()->envoyer([21], 'absence', 'Kofi est absent');

        $this->actingAs($parent, 'sanctum');
        $r = $this->getJson('/api/v1/mon-espace/notifications')->assertOk()
            ->assertJsonPath('non_lues', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.titre', 'Awa est absente')
            ->assertJsonPath('notifications.0.lien', '/mon-espace/enfants/EL1')
            ->assertJsonPath('notifications.0.lue', false);

        // La notification de l'autre parent : introuvable, pas « déjà lue ».
        $idAutre = DB::connection('ecoprim')->table('portail_notifications')->where('rh_user_id', 21)->value('id');
        $this->postJson("/api/v1/mon-espace/notifications/{$idAutre}/lue")->assertNotFound();
        $this->assertNull(DB::connection('ecoprim')->table('portail_notifications')->where('id', $idAutre)->value('lue_le'));

        $this->postJson('/api/v1/mon-espace/notifications/'.$r->json('notifications.0.id').'/lue')
            ->assertOk()->assertJsonPath('non_lues', 0);
    }

    public function test_tout_marquer_lu_et_derniere_non_lue(): void
    {
        $enseignant = $this->compte(10, RhUser::ROLE_ENSEIGNANT);
        $this->notifieur()->envoyer([10], 'message', 'Premier');
        $this->travelTo(now()->addMinute());
        $this->notifieur()->envoyer([10], 'message', 'Second');

        $this->actingAs($enseignant, 'sanctum');
        $this->getJson('/api/v1/mon-espace/notifications/derniere')->assertOk()
            ->assertJsonPath('notification.titre', 'Second')
            ->assertJsonPath('non_lues', 2);

        $this->postJson('/api/v1/mon-espace/notifications/tout-lu')->assertOk()->assertJsonPath('non_lues', 0);
        $this->getJson('/api/v1/mon-espace/notifications/derniere')->assertOk()
            ->assertJsonPath('notification', null);
        $this->getJson('/api/v1/mon-espace/notifications/compteur')->assertOk()->assertJsonPath('non_lues', 0);
    }

    public function test_le_personnel_n_a_pas_de_cloche_de_portail(): void
    {
        $this->actingAs($this->compte(30, 'secretaire'), 'sanctum');

        $this->getJson('/api/v1/mon-espace/notifications')->assertForbidden();
    }

    public function test_les_parents_d_un_eleve_sont_retrouves(): void
    {
        $this->compte(20, RhUser::ROLE_PARENT, ['EL1', 'EL3']);
        $this->compte(21, RhUser::ROLE_PARENT, ['EL1']);
        $this->compte(22, RhUser::ROLE_PARENT, ['EL2']);
        // Affectation désactivée : ce parent n'est plus prévenu.
        $this->compte(23, RhUser::ROLE_PARENT, ['EL1']);
        DB::connection('ecoprim')->table('console_affectations')->where('rh_user_id', 23)->update(['actif' => false]);

        $ids = $this->notifieur()->parentsDesEleves(['EL1']);
        sort($ids);

        $this->assertSame([20, 21], $ids);
        $this->assertSame(2, $this->notifieur()->envoyer($ids, 'absence', 'Absence'));
        $this->assertSame([], $this->notifieur()->parentsDesEleves([]));
    }

    public function test_abonnement_push_d_un_appareil(): void
    {
        $parent = $this->compte(20, RhUser::ROLE_PARENT);
        $autre = $this->compte(21, RhUser::ROLE_PARENT);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

        $this->actingAs($parent, 'sanctum');
        $this->postJson('/api/v1/mon-espace/push/abonnement', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'cle', 'auth' => 'secret'],
        ])->assertCreated();
        // Le même appareil qui se réabonne ne crée pas de doublon.
        $this->postJson('/api/v1/mon-espace/push/abonnement', ['endpoint' => $endpoint])->assertCreated();
        $this->assertDatabaseCount('portail_abonnements_push', 1, 'ecoprim');

        // Un point d'arrivée non chiffré est refusé.
        $this->postJson('/api/v1/mon-espace/push/abonnement', ['endpoint' => 'http://exemple.ci/x'])
            ->assertUnprocessable();

        // Un autre compte ne peut pas désabonner cet appareil…
        $this->actingAs($autre, 'sanctum');
        $this->deleteJson('/api/v1/mon-espace/push/abonnement', ['endpoint' => $endpoint])->assertOk();
        $this->assertDatabaseCount('portail_abonnements_push', 1, 'ecoprim');

        // …mais son propriétaire, si.
        $this->actingAs($parent, 'sanctum');
        $this->deleteJson('/api/v1/mon-espace/push/abonnement', ['endpoint' => $endpoint])->assertOk();
        $this->assertDatabaseCount('portail_abonnements_push', 0, 'ecoprim');
    }

    public function test_le_reveil_supprime_les_abonnements_expires(): void
    {
        $cles = app(WebPush::class)->generer();
        config(['nexora.push.cle_publique' => $cles['publique'], 'nexora.push.cle_privee' => base64_encode($cles['privee'])]);
        $this->app->forgetInstance(WebPush::class);

        $table = DB::connection('ecoprim')->table('portail_abonnements_push');
        foreach (['https://push.exemple/actif', 'https://push.exemple/expire'] as $endpoint) {
            $table->insert(['rh_user_id' => 20, 'endpoint' => $endpoint, 'endpoint_empreinte' => hash('sha256', $endpoint)]);
        }

        Http::fake([
            'push.exemple/actif' => Http::response('', 201),
            'push.exemple/expire' => Http::response('', 410),
        ]);

        app(Notifieur::class)->reveiller([20]);

        $this->assertSame(['https://push.exemple/actif'], $table->pluck('endpoint')->all());
        $this->assertNotNull($table->value('dernier_envoi_le'));
        Http::assertSent(fn ($req) => str_starts_with($req->header('Authorization')[0] ?? '', 'vapid t=')
            && $req->header('TTL')[0] === '86400' && $req->body() === '');
    }

    public function test_le_jeton_vapid_est_un_es256_verifiable(): void
    {
        $push = app(WebPush::class);
        $cles = $push->generer();
        [$entete, $charge, $signature] = explode('.', $push->jeton('https://fcm.googleapis.com', $cles['privee']));

        $b64 = fn ($s) => base64_decode(strtr($s, '-_', '+/'));
        $this->assertSame('ES256', json_decode($b64($entete), true)['alg']);
        $this->assertSame('https://fcm.googleapis.com', json_decode($b64($charge), true)['aud']);

        // R||S (64 octets) reconverti en DER : la signature doit se vérifier avec la clé publique.
        $brut = $b64($signature);
        $this->assertSame(64, strlen($brut));
        $entier = function ($octets) {
            $octets = ltrim($octets, "\0");

            return ord($octets[0]) > 127 ? "\0".$octets : $octets;
        };
        [$r, $s] = [$entier(substr($brut, 0, 32)), $entier(substr($brut, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $publique = openssl_pkey_get_details(openssl_pkey_get_private($cles['privee']))['key'];

        $this->assertSame(1, openssl_verify("{$entete}.{$charge}", $der, $publique, OPENSSL_ALGO_SHA256));
        // La clé publique transmise au navigateur : point non compressé, 65 octets.
        $this->assertSame(65, strlen($b64($cles['publique'])));
    }
}
