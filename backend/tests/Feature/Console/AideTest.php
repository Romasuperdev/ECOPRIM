<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Aide (documentation interne) : le Super Admin gère, tout compte connecté lit.
 *
 * Ce que ces tests tiennent surtout, ce sont les deux défauts de l'appli dont l'idée est
 * reprise : un article désactivé doit rester VISIBLE en gestion (sinon on ne peut plus le
 * réactiver) et INVISIBLE en lecture — y compris par son adresse directe.
 */
class AideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();

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

    private function compte(int $id, string $login, bool $superAdmin): RhUser
    {
        return RhUser::on('master')->find($id) ?? RhUser::on('master')->forceCreate([
            'Id' => $id, 'Login' => $login, 'Nom' => 'N', 'Prenom' => 'P', 'Email' => $login.'@e.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => $superAdmin, 'Supprimer' => false,
            'CodeApp' => '001',
        ]);
    }

    private function enSuperAdmin(): void
    {
        $this->actingAs($this->compte(1, 'boss', true), 'sanctum');
    }

    private function enUtilisateur(): void
    {
        $this->actingAs($this->compte(2, 'simple', false), 'sanctum');
    }

    /** Une catégorie et trois articles, dont un désactivé. Retourne les identifiants. */
    private function contenu(): array
    {
        $this->enSuperAdmin();
        $cat = $this->postJson('/api/v1/console/aide/categories', ['titre' => 'Démarrer', 'icone' => 'BookOpen'])
            ->assertCreated()->json('id');
        $a1 = $this->postJson('/api/v1/console/aide/articles', [
            'categorie_id' => $cat, 'titre' => 'Se connecter', 'contenu' => "Ligne 1\nLigne 2", 'ordre' => 1,
        ])->assertCreated()->json('id');
        $a2 = $this->postJson('/api/v1/console/aide/articles', [
            'categorie_id' => $cat, 'titre' => 'Choisir son établissement', 'contenu' => 'Menu du haut.',
            'module' => 'Paramètre', 'ordre' => 2,
        ])->assertCreated()->json('id');
        $off = $this->postJson('/api/v1/console/aide/articles', [
            'categorie_id' => $cat, 'titre' => 'Ancienne procédure', 'contenu' => 'Obsolète.', 'active' => false, 'ordre' => 3,
        ])->assertCreated()->json('id');

        return compact('cat', 'a1', 'a2', 'off');
    }

    // --- Droits -----------------------------------------------------------------------

    public function test_un_compte_non_admin_ne_peut_pas_gerer(): void
    {
        $this->enUtilisateur();

        $this->getJson('/api/v1/console/aide')->assertForbidden();
        $this->postJson('/api/v1/console/aide/categories', ['titre' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/console/aide/articles', ['titre' => 'X'])->assertForbidden();
    }

    public function test_un_compte_non_admin_peut_lire(): void
    {
        $ids = $this->contenu();
        $this->enUtilisateur();

        $this->getJson('/api/v1/aide')->assertOk()->assertJsonPath('0.titre', 'Démarrer');
        $this->getJson("/api/v1/aide/articles/{$ids['a1']}")->assertOk()
            ->assertJsonPath('contenu', "Ligne 1\nLigne 2");
    }

    public function test_la_lecture_exige_d_etre_connecte(): void
    {
        $this->getJson('/api/v1/aide')->assertUnauthorized();
    }

    // --- Actif / inactif --------------------------------------------------------------

    public function test_un_article_inactif_est_invisible_au_lecteur(): void
    {
        $ids = $this->contenu();
        $this->enUtilisateur();

        $titres = collect($this->getJson('/api/v1/aide')->assertOk()->json('0.articles'))->pluck('titre')->all();
        $this->assertSame(['Se connecter', 'Choisir son établissement'], $titres);

        $this->getJson("/api/v1/aide/articles/{$ids['off']}")->assertNotFound();
        $this->getJson('/api/v1/aide/recherche?q=Obsol')->assertOk()->assertJsonCount(0);
    }

    public function test_un_article_inactif_reste_visible_en_gestion_et_se_reactive(): void
    {
        $ids = $this->contenu();

        $articles = collect($this->getJson('/api/v1/console/aide')->assertOk()->json('0.articles'))->keyBy('id');
        $this->assertCount(3, $articles);
        $this->assertFalse($articles[$ids['off']]['active']);

        $this->putJson("/api/v1/console/aide/articles/{$ids['off']}", [
            'categorie_id' => $ids['cat'], 'titre' => 'Ancienne procédure', 'contenu' => 'Obsolète.', 'active' => true,
        ])->assertOk()->assertJsonPath('active', true);

        $this->getJson("/api/v1/aide/articles/{$ids['off']}")->assertOk();
    }

    public function test_une_categorie_inactive_cache_ses_articles_au_lecteur(): void
    {
        $ids = $this->contenu();
        $this->putJson("/api/v1/console/aide/categories/{$ids['cat']}", ['titre' => 'Démarrer', 'active' => false])
            ->assertOk()->assertJsonPath('active', false);

        $this->getJson('/api/v1/console/aide')->assertOk()->assertJsonCount(1);

        $this->enUtilisateur();
        $this->getJson('/api/v1/aide')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/v1/aide/articles/{$ids['a1']}")->assertNotFound();
    }

    public function test_une_categorie_se_modifie(): void
    {
        $ids = $this->contenu();

        $this->putJson("/api/v1/console/aide/categories/{$ids['cat']}", ['titre' => 'Premiers pas', 'icone' => 'Rocket', 'ordre' => 4])
            ->assertOk()
            ->assertJsonPath('titre', 'Premiers pas')
            ->assertJsonPath('icone', 'Rocket')
            ->assertJsonPath('ordre', 4);
    }

    // --- Lecture ----------------------------------------------------------------------

    public function test_la_recherche_porte_sur_le_titre_et_le_contenu(): void
    {
        $this->contenu();
        $this->enUtilisateur();

        $this->getJson('/api/v1/aide/recherche?q=connecter')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.titre', 'Se connecter')
            ->assertJsonPath('0.categorie.titre', 'Démarrer');
        $this->getJson('/api/v1/aide/recherche?q=Menu du')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.titre', 'Choisir son établissement');
        $this->getJson('/api/v1/aide/recherche?q=x')->assertUnprocessable();
    }

    public function test_le_detail_donne_les_voisins_actifs_de_la_categorie(): void
    {
        $ids = $this->contenu();
        $this->enUtilisateur();

        $this->getJson("/api/v1/aide/articles/{$ids['a1']}")->assertOk()
            ->assertJsonPath('precedent', null)
            ->assertJsonPath('suivant.id', $ids['a2']);
        // L'article désactivé, rangé après, n'est pas proposé comme « suivant ».
        $this->getJson("/api/v1/aide/articles/{$ids['a2']}")->assertOk()
            ->assertJsonPath('precedent.id', $ids['a1'])
            ->assertJsonPath('suivant', null);
    }

    // --- Suppression ------------------------------------------------------------------

    public function test_supprimer_une_categorie_supprime_ses_articles(): void
    {
        $ids = $this->contenu();

        $this->deleteJson("/api/v1/console/aide/categories/{$ids['cat']}")->assertNoContent();

        $this->assertSame(0, DB::connection('ecoprim')->table('aide_categories')->count());
        $this->assertSame(0, DB::connection('ecoprim')->table('aide_articles')->count());
    }

    public function test_un_article_se_supprime_seul(): void
    {
        $ids = $this->contenu();

        $this->deleteJson("/api/v1/console/aide/articles/{$ids['a1']}")->assertNoContent();

        $this->assertSame(2, DB::connection('ecoprim')->table('aide_articles')->count());
    }

    public function test_un_article_exige_une_categorie_existante(): void
    {
        $this->enSuperAdmin();

        $this->postJson('/api/v1/console/aide/articles', ['categorie_id' => 999, 'titre' => 'X', 'contenu' => 'Y'])
            ->assertUnprocessable()->assertJsonValidationErrors('categorie_id');
    }
}
