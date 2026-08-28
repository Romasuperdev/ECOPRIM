<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cycle;
use App\Models\Niveau;

class NiveauSeeder extends Seeder
{
    public function run(): void
    {
        $prep = Cycle::where('code', 'PREP')->first();
        $elem = Cycle::where('code', 'ELEM')->first();
        $moy = Cycle::where('code', 'MOY')->first();

        collect([
            ['code' => 'CP1', 'libelle' => 'Cours Préparatoire 1', 'ordre' => 1, 'cycle_id' => $prep?->id],
            ['code' => 'CP2', 'libelle' => 'Cours Préparatoire 2', 'ordre' => 2, 'cycle_id' => $prep?->id],
            ['code' => 'CE1', 'libelle' => 'Cours Élémentaire 1', 'ordre' => 3, 'cycle_id' => $elem?->id],
            ['code' => 'CE2', 'libelle' => 'Cours Élémentaire 2', 'ordre' => 4, 'cycle_id' => $elem?->id],
            ['code' => 'CM1', 'libelle' => 'Cours Moyen 1', 'ordre' => 5, 'cycle_id' => $moy?->id],
            ['code' => 'CM2', 'libelle' => 'Cours Moyen 2', 'ordre' => 6, 'cycle_id' => $moy?->id],
        ])->each(fn (array $niveau) => Niveau::updateOrCreate(['code' => $niveau['code']], $niveau));
    }
}
