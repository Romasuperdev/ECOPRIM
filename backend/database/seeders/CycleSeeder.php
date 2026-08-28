<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Cycle;

class CycleSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['code' => 'PREP', 'libelle' => 'Cycle Préparatoire', 'ordre' => 1],
            ['code' => 'ELEM', 'libelle' => 'Cycle Élémentaire', 'ordre' => 2],
            ['code' => 'MOY', 'libelle' => 'Cycle Moyen', 'ordre' => 3],
        ])->each(fn (array $cycle) => Cycle::firstOrCreate(['code' => $cycle['code']], $cycle));
    }
}
