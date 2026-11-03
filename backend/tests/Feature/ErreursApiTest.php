<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Aucune erreur technique ne doit atteindre l'écran (voir App\Support\ErreursApi).
 *
 * Cas d'origine : en ligne, ECONOMAT n'avait pas T_NIVEAU, et l'écran « Barèmes et
 * coefficients » affichait la requête SQL complète à l'utilisateur.
 */
class ErreursApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('_essai/table-absente', fn () => DB::table('T_TABLE_ABSENTE')->get());
            Route::get('_essai/plantage', fn () => throw new \LogicException('C:\\wamp64\\www\\secret.php ligne 12'));
            Route::get('_essai/validation', fn () => throw ValidationException::withMessages(['nom' => 'Le nom est obligatoire.']));
        });
    }

    public function test_une_table_absente_donne_un_message_lisible_sans_sql(): void
    {
        config(['app.debug' => false]);

        $reponse = $this->getJson('/api/v1/_essai/table-absente');

        $reponse->assertStatus(500)
            ->assertJsonPath('code', 'STRUCTURE_BASE')
            ->assertJsonStructure(['message', 'code', 'reference'])
            ->assertJsonMissingPath('detail')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->assertStringNotContainsString('SQLSTATE', $reponse->getContent());
        $this->assertStringNotContainsString('T_TABLE_ABSENTE', $reponse->getContent());
        $this->assertStringContainsString($reponse->json('reference'), $reponse->json('message'));
    }

    public function test_une_erreur_inattendue_ne_montre_ni_chemin_ni_trace(): void
    {
        config(['app.debug' => false]);

        $reponse = $this->getJson('/api/v1/_essai/plantage');

        $reponse->assertStatus(500)->assertJsonPath('code', 'ERREUR_SERVEUR');
        $this->assertStringNotContainsString('wamp64', $reponse->getContent());
    }

    public function test_en_developpement_le_detail_reste_disponible(): void
    {
        config(['app.debug' => true]);

        $reponse = $this->getJson('/api/v1/_essai/table-absente');

        $reponse->assertStatus(500)->assertJsonPath('code', 'STRUCTURE_BASE');
        $this->assertStringContainsString('T_TABLE_ABSENTE', (string) $reponse->json('detail'));
        $this->assertStringNotContainsString('SQLSTATE', (string) $reponse->json('message'));
    }

    public function test_les_erreurs_metier_ne_sont_pas_touchees(): void
    {
        $this->getJson('/api/v1/_essai/validation')
            ->assertStatus(422)
            ->assertJsonPath('errors.nom.0', 'Le nom est obligatoire.');

        $this->getJson('/api/v1/_essai/inexistant')->assertStatus(404);
    }
}
