<?php

use App\Models\Appointment;
use App\Models\ServiceType;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00+07:00'));
});

it('cancels a confirmed appointment and returns 200', function () {
    $appointment = Appointment::factory()->create();

    $this->patchJson(route('appointments.cancel', $appointment))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('appointments', ['id' => $appointment->id, 'status' => 'cancelled']);
});

it('returns 200 when the appointment is already cancelled', function () {
    $appointment = Appointment::factory()->cancelled()->create();

    $this->patchJson(route('appointments.cancel', $appointment))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');
});

it('frees the slot so the same time can be booked again', function () {
    $dealership = bookableDealership(technicians: 1, serviceBays: 1);
    $serviceType = ServiceType::factory()->create();
    $first = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00'))
        ->assertCreated();
    $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00'))
        ->assertConflict();

    $this->patchJson(route('appointments.cancel', $first->json('data.id')))->assertOk();

    $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00'))
        ->assertCreated();
});

it('returns 404 for an unknown appointment', function () {
    $this->patchJson(route('appointments.cancel', 999))->assertNotFound();
});
