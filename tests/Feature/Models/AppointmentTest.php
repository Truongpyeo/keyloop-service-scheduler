<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('belongs to its customer, vehicle, dealership, service type, technician and service bay', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $dealership = Dealership::factory()->create();
    $serviceType = ServiceType::factory()->create();
    $technician = Technician::factory()->for($dealership)->create();
    $serviceBay = ServiceBay::factory()->for($dealership)->create();

    $appointment = Appointment::factory()
        ->for($customer)
        ->for($vehicle)
        ->for($dealership)
        ->for($serviceType)
        ->for($technician)
        ->for($serviceBay)
        ->create();

    expect($appointment->customer->is($customer))->toBeTrue();
    expect($appointment->vehicle->is($vehicle))->toBeTrue();
    expect($appointment->dealership->is($dealership))->toBeTrue();
    expect($appointment->serviceType->is($serviceType))->toBeTrue();
    expect($appointment->technician->is($technician))->toBeTrue();
    expect($appointment->serviceBay->is($serviceBay))->toBeTrue();
});

it('casts the start and end times to immutable UTC datetimes', function () {
    $appointment = Appointment::factory()->create([
        'starts_at' => '2026-11-05 02:00:00',
        'ends_at' => '2026-11-05 03:30:00',
    ])->fresh();

    expect($appointment->starts_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($appointment->starts_at->toIso8601ZuluString())->toBe('2026-11-05T02:00:00Z');
    expect($appointment->ends_at->toIso8601ZuluString())->toBe('2026-11-05T03:30:00Z');
});

it('casts the status to the appointment status enum', function () {
    $confirmed = Appointment::factory()->create()->fresh();
    $cancelled = Appointment::factory()->cancelled()->create()->fresh();

    expect($confirmed->status)->toBe(AppointmentStatus::Confirmed);
    expect($cancelled->status)->toBe(AppointmentStatus::Cancelled);
});
