<?php

namespace Database\Factories;

use App\Models\Societe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Societe>
 */
class SocieteFactory extends Factory
{
    protected $model = Societe::class;

    public function definition(): array
    {
        return [
            'code' => 'SOC'.fake()->unique()->numberBetween(1000, 999999),
            'nom' => fake()->company(),
            'statut' => 'actif',
        ];
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['statut' => 'inactif']);
    }
}
