<?php

namespace Tests\Feature;

use App\Support\TablesPropres;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Les tables propres à NEXORA se créent d'elles-mêmes au premier appel de l'API, sauf si
 * elles risquent d'écraser celles d'une autre application, ou si la base visée est
 * dbmasterbacou / ECONOMAT.
 */
class TablesPropresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.ecoprim' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('ecoprim');
        config(['nexora.installation_auto' => true]);
        TablesPropres::oublier();
    }

    public function test_les_tables_manquantes_sont_creees_au_premier_appel(): void
    {
        $this->assertCount(15, TablesPropres::manquantes());

        $this->getJson('/api/v1/me'); // n'importe quelle route de l'API

        $this->assertSame([], TablesPropres::manquantes());
        $this->assertTrue(Schema::connection('ecoprim')->hasTable('console_affectations'));
    }

    public function test_une_table_d_une_autre_application_n_est_jamais_completee(): void
    {
        Schema::connection('ecoprim')->create('aide_articles', fn ($t) => $t->id());

        $this->getJson('/api/v1/me');

        // L'aide est laissée de côté (une de ses deux tables existe déjà)…
        $this->assertSame(['aide_articles'], TablesPropres::conflits());
        $this->assertFalse(Schema::connection('ecoprim')->hasTable('aide_categories'));
        // … mais le reste est créé.
        $this->assertTrue(Schema::connection('ecoprim')->hasTable('console_societes'));
        $this->assertTrue(Schema::connection('ecoprim')->hasTable('nouveautes_versions'));
    }

    public function test_les_tables_manquantes_seules_sont_creees(): void
    {
        // Cas du serveur : les tables console_* existent, l'aide et les nouveautés manquent.
        $this->getJson('/api/v1/me');
        Schema::connection('ecoprim')->drop('aide_articles');
        Schema::connection('ecoprim')->drop('aide_categories');
        DB::connection('ecoprim')->table('console_roles')->insert(['code' => 'x', 'nom' => 'X']);
        TablesPropres::oublier();

        $this->getJson('/api/v1/me');

        $this->assertTrue(Schema::connection('ecoprim')->hasTable('aide_categories'));
        $this->assertSame(1, DB::connection('ecoprim')->table('console_roles')->count()); // intact
    }

    public function test_ecoprim_peut_partager_la_base_d_economat(): void
    {
        config(['database.connections.economat.database' => ':memory:']);

        $this->assertTrue(TablesPropres::installer());
        $this->assertTrue(Schema::connection('ecoprim')->hasTable('aide_articles'));
    }

    public function test_jamais_dans_dbmasterbacou(): void
    {
        config(['database.connections.master.database' => ':memory:']);

        $this->assertFalse(TablesPropres::installer());
        $this->assertFalse(Schema::connection('ecoprim')->hasTable('console_societes'));
    }

    public function test_desactivable(): void
    {
        config(['nexora.installation_auto' => false]);

        $this->getJson('/api/v1/me');

        $this->assertCount(15, TablesPropres::manquantes());
    }
}
