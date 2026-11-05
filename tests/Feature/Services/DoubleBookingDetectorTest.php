<?php

use App\Models\Appointment;
use App\Services\DoubleBookingDetector;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('reports two overlapping confirmed appointments that share a resource', function (string $sharedColumn, string $sharedResource) {
    $first = Appointment::factory()->create(['starts_at' => '2026-11-05 02:00:00', 'ends_at' => '2026-11-05 03:00:00']);
    $second = Appointment::factory()->create([
        $sharedColumn => $first->{$sharedColumn},
        'starts_at' => '2026-11-05 02:30:00',
        'ends_at' => '2026-11-05 03:30:00',
    ]);

    $pairs = app(DoubleBookingDetector::class)->overlappingPairs();

    expect($pairs)->toHaveCount(1);
    expect((array) $pairs->first())->toEqual(['first_id' => $first->id, 'second_id' => $second->id, 'shared_resource' => $sharedResource]);
})->with([
    'same technician' => ['technician_id', 'technician'],
    'same bay' => ['service_bay_id', 'service_bay'],
    'same vehicle' => ['vehicle_id', 'vehicle'],
]);

it('does not report back-to-back appointments on the same technician', function () {
    $first = Appointment::factory()->create(['starts_at' => '2026-11-05 02:00:00', 'ends_at' => '2026-11-05 03:00:00']);
    Appointment::factory()->create([
        'technician_id' => $first->technician_id,
        'starts_at' => '2026-11-05 03:00:00',
        'ends_at' => '2026-11-05 04:00:00',
    ]);

    expect(app(DoubleBookingDetector::class)->overlappingPairs())->toBeEmpty();
});

it('does not report an overlap with a cancelled appointment', function () {
    $first = Appointment::factory()->cancelled()->create(['starts_at' => '2026-11-05 02:00:00', 'ends_at' => '2026-11-05 03:00:00']);
    Appointment::factory()->create([
        'technician_id' => $first->technician_id,
        'starts_at' => '2026-11-05 02:30:00',
        'ends_at' => '2026-11-05 03:30:00',
    ]);

    expect(app(DoubleBookingDetector::class)->overlappingPairs())->toBeEmpty();
});
