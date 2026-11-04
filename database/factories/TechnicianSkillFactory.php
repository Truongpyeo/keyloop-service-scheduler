<?php

namespace Database\Factories;

use App\Models\Technician;
use App\Models\TechnicianSkill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechnicianSkill>
 */
class TechnicianSkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'technician_id' => Technician::factory(),
            'skill' => 'general',
        ];
    }
}
