<?php

use App\Models\ServiceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00+07:00'));
});

it('lists 30-minute slots in local time from opening until the last one that ends by closing', function () {
    $dealership = bookableDealership();
    $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

    $response = $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']));

    $response->assertOk()
        ->assertJsonPath('data.timezone', 'Asia/Ho_Chi_Minh')
        ->assertJsonCount(19, 'data.slots')
        ->assertJsonPath('data.slots.0', ['starts_at' => '2026-11-05T08:00:00+07:00', 'ends_at' => '2026-11-05T09:00:00+07:00'])
        ->assertJsonPath('data.slots.18', ['starts_at' => '2026-11-05T17:00:00+07:00', 'ends_at' => '2026-11-05T18:00:00+07:00']);
});

it('leaves out slots that overlap an appointment of the only skilled technician', function () {
    $dealership = bookableDealership(technicians: 1, serviceBays: 2);
    existingAppointment($dealership->technicians()->sole(), $dealership->serviceBays()->first(), '2026-11-05 02:00:00', '2026-11-05 03:00:00');
    $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

    $startTimes = $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']))
        ->assertOk()
        ->collect('data.slots')
        ->pluck('starts_at');

    expect($startTimes)->toContain('2026-11-05T08:00:00+07:00', '2026-11-05T10:00:00+07:00');
    expect($startTimes)->not->toContain('2026-11-05T08:30:00+07:00', '2026-11-05T09:00:00+07:00', '2026-11-05T09:30:00+07:00');
});

it('keeps a slot open while another technician and bay are still free', function () {
    $dealership = bookableDealership(technicians: 2, serviceBays: 2);
    existingAppointment($dealership->technicians()->first(), $dealership->serviceBays()->first(), '2026-11-05 02:00:00', '2026-11-05 03:00:00');
    $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

    $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']))
        ->assertOk()
        ->assertJsonCount(19, 'data.slots');
});

it('leaves out slots that have already started today', function () {
    $dealership = bookableDealership();
    $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

    $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-04']))
        ->assertOk()
        ->assertJsonPath('data.slots.0.starts_at', '2026-11-04T10:30:00+07:00');
});

it('returns no slots when no technician has the required skill', function () {
    $dealership = bookableDealership(skills: ['general']);
    $serviceType = ServiceType::factory()->create(['required_skill' => 'ev']);

    $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']))
        ->assertOk()
        ->assertJsonCount(0, 'data.slots');
});

it('returns 422 when the date is not formatted as YYYY-MM-DD', function () {
    $dealership = bookableDealership();
    $serviceType = ServiceType::factory()->create();

    $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '05/11/2026']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('returns 404 for an unknown dealership', function () {
    $serviceType = ServiceType::factory()->create();

    $this->getJson(route('dealerships.availability', [999, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Dealership not found.']);
});
