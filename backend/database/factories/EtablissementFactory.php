<?php

namespace Database\Factories;

use App\Models\Etablissement;
use App\Models\Societe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Etablissement>
 */
class EtablissementFactory extends Factory
{
    protected $model = Etablissement::class;

    public function definition(): array
    {
        return [
            'societe_id' => Societe::factory(),
            'code' => 'ETB'.fake()->unique()->numberBetween(1000, 999999),
            'nom' => 'Établissement '.fake()->city(),
            'type' => 'primaire',
            'statut' => 'actif',
        ];
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['statut' => 'inactif']);
    }
}
