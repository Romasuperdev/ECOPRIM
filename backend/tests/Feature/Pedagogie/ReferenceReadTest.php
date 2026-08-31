<?php

namespace Tests\Feature\Pedagogie;

use App\Models\RhUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tables de référence pédagogiques en lecture seule sur ECONOMAT :
 * niveaux (T_NIVEAU), matières (T_MATIERE), cycles (T_CYCLE).
 */
class ReferenceReadTest extends TestCase
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

    public function test_cycles_mappes(): void
    {
        DB::connection('economat')->table('T_CYCLE')->insert([
            'Num' => 1, 'CodeCycle' => 'PRIM', 'LibelleCycle' => 'Primaire', 'Primaire' => true,
        ]);

        $this->getJson('/api/v1/cycles')->assertOk()
            ->assertJsonFragment(['id' => 1, 'code' => 'PRIM', 'libelle' => 'Primaire', 'primaire' => true]);
    }

    public function test_niveaux_mappes_avec_cycle(): void
    {
        DB::connection('economat')->table('T_CYCLE')->insert(['Num' => 1, 'CodeCycle' => 'PRIM', 'LibelleCycle' => 'Primaire', 'Primaire' => true]);
        DB::connection('economat')->table('T_NIVEAU')->insert([
            'Num' => 3, 'CodeNiveau' => 'CP1', 'LibelleNiveau' => 'Cours Préparatoire 1',
            'CodeCycle' => 'PRIM', 'Ordre' => 1, 'ANNEE' => '2025',
        ]);

        $this->getJson('/api/v1/niveaux')->assertOk()
            ->assertJsonFragment(['id' => 3, 'code' => 'CP1', 'libelle' => 'Cours Préparatoire 1', 'ordre' => 1, 'cycle_code' => 'PRIM'])
            ->assertJsonPath('0.cycle.libelle', 'Primaire');
    }

    public function test_matieres_paginees_et_mappees(): void
    {
        DB::connection('economat')->table('T_MATIERE')->insert([
            'Code' => 7, 'CodeMatiere' => 'MATH', 'LibelleMatiere' => 'Mathématiques', 'Type' => 'Fondamentale', 'CodeCycle' => 'PRIM',
        ]);

        $this->getJson('/api/v1/matieres')->assertOk()
            ->assertJsonFragment(['id' => 7, 'code' => 'MATH', 'libelle' => 'Mathématiques', 'type' => 'Fondamentale'])
            ->assertJsonStructure(['data', 'current_page', 'next_page_url']);
    }
}
