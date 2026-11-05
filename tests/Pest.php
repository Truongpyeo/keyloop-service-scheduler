<?php

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A dealership open 08:00–18:00 in Asia/Ho_Chi_Minh, with technicians who all have the given skills, and bays.
 *
 * @param  list<string>  $skills
 */
function bookableDealership(int $technicians = 1, int $serviceBays = 1, array $skills = ['general']): Dealership
{
    $dealership = Dealership::factory()->create([
        'timezone' => 'Asia/Ho_Chi_Minh',
        'opens_at' => '08:00:00',
        'closes_at' => '18:00:00',
    ]);

    Technician::factory()->count($technicians)->for($dealership)->withSkills(...$skills)->create();
    ServiceBay::factory()->count($serviceBays)->for($dealership)->create();

    return $dealership;
}

/**
 * A confirmed appointment holding the given technician and bay between two UTC instants.
 */
function existingAppointment(Technician $technician, ServiceBay $serviceBay, string $startsAtUtc, string $endsAtUtc): Appointment
{
    return Appointment::factory()
        ->for($technician->dealership)
        ->for($technician)
        ->for($serviceBay)
        ->create(['starts_at' => $startsAtUtc, 'ends_at' => $endsAtUtc]);
}

/**
 * The JSON body of POST /api/appointments.
 *
 * @return array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}
 */
function bookingRequest(Dealership $dealership, ServiceType $serviceType, Vehicle $vehicle, string $startsAt): array
{
    return [
        'customer_id' => $vehicle->customer_id,
        'vehicle_id' => $vehicle->id,
        'dealership_id' => $dealership->id,
        'service_type_id' => $serviceType->id,
        'starts_at' => $startsAt,
    ];
}
