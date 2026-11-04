<?php

namespace Database\Factories;

use App\Models\Dealership;
use App\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dealership_id' => Dealership::factory(),
            'name' => fake()->name(),
        ];
    }

    /**
     * Give the technician the given skills.
     */
    public function withSkills(string ...$skills): static
    {
        return $this->afterCreating(function (Technician $technician) use ($skills): void {
            foreach ($skills as $skill) {
                $technician->skills()->create(['skill' => $skill]);
            }
        });
    }
}
