<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04T10:00:00+07:00'));
});

describe('store', function () {
    it('books the appointment and returns 201 with UTC and local times', function () {
        $dealership = bookableDealership();
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60, 'required_skill' => 'general']);
        $vehicle = Vehicle::factory()->create();

        $response = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T09:00:00+07:00'));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.starts_at.utc', '2026-11-05T02:00:00Z')
            ->assertJsonPath('data.starts_at.local', '2026-11-05T09:00:00+07:00')
            ->assertJsonPath('data.ends_at.local', '2026-11-05T10:00:00+07:00');
        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('data.id'),
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'technician_id' => $dealership->technicians()->sole()->id,
            'service_bay_id' => $dealership->serviceBays()->sole()->id,
            'starts_at' => '2026-11-05 02:00:00',
            'ends_at' => '2026-11-05 03:00:00',
            'status' => 'confirmed',
        ]);
    });

    it('logs booking.succeeded with the assigned technician and bay', function () {
        $dealership = bookableDealership();
        $serviceType = ServiceType::factory()->create();
        $vehicle = Vehicle::factory()->create();
        Log::spy();

        $response = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T09:00:00+07:00'));

        Log::shouldHaveReceived('info')->with('booking.succeeded', Mockery::on(
            fn (array $context) => $context['appointment_id'] === $response->json('data.id')
                && $context['starts_at'] === '2026-11-05T02:00:00Z'
        ));
    });

    it('returns 409 no_technician_available when the only skilled technician is busy', function () {
        $dealership = bookableDealership(technicians: 1, serviceBays: 2);
        existingAppointment($dealership->technicians()->sole(), $dealership->serviceBays()->first(), '2026-11-05 02:00:00', '2026-11-05 03:00:00');
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);
        Log::spy();

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:30:00+07:00'))
            ->assertConflict()
            ->assertJsonPath('reason', 'no_technician_available');

        $this->assertDatabaseCount('appointments', 1);
        Log::shouldHaveReceived('warning')->with('booking.failed', Mockery::on(
            fn (array $context) => $context['reason'] === 'no_technician_available'
        ));
    });

    it('returns 409 no_bay_available when every bay is busy', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 1);
        existingAppointment($dealership->technicians()->first(), $dealership->serviceBays()->sole(), '2026-11-05 02:00:00', '2026-11-05 03:00:00');
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00'))
            ->assertConflict()
            ->assertJsonPath('reason', 'no_bay_available');

        $this->assertDatabaseCount('appointments', 1);
    });

    it('returns 409 no_skilled_technician when nobody at the dealership has the required skill', function () {
        $dealership = bookableDealership(skills: ['general']);
        $serviceType = ServiceType::factory()->create(['required_skill' => 'ev']);

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00'))
            ->assertConflict()
            ->assertJsonPath('reason', 'no_skilled_technician');

        $this->assertDatabaseCount('appointments', 0);
    });

    it('returns 409 vehicle_already_booked when the vehicle has an overlapping appointment elsewhere', function () {
        $vehicle = Vehicle::factory()->create();
        Appointment::factory()->for($vehicle->customer)->for($vehicle)->create([
            'starts_at' => '2026-11-05 02:00:00',
            'ends_at' => '2026-11-05 03:00:00',
        ]);
        $dealership = bookableDealership();
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T09:30:00+07:00'))
            ->assertConflict()
            ->assertJsonPath('reason', 'vehicle_already_booked');

        $this->assertDatabaseCount('appointments', 1);
    });

    it('assigns the free technician with the fewest appointments that day', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 2);
        [$busierTechnician, $quieterTechnician] = $dealership->technicians()->orderBy('id')->get();
        existingAppointment($busierTechnician, $dealership->serviceBays()->first(), '2026-11-05 01:00:00', '2026-11-05 02:00:00');
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

        $response = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T11:00:00+07:00'));

        $response->assertCreated()->assertJsonPath('data.technician.id', $quieterTechnician->id);
    });

    it('assigns the lowest-id technician and bay when free technicians are equally busy', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 2);
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

        $response = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T11:00:00+07:00'));

        $response->assertCreated()
            ->assertJsonPath('data.technician.id', $dealership->technicians()->min('id'))
            ->assertJsonPath('data.service_bay.id', $dealership->serviceBays()->min('id'));
    });

    it('returns 201 when the service ends exactly at closing time', function () {
        $dealership = bookableDealership();
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 60]);

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T17:00:00+07:00'))
            ->assertCreated()
            ->assertJsonPath('data.ends_at.local', '2026-11-05T18:00:00+07:00');
    });

    it('returns 422 when the service would end one minute after closing time', function () {
        $dealership = bookableDealership();
        $serviceType = ServiceType::factory()->create(['duration_minutes' => 61]);

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, Vehicle::factory()->create(), '2026-11-05T17:00:00+07:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'finish by 18:00']);

        $this->assertDatabaseCount('appointments', 0);
    });

    it('returns 422 when starts_at has no UTC offset', function () {
        $dealership = bookableDealership();

        $this->postJson(route('appointments.store'), bookingRequest($dealership, ServiceType::factory()->create(), Vehicle::factory()->create(), '2026-11-05T09:00:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'UTC offset or Z']);
    });

    it('returns 422 when starts_at is not on a 30-minute boundary in local time', function () {
        $dealership = bookableDealership();

        $this->postJson(route('appointments.store'), bookingRequest($dealership, ServiceType::factory()->create(), Vehicle::factory()->create(), '2026-11-05T09:15:00+07:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => '30-minute boundary']);
    });

    it('returns 422 when starts_at is in the past', function () {
        $dealership = bookableDealership();

        $this->postJson(route('appointments.store'), bookingRequest($dealership, ServiceType::factory()->create(), Vehicle::factory()->create(), '2026-11-04T09:30:00+07:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at' => 'must be in the future']);
    });

    it('returns 422 when the vehicle does not belong to the customer', function () {
        $dealership = bookableDealership();
        $vehicle = Vehicle::factory()->create();
        $otherCustomer = Customer::factory()->create();

        $this->postJson(route('appointments.store'), [
            ...bookingRequest($dealership, ServiceType::factory()->create(), $vehicle, '2026-11-05T09:00:00+07:00'),
            'customer_id' => $otherCustomer->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vehicle_id' => 'does not belong to the customer']);
    });

    it('returns 200 with the same appointment when the Idempotency-Key is replayed', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 2);
        $request = bookingRequest($dealership, ServiceType::factory()->create(), Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00');

        $first = $this->postJson(route('appointments.store'), $request, ['Idempotency-Key' => 'booking-123']);
        $replay = $this->postJson(route('appointments.store'), $request, ['Idempotency-Key' => 'booking-123']);

        $first->assertCreated();
        $replay->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('appointments', 1);
    });

    it('treats the same start time written in UTC as the same request for an Idempotency-Key', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 2);
        $serviceType = ServiceType::factory()->create();
        $vehicle = Vehicle::factory()->create();

        $first = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T09:00:00+07:00'), ['Idempotency-Key' => 'booking-123']);
        $replay = $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T02:00:00Z'), ['Idempotency-Key' => 'booking-123']);

        $replay->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    });

    it('returns 422 when an Idempotency-Key is reused with a different payload', function () {
        $dealership = bookableDealership(technicians: 2, serviceBays: 2);
        $serviceType = ServiceType::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T09:00:00+07:00'), ['Idempotency-Key' => 'booking-123'])
            ->assertCreated();

        $this->postJson(route('appointments.store'), bookingRequest($dealership, $serviceType, $vehicle, '2026-11-05T13:00:00+07:00'), ['Idempotency-Key' => 'booking-123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'Idempotency-Key reused with a different payload.']);

        $this->assertDatabaseCount('appointments', 1);
    });

    it('returns 200 with the cancelled appointment when its Idempotency-Key is replayed after cancelling', function () {
        $dealership = bookableDealership();
        $request = bookingRequest($dealership, ServiceType::factory()->create(), Vehicle::factory()->create(), '2026-11-05T09:00:00+07:00');
        $first = $this->postJson(route('appointments.store'), $request, ['Idempotency-Key' => 'booking-123']);
        $this->patchJson(route('appointments.cancel', $first->json('data.id')))->assertOk();

        $this->postJson(route('appointments.store'), $request, ['Idempotency-Key' => 'booking-123'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseCount('appointments', 1);
    });
});

describe('show', function () {
    it('returns the appointment with its technician, bay, service type, vehicle and both time zones', function () {
        $dealership = Dealership::factory()->create(['name' => 'Keyloop Da Nang', 'timezone' => 'Asia/Ho_Chi_Minh']);
        $technician = Technician::factory()->for($dealership)->create(['name' => 'An Nguyen']);
        $serviceType = ServiceType::factory()->create(['name' => 'Oil change', 'duration_minutes' => 60]);
        $vehicle = Vehicle::factory()->create(['vin' => 'MR0FB8CD6N0123456']);
        $appointment = Appointment::factory()
            ->for($dealership)
            ->for($technician)
            ->for($serviceType)
            ->for($vehicle->customer)
            ->for($vehicle)
            ->create(['starts_at' => '2026-11-05 02:00:00', 'ends_at' => '2026-11-05 03:00:00']);

        $this->getJson(route('appointments.show', $appointment))
            ->assertOk()
            ->assertJsonPath('data.id', $appointment->id)
            ->assertJsonPath('data.status', AppointmentStatus::Confirmed->value)
            ->assertJsonPath('data.starts_at', ['utc' => '2026-11-05T02:00:00Z', 'local' => '2026-11-05T09:00:00+07:00'])
            ->assertJsonPath('data.ends_at', ['utc' => '2026-11-05T03:00:00Z', 'local' => '2026-11-05T10:00:00+07:00'])
            ->assertJsonPath('data.technician', ['id' => $technician->id, 'name' => 'An Nguyen'])
            ->assertJsonPath('data.service_bay.id', $appointment->service_bay_id)
            ->assertJsonPath('data.service_type.name', 'Oil change')
            ->assertJsonPath('data.vehicle.vin', 'MR0FB8CD6N0123456');
    });

    it('returns 404 with a JSON message for an unknown appointment', function () {
        $this->getJson(route('appointments.show', 999))
            ->assertNotFound()
            ->assertExactJson(['message' => 'Appointment not found.']);
    });
});
