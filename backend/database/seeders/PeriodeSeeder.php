<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AnneeScolaire;
use App\Models\Periode;

class PeriodeSeeder extends Seeder
{
    public function run(): void
    {
        $anneeScolaire = AnneeScolaire::where('active', true)->first();

        if (! $anneeScolaire) {
            return;
        }

        collect([
            ['libelle' => 'Trimestre 1', 'ordre' => 1],
            ['libelle' => 'Trimestre 2', 'ordre' => 2],
            ['libelle' => 'Trimestre 3', 'ordre' => 3],
        ])->each(fn (array $periode) => Periode::firstOrCreate(
            ['annee_scolaire_id' => $anneeScolaire->id, 'libelle' => $periode['libelle']],
            ['ordre' => $periode['ordre']]
        ));
    }
}
