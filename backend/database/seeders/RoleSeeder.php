<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Super Admin',
            'Admin Société',
            'Admin Établissement',
            'Direction',
            'Directeur Adjoint',
            'Enseignant',
            'Secretaire',
            'Surveillant',
            'Parent',
            'Élève',
        ])->each(fn (string $role) => Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
    }
}
