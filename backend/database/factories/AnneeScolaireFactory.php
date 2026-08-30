<?php

namespace Database\Factories;

use App\Models\AnneeScolaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AnneeScolaire>
 */
class AnneeScolaireFactory extends Factory
{
    protected $model = AnneeScolaire::class;

    public function definition(): array
    {
        $an = fake()->unique()->numberBetween(2000, 2090);

        return [
            'libelle' => $an.'-'.($an + 1),
            'date_debut' => $an.'-09-01',
            'date_fin' => ($an + 1).'-07-01',
            'active' => false,
            'cloturee' => false,
        ];
    }

    public function cloturee(): static
    {
        return $this->state(fn () => ['cloturee' => true]);
    }
}
