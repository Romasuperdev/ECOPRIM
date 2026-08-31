<?php

namespace Tests\Feature\Pedagogie;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Années scolaires en lecture seule sur ECONOMAT.T_ANNEEACADEMIQUE : l'API doit
 * renvoyer les attributs mappés (id, libelle, dates, statuts) à partir des vraies colonnes.
 */
class AnneeScolaireReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMasterDb();
        $this->setUpEconomatDb();

        $rh = RhUser::on('master')->forceCreate([
            'Id' => 1, 'Login' => 'u', 'Nom' => 'N', 'Prenom' => 'P', 'Email' => 'u@ecole.ci',
            'MotDePasse' => Hash::make('x'), 'SuperAdmin' => true, 'Supprimer' => false,
        ]);
        $this->actingAs($rh, 'sanctum');
    }

    public function test_liste_mappe_les_colonnes_reelles(): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            'CODE' => 12, 'CodeAnnee' => '2025-2026', 'LibelleAnnee' => 'Année 2025-2026',
            'Activer' => true, 'CloturePartielle' => false, 'ClotureDefinitive' => false,
            'DEBUT' => '2025-09-01', 'FIN' => '2026-07-05', 'CODESOCIETE' => 'ABN',
        ]);

        $this->getJson('/api/v1/annees-scolaires')
            ->assertOk()
            ->assertJsonFragment([
                'id' => 12,
                'code_annee' => '2025-2026',
                'libelle' => 'Année 2025-2026',
                'date_debut' => '2025-09-01',
                'date_fin' => '2026-07-05',
                'active' => true,
                'cloturee' => false,
                'cloture_partielle' => false,
                'societe_code' => 'ABN',
            ]);
    }

    public function test_tri_par_date_debut_descendante(): void
    {
        DB::connection('economat')->table('T_ANNEEACADEMIQUE')->insert([
            ['CODE' => 1, 'CodeAnnee' => '2024', 'LibelleAnnee' => 'A', 'DEBUT' => '2024-09-01', 'FIN' => '2025-07-01'],
            ['CODE' => 2, 'CodeAnnee' => '2026', 'LibelleAnnee' => 'B', 'DEBUT' => '2026-09-01', 'FIN' => '2027-07-01'],
        ]);

        $data = $this->getJson('/api/v1/annees-scolaires')->assertOk()->json();
        $this->assertSame(2, $data[0]['id']); // la plus récente d'abord
    }
}
