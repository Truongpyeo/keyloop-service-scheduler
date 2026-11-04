<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\Technician;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('has many technicians, service bays and appointments', function () {
    $dealership = Dealership::factory()->create();
    $technicians = Technician::factory()->count(2)->for($dealership)->create();
    $serviceBays = ServiceBay::factory()->count(3)->for($dealership)->create();
    $appointment = Appointment::factory()
        ->for($dealership)
        ->for($technicians->first())
        ->for($serviceBays->first())
        ->create();
    Technician::factory()->create();

    expect($dealership->technicians->modelKeys())->toEqualCanonicalizing($technicians->modelKeys());
    expect($dealership->serviceBays->modelKeys())->toEqualCanonicalizing($serviceBays->modelKeys());
    expect($dealership->appointments->modelKeys())->toBe([$appointment->id]);
});

it('cannot be deleted while it still has technicians', function () {
    $dealership = Dealership::factory()->create();
    Technician::factory()->for($dealership)->create();

    expect(fn () => $dealership->delete())->toThrow(QueryException::class);
    $this->assertModelExists($dealership);
});
