<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Matiere;

class MatiereSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'FR', 'libelle' => 'Français', 'coefficient_defaut' => 4],
            ['code' => 'MATH', 'libelle' => 'Mathématiques', 'coefficient_defaut' => 4],
            ['code' => 'EVS', 'libelle' => 'Éveil scientifique', 'coefficient_defaut' => 2],
            ['code' => 'HG', 'libelle' => 'Histoire-Géographie', 'coefficient_defaut' => 2],
            ['code' => 'EMC', 'libelle' => 'Éducation civique et morale', 'coefficient_defaut' => 1],
            ['code' => 'EPS', 'libelle' => 'Éducation physique et sportive', 'coefficient_defaut' => 1],
            ['code' => 'ART', 'libelle' => 'Arts plastiques', 'coefficient_defaut' => 1],
            ['code' => 'ANG', 'libelle' => 'Anglais', 'coefficient_defaut' => 1],
        ])->each(fn (array $matiere) => Matiere::firstOrCreate(['code' => $matiere['code']], $matiere));
    }
}
