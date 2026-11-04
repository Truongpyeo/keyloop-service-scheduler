<?php

use App\Models\Appointment;
use App\Models\ServiceType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('has many appointments', function () {
    $serviceType = ServiceType::factory()->create();
    $appointment = Appointment::factory()->for($serviceType)->create();
    Appointment::factory()->create();

    expect($serviceType->appointments->modelKeys())->toBe([$appointment->id]);
});
