<?php

namespace Database\Factories;

use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tiers>
 */
class TiersFactory extends Factory
{
    protected $model = Tiers::class;

    public function definition(): array
    {
        return [
            'code' => 'CL-2026-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => fake()->company(),
            'is_client' => true,
            'is_supplier' => false,
            'ice' => (string) fake()->numerify('###############'),
            'city' => fake()->randomElement(['Casablanca', 'Rabat', 'Marrakech', 'Tanger', 'Agadir']),
            'country' => 'MA',
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'is_active' => true,
        ];
    }
}
