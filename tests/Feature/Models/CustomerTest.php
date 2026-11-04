<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('has many vehicles and appointments', function () {
    $customer = Customer::factory()->create();
    $vehicles = Vehicle::factory()->count(2)->for($customer)->create();
    $appointment = Appointment::factory()->for($customer)->create();
    Vehicle::factory()->create();

    expect($customer->vehicles->modelKeys())->toEqualCanonicalizing([...$vehicles->modelKeys(), $appointment->vehicle_id]);
    expect($customer->appointments->modelKeys())->toBe([$appointment->id]);
});

it('cannot be deleted while it still owns vehicles', function () {
    $customer = Customer::factory()->create();
    Vehicle::factory()->for($customer)->create();

    expect(fn () => $customer->delete())->toThrow(QueryException::class);
    $this->assertModelExists($customer);
});
