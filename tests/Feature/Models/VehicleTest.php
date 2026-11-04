<?php

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('belongs to a customer and has many appointments', function () {
    $customer = Customer::factory()->create();
    $vehicle = Vehicle::factory()->for($customer)->create();
    $appointment = Appointment::factory()->for($customer)->for($vehicle)->create();

    expect($vehicle->customer->is($customer))->toBeTrue();
    expect($vehicle->appointments->modelKeys())->toBe([$appointment->id]);
});

it('rejects a second vehicle with the same VIN', function () {
    Vehicle::factory()->create(['vin' => 'MR0FB8CD6N0123456']);

    expect(fn () => Vehicle::factory()->create(['vin' => 'MR0FB8CD6N0123456']))->toThrow(QueryException::class);
});
