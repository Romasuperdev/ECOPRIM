<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AnneeScolaire;

class AnneeScolaireSeeder extends Seeder
{
    public function run(): void
    {
        AnneeScolaire::firstOrCreate(
            ['libelle' => '2026-2027'],
            [
                'date_debut' => '2026-09-01',
                'date_fin' => '2027-06-30',
                'active' => true,
            ]
        );
    }
}
