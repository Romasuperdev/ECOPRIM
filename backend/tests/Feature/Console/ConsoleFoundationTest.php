<?php

namespace Tests\Feature\Console;

use App\Models\Console\Affectation;
use App\Models\Console\Etablissement;
use App\Models\Console\Role;
use App\Models\Console\Societe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fondation Console écrivable (base ecoprim) : migrations + modèles + relations. */
class ConsoleFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
    }

    public function test_hierarchie_societe_etablissement_affectation(): void
    {
        $societe = Societe::create(['code' => 'ABN', 'nom' => 'Abidjan Nord']);
        $etab = Etablissement::create(['code' => 'ETB1', 'intitule' => 'École Alpha', 'societe_code' => 'ABN']);
        $role = Role::create(['code' => 'ADMIN', 'nom' => 'Administrateur']);

        $aff = Affectation::create([
            'rh_user_id' => 42, 'societe_code' => 'ABN', 'etablissement_code' => 'ETB1', 'role_id' => $role->id,
        ]);

        // Relations
        $this->assertSame('Abidjan Nord', $etab->societe->nom);
        $this->assertSame('École Alpha', $aff->etablissement->intitule);
        $this->assertSame('Administrateur', $aff->role->nom);
        $this->assertCount(1, $societe->etablissements);
        $this->assertTrue($etab->fresh()->actif);
    }

    public function test_un_utilisateur_plusieurs_roles_dans_un_etablissement(): void
    {
        Societe::create(['code' => 'ABN', 'nom' => 'Nord']);
        Etablissement::create(['code' => 'E1', 'intitule' => 'A', 'societe_code' => 'ABN']);
        $r1 = Role::create(['code' => 'ADMIN', 'nom' => 'Admin']);
        $r2 = Role::create(['code' => 'GEST', 'nom' => 'Gestionnaire']);

        Affectation::create(['rh_user_id' => 7, 'societe_code' => 'ABN', 'etablissement_code' => 'E1', 'role_id' => $r1->id]);
        Affectation::create(['rh_user_id' => 7, 'societe_code' => 'ABN', 'etablissement_code' => 'E1', 'role_id' => $r2->id]);

        $this->assertSame(2, Affectation::where('rh_user_id', 7)->where('etablissement_code', 'E1')->count());
    }
}
