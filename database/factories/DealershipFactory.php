<?php

namespace Database\Factories;

use App\Models\Dealership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dealership>
 */
class DealershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'opens_at' => '08:00:00',
            'closes_at' => '18:00:00',
        ];
    }
}
