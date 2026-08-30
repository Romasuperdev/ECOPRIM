<?php

namespace Database\Factories;

use App\Models\Affectation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Affectation>
 */
class AffectationFactory extends Factory
{
    protected $model = Affectation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'societe_id' => null,
            'etablissement_id' => null,
            'role_id' => fn () => Role::findOrCreate('Enseignant', 'web')->id,
            'date_debut' => now()->toDateString(),
            'date_fin' => null,
            'actif' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }

    public function expiree(): static
    {
        return $this->state(fn () => ['date_fin' => now()->subDay()->toDateString()]);
    }
}
