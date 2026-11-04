<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'vin' => fake()->unique()->regexify('[A-HJ-NPR-Z0-9]{17}'),
            'make' => fake()->randomElement(['Toyota', 'Honda', 'Ford', 'VinFast', 'Hyundai']),
            'model' => fake()->word(),
            'year' => fake()->numberBetween(2010, 2026),
        ];
    }
}
