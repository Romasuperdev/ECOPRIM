<?php

namespace Tests\Feature\Console;

use App\Models\RhUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Nouveautés (notes de version) : le Super Admin publie, chaque compte lit et marque lu.
 *
 * Tenu ici : les droits, l'invisibilité d'une version inactive, le marquage lu idempotent
 * et propre à chaque compte, et la cascade — supprimer une version efface ses éléments ET
 * ses lectures, plutôt que de laisser des lignes orphelines.
 */
class NouveautesTest extends TestCase
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

    private function enUtilisateur(int $id = 2): void
    {
        $this->actingAs($this->compte($id, "simple{$id}", false), 'sanctum');
    }

    private function publier(string $numero, string $date, bool $active = true): int
    {
        $this->enSuperAdmin();

        return $this->postJson('/api/v1/console/nouveautes', [
            'numero' => $numero, 'titre' => "Version {$numero}", 'description' => 'Résumé',
            'date_sortie' => $date, 'active' => $active,
            'elements' => [
                ['titre' => 'Aide intégrée', 'type' => 'nouveaute'],
                ['titre' => 'Saisie plus rapide', 'type' => 'amelioration'],
                ['titre' => 'Bulletin corrigé', 'type' => 'correction', 'description' => 'Arrondi'],
            ],
        ])->assertCreated()->json('id');
    }

    // --- Droits -----------------------------------------------------------------------

    public function test_un_compte_non_admin_ne_peut_pas_gerer_mais_peut_lire(): void
    {
        $this->publier('1.0', '2026-10-01');
        $this->enUtilisateur();

        $this->getJson('/api/v1/console/nouveautes')->assertForbidden();
        $this->postJson('/api/v1/console/nouveautes', ['numero' => 'X'])->assertForbidden();

        $this->getJson('/api/v1/nouveautes')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.numero', '1.0')
            ->assertJsonCount(3, '0.elements')
            ->assertJsonPath('0.elements.2.type', 'correction');
    }

    public function test_un_type_d_element_inconnu_est_refuse(): void
    {
        $this->enSuperAdmin();

        $this->postJson('/api/v1/console/nouveautes', [
            'numero' => '1.0', 'titre' => 'V', 'date_sortie' => '2026-10-01',
            'elements' => [['titre' => 'X', 'type' => 'evolution']],
        ])->assertUnprocessable()->assertJsonValidationErrors('elements.0.type');
    }

    // --- Lecture ----------------------------------------------------------------------

    public function test_une_version_inactive_est_invisible_au_lecteur_mais_pas_en_gestion(): void
    {
        $this->publier('1.0', '2026-09-01');
        $brouillon = $this->publier('2.0', '2026-10-01', false);

        $this->getJson('/api/v1/console/nouveautes')->assertOk()->assertJsonCount(2);

        $this->enUtilisateur();
        $this->getJson('/api/v1/nouveautes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.numero', '1.0');
        $this->getJson('/api/v1/nouveautes/non-lues')->assertOk()->assertJsonCount(1);
        $this->postJson("/api/v1/nouveautes/{$brouillon}/lue")->assertNotFound();
        $this->postJson('/api/v1/nouveautes/999/lue')->assertNotFound();
    }

    public function test_les_non_lues_sont_triees_de_la_plus_recente_et_propres_a_chaque_compte(): void
    {
        $this->publier('1.0', '2026-09-01');
        $v2 = $this->publier('1.1', '2026-10-01');

        $this->enUtilisateur(2);
        $this->assertSame(['1.1', '1.0'],
            collect($this->getJson('/api/v1/nouveautes/non-lues')->assertOk()->json())->pluck('numero')->all());

        $this->postJson("/api/v1/nouveautes/{$v2}/lue")->assertOk()->assertJsonPath('version_id', $v2);
        $this->assertSame(['1.0'],
            collect($this->getJson('/api/v1/nouveautes/non-lues')->json())->pluck('numero')->all());

        // Un autre compte n'hérite pas de la lecture du premier.
        $this->enUtilisateur(3);
        $this->getJson('/api/v1/nouveautes/non-lues')->assertOk()->assertJsonCount(2);
    }

    public function test_marquer_lu_est_idempotent(): void
    {
        $v = $this->publier('1.0', '2026-10-01');
        $this->enUtilisateur();

        $premiere = $this->postJson("/api/v1/nouveautes/{$v}/lue")->assertOk()->json('lu_le');
        $this->travel(5)->minutes();
        $seconde = $this->postJson("/api/v1/nouveautes/{$v}/lue")->assertOk()->json('lu_le');

        // Pas de seconde ligne, et la date de première lecture ne bouge pas.
        $this->assertSame($premiere, $seconde);
        $this->assertSame(1, DB::connection('ecoprim')->table('nouveautes_lues')->count());
    }

    // --- Gestion ----------------------------------------------------------------------

    public function test_la_mise_a_jour_synchronise_les_elements(): void
    {
        $v = $this->publier('1.0', '2026-10-01');
        $elements = $this->getJson('/api/v1/console/nouveautes')->json('0.elements');

        $this->putJson("/api/v1/console/nouveautes/{$v}", [
            'numero' => '1.0.1', 'titre' => 'Correctif', 'date_sortie' => '2026-10-02', 'active' => true,
            'elements' => [
                // Le deuxième remonte en tête et change de titre ; le premier et le
                // troisième disparaissent ; un nouveau s'ajoute.
                ['id' => $elements[1]['id'], 'titre' => 'Saisie encore plus rapide', 'type' => 'amelioration'],
                ['titre' => 'Nouvel élément', 'type' => 'nouveaute'],
            ],
        ])->assertOk()
            ->assertJsonPath('numero', '1.0.1')
            ->assertJsonCount(2, 'elements')
            ->assertJsonPath('elements.0.id', $elements[1]['id'])
            ->assertJsonPath('elements.0.titre', 'Saisie encore plus rapide')
            ->assertJsonPath('elements.1.titre', 'Nouvel élément');

        $this->assertSame(2, DB::connection('ecoprim')->table('nouveautes_elements')->count());
    }

    public function test_supprimer_une_version_supprime_ses_elements_et_ses_lectures(): void
    {
        $v = $this->publier('1.0', '2026-10-01');
        $this->enUtilisateur();
        $this->postJson("/api/v1/nouveautes/{$v}/lue")->assertOk();

        $this->enSuperAdmin();
        $this->getJson('/api/v1/console/nouveautes')->assertOk()->assertJsonPath('0.lectures', 1);
        $this->deleteJson("/api/v1/console/nouveautes/{$v}")->assertNoContent();

        $db = DB::connection('ecoprim');
        $this->assertSame(0, $db->table('nouveautes_versions')->count());
        $this->assertSame(0, $db->table('nouveautes_elements')->count());
        $this->assertSame(0, $db->table('nouveautes_lues')->count());
    }
}
