<?php

use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('seeds the Keyloop Da Nang dealership with its opening hours and timezone', function () {
    $this->seed();

    expect(Dealership::count())->toBe(1);
    $this->assertDatabaseHas('dealerships', [
        'name' => 'Keyloop Da Nang',
        'timezone' => 'Asia/Ho_Chi_Minh',
        'opens_at' => '08:00:00',
        'closes_at' => '18:00:00',
    ]);
});

it('seeds the three service types with their durations and required skills', function () {
    $this->seed();

    $serviceTypes = ServiceType::orderBy('duration_minutes')->get(['name', 'duration_minutes', 'required_skill'])->toArray();

    expect($serviceTypes)->toBe([
        ['name' => 'Oil change', 'duration_minutes' => 60, 'required_skill' => 'general'],
        ['name' => 'Brake inspection', 'duration_minutes' => 90, 'required_skill' => 'brakes'],
        ['name' => 'EV battery check', 'duration_minutes' => 120, 'required_skill' => 'ev'],
    ]);
});

it('seeds four technicians and three bays at the dealership', function () {
    $this->seed();

    $dealership = Dealership::sole();

    expect(Technician::whereBelongsTo($dealership)->count())->toBe(4);
    expect(ServiceBay::whereBelongsTo($dealership)->count())->toBe(3);
});

it('seeds technicians covering every skill, with exactly one EV technician', function () {
    $this->seed();

    $skillSets = Technician::with('skills')->get()
        ->map(fn (Technician $technician) => $technician->skills->pluck('skill')->sort()->values()->all());

    expect($skillSets)->toContain(['general']);
    expect($skillSets)->toContain(['brakes', 'general']);
    expect($skillSets->filter(fn (array $skills) => in_array('ev', $skills))->count())->toBe(1);
});

it('seeds data where every service type has at least one bookable slot tomorrow', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00+07:00'));
    $this->seed();
    $dealership = Dealership::sole();

    foreach (ServiceType::all() as $serviceType) {
        $this->getJson(route('dealerships.availability', [$dealership, 'service_type_id' => $serviceType->id, 'date' => '2026-11-05']))
            ->assertOk()
            ->assertJsonPath('data.slots.0.starts_at', '2026-11-05T08:00:00+07:00');
    }
});

it('seeds three customers who each own one or two vehicles with 17-character VINs', function () {
    $this->seed();

    $customers = Customer::withCount('vehicles')->get();

    expect($customers)->toHaveCount(3);
    expect($customers->pluck('vehicles_count')->every(fn (int $count) => $count >= 1 && $count <= 2))->toBeTrue();
    expect(Vehicle::pluck('vin')->every(fn (string $vin) => preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin) === 1))->toBeTrue();
});
