<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('belongs to a dealership and has many appointments', function () {
    $dealership = Dealership::factory()->create();
    $serviceBay = ServiceBay::factory()->for($dealership)->create();
    $appointment = Appointment::factory()->for($dealership)->for($serviceBay)->create();

    expect($serviceBay->dealership->is($dealership))->toBeTrue();
    expect($serviceBay->appointments->modelKeys())->toBe([$appointment->id]);
});
