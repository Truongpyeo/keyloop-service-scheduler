<?php

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'vehicle_id' => fn (array $attributes) => Vehicle::factory()->create(['customer_id' => $attributes['customer_id']]),
            'dealership_id' => Dealership::factory(),
            'service_type_id' => ServiceType::factory(),
            'technician_id' => fn (array $attributes) => Technician::factory()->create(['dealership_id' => $attributes['dealership_id']]),
            'service_bay_id' => fn (array $attributes) => ServiceBay::factory()->create(['dealership_id' => $attributes['dealership_id']]),
            'starts_at' => CarbonImmutable::now('UTC')->addDay()->setTime(2, 0),
            'ends_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_at'])
                ->addMinutes(ServiceType::findOrFail($attributes['service_type_id'])->duration_minutes),
            'status' => AppointmentStatus::Confirmed,
            'idempotency_key' => null,
            'request_hash' => null,
        ];
    }

    /**
     * Indicate that the appointment has been cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Cancelled,
        ]);
    }
}
