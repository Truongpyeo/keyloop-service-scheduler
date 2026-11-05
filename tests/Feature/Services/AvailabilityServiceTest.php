<?php

use App\Models\ServiceType;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

it('computes a day of slots with three queries, however many appointments exist', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00+07:00'));
    $dealership = bookableDealership(technicians: 3, serviceBays: 3);
    foreach ($dealership->technicians as $index => $technician) {
        existingAppointment($technician, $dealership->serviceBays[$index], '2026-11-05 02:00:00', '2026-11-05 03:00:00');
    }
    $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

    DB::enableQueryLog();
    $slots = app(AvailabilityService::class)->slotsFor($dealership, $serviceType, CarbonImmutable::parse('2026-11-05', 'Asia/Ho_Chi_Minh'));

    expect(DB::getQueryLog())->toHaveCount(3);
    expect($slots)->toHaveCount(16);
});
