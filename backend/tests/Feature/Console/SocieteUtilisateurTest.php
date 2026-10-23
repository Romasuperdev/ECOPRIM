<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Société - Utilisateurs : la seule écriture de la console sur la plateforme.
 *
 * Ce qui compte : un rattachement porte RH_USER.Id (pas RH_USER.user_id), recopie le
 * NUMAUTO de la société, ne se duplique pas, et seul le Super Admin peut en poser.
 */
class SocieteUtilisateurTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();

        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173']);
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');

        DB::connection('master')->table('US_SOCIETE')->insert([
            ['CODESOCIETE' => 'AURIAK', 'NOMSOCIETE' => 'AURIAK TECHNOLOGY SARL', 'NUMAUTO' => 1],
            ['CODESOCIETE' => 'CI', 'NOMSOCIETE' => 'IVOIRE NEGOCE SARL', 'NUMAUTO' => 27],
        ]);
    }

    private function compte(int $id, string $login, bool $superAdmin, ?int $userId = null, bool $supprime = false): RhUser
    {
        return RhUser::on('master')->forceCreate([
            'Id' => $id, 'user_id' => $userId, 'Login' => $login, 'Nom' => 'N', 'Prenom' => 'P',
            'Email' => $login.'@e.ci', 'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin,
            'Supprimer' => $supprime, 'CodeApp' => '001',
        ]);
    }

    private function liaisons()
    {
        return DB::connection('master')->table('societe_utilisateur')->orderBy('societe_id')->get();
    }

    public function test_rattacher_un_compte_a_deux_societes(): void
    {
        $this->compte(2, 'mobio', false, userId: 1);

        $this->postJson('/api/v1/plateforme/affectations', [
            'utilisateur_id' => 2, 'societes' => ['AURIAK', 'CI'], 'admin_societe' => true,
        ])->assertCreated()->assertJson(['crees' => 2]);

        $lignes = $this->liaisons();
        $this->assertCount(2, $lignes);
        // RH_USER.Id (2), et non RH_USER.user_id (1).
        $this->assertSame([2, 2], $lignes->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1, 27], $lignes->pluck('societe_numauto')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue((bool) $lignes[0]->EstAdminSociete);
    }

    public function test_un_rattachement_existant_n_est_pas_duplique(): void
    {
        $this->compte(2, 'mobio', false);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['CI']])->assertCreated();

        $this->postJson('/api/v1/plateforme/affectations', [
            'utilisateur_id' => 2, 'societes' => ['CI'], 'admin_societe' => true,
        ])->assertCreated()->assertJson(['crees' => 0]);

        $this->assertCount(1, $this->liaisons());
        $this->assertTrue((bool) $this->liaisons()[0]->EstAdminSociete);
    }

    public function test_societe_inconnue_ou_compte_desactive_sont_refuses(): void
    {
        $this->compte(2, 'mobio', false);
        $this->compte(3, 'parti', false, supprime: true);

        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['XX']])
            ->assertStatus(422)->assertJsonValidationErrors('societes');
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 3, 'societes' => ['CI']])
            ->assertStatus(422)->assertJsonValidationErrors('utilisateur_id');

        $this->assertCount(0, $this->liaisons());
    }

    public function test_changer_le_statut_admin_puis_detacher(): void
    {
        $this->compte(2, 'mobio', false);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['CI']]);
        $id = (int) $this->liaisons()[0]->id;

        $this->putJson("/api/v1/plateforme/affectations/{$id}", ['admin_societe' => true])->assertNoContent();
        $this->assertTrue((bool) $this->liaisons()[0]->EstAdminSociete);

        $this->deleteJson("/api/v1/plateforme/affectations/{$id}")->assertNoContent();
        $this->assertCount(0, $this->liaisons());
        // Détacher retire l'accès, pas le compte.
        $this->assertTrue(DB::connection('master')->table('RH_USER')->where('Id', 2)->exists());

        $this->deleteJson("/api/v1/plateforme/affectations/{$id}")->assertNotFound();
    }

    public function test_referentiels_ne_proposent_que_les_comptes_actifs(): void
    {
        $this->compte(2, 'mobio', false);
        $this->compte(3, 'parti', false, supprime: true);

        $r = $this->getJson('/api/v1/plateforme/affectations/referentiels')->assertOk();

        $this->assertSame(['boss', 'mobio'], collect($r->json('utilisateurs'))->pluck('login')->all());
        $this->assertSame(['AURIAK', 'CI'], collect($r->json('societes'))->pluck('code')->all());
    }

    public function test_seul_le_super_admin_peut_rattacher(): void
    {
        $this->actingAs($this->compte(2, 'simple', false), 'sanctum');

        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['CI']])->assertStatus(403);
        $this->getJson('/api/v1/plateforme/affectations/referentiels')->assertStatus(403);
        $this->assertCount(0, $this->liaisons());
    }

    /** Le formulaire envoie la liste complète : décocher une société la détache. */
    public function test_synchroniser_fait_de_la_liste_la_liste_des_societes(): void
    {
        $this->compte(2, 'mobio', false, userId: 1);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['AURIAK']]);

        $this->putJson('/api/v1/plateforme/affectations/synchroniser', [
            'utilisateur_id' => 2, 'societes' => ['CI'], 'admins' => ['CI'],
        ])->assertOk()->assertJson(['crees' => 1, 'retires' => 1]);

        $lignes = $this->liaisons();
        $this->assertSame(['CI'], $lignes->pluck('societe_id')->all());
        $this->assertSame(2, (int) $lignes[0]->user_id);
        $this->assertSame(27, (int) $lignes[0]->societe_numauto);
        $this->assertTrue((bool) $lignes[0]->EstAdminSociete);

        // Même liste, admin retiré : la liaison est conservée, seul le statut change.
        $id = (int) $lignes[0]->id;
        $this->putJson('/api/v1/plateforme/affectations/synchroniser', [
            'utilisateur_id' => 2, 'societes' => ['CI', 'AURIAK'],
        ])->assertOk()->assertJson(['crees' => 1, 'retires' => 0]);

        $ci = $this->liaisons()->firstWhere('societe_id', 'CI');
        $this->assertSame($id, (int) $ci->id);
        $this->assertFalse((bool) $ci->EstAdminSociete);
    }

    public function test_synchroniser_une_liste_vide_retire_tout_meme_pour_un_compte_desactive(): void
    {
        $this->compte(3, 'parti', false, supprime: true);
        DB::connection('master')->table('societe_utilisateur')->insert([
            ['user_id' => 3, 'societe_id' => 'CI', 'EstAdminSociete' => false],
        ]);

        // Lui donner une société est refusé...
        $this->putJson('/api/v1/plateforme/affectations/synchroniser', ['utilisateur_id' => 3, 'societes' => ['AURIAK']])
            ->assertStatus(422)->assertJsonValidationErrors('utilisateur_id');
        // ... la lui retirer, non.
        $this->putJson('/api/v1/plateforme/affectations/synchroniser', ['utilisateur_id' => 3, 'societes' => []])
            ->assertOk()->assertJson(['crees' => 0, 'retires' => 1]);
        $this->assertCount(0, $this->liaisons());
    }

    public function test_synchroniser_refuse_une_societe_inconnue_sans_rien_toucher(): void
    {
        $this->compte(2, 'mobio', false);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['AURIAK']]);

        $this->putJson('/api/v1/plateforme/affectations/synchroniser', ['utilisateur_id' => 2, 'societes' => ['XX']])
            ->assertStatus(422)->assertJsonValidationErrors('societes');

        $this->assertSame(['AURIAK'], $this->liaisons()->pluck('societe_id')->all());
    }

    public function test_retirer_tout_ne_touche_que_le_compte_vise(): void
    {
        $this->compte(2, 'mobio', false);
        $this->compte(3, 'kone', false);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 2, 'societes' => ['AURIAK', 'CI']]);
        $this->postJson('/api/v1/plateforme/affectations', ['utilisateur_id' => 3, 'societes' => ['CI']]);

        $this->deleteJson('/api/v1/plateforme/affectations/utilisateurs/2')->assertOk()->assertJson(['retires' => 2]);

        $this->assertSame([3], $this->liaisons()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue(DB::connection('master')->table('RH_USER')->where('Id', 2)->exists());
    }

    public function test_synchroniser_et_retirer_tout_sont_reserves_au_super_admin(): void
    {
        $this->compte(3, 'kone', false);
        DB::connection('master')->table('societe_utilisateur')->insert([
            ['user_id' => 3, 'societe_id' => 'CI', 'EstAdminSociete' => true],
        ]);
        $this->actingAs($this->compte(2, 'simple', false), 'sanctum');

        $this->putJson('/api/v1/plateforme/affectations/synchroniser', ['utilisateur_id' => 3, 'societes' => []])->assertStatus(403);
        $this->deleteJson('/api/v1/plateforme/affectations/utilisateurs/3')->assertStatus(403);
        $this->assertCount(1, $this->liaisons());
    }

    /** Le périmètre d'un compte se lit par RH_USER.Id — l'ancienne jointure sur user_id se trompait de compte. */
    public function test_le_perimetre_d_un_compte_suit_son_id(): void
    {
        $mobio = $this->compte(2, 'mobio', false, userId: 1);
        DB::connection('master')->table('societe_utilisateur')->insert([
            ['user_id' => 1, 'societe_id' => 'CI', 'EstAdminSociete' => false],
            ['user_id' => 2, 'societe_id' => 'AURIAK', 'EstAdminSociete' => false],
        ]);

        $this->assertSame(['AURIAK'], $mobio->allowedSocieteIds());
    }
}
