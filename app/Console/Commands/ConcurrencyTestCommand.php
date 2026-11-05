<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use App\Services\DoubleBookingDetector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('app:concurrency-test
    {--date= : Local date to book on, YYYY-MM-DD (default: 7 days from today in the dealership timezone)}
    {--url=http://localhost:8000 : Base URL of the running API}
    {--force : Required, because the command first deletes the dealership\'s appointments on that date}')]
#[Description('Fire parallel booking requests at the running API and check the database for double bookings (local only)')]
class ConcurrencyTestCommand extends Command
{
    private const DEALERSHIP_ID = 1;

    private const FIXTURE_VIN_PREFIX = 'CTEST';

    /**
     * Run the three race scenarios against the live API and the real database.
     */
    public function handle(DoubleBookingDetector $detector): int
    {
        if (! app()->isLocal()) {
            $this->components->error('app:concurrency-test only runs in the local environment.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->components->error('This command deletes the dealership\'s appointments on the test date. Re-run with --force.');

            return self::FAILURE;
        }

        $dealership = Dealership::findOrFail(self::DEALERSHIP_ID);
        $evBatteryCheck = ServiceType::where('name', 'EV battery check')->firstOrFail();
        $oilChange = ServiceType::where('name', 'Oil change')->firstOrFail();
        $day = $this->testDay($dealership);

        if ($day === null || ! $this->seedMatchesScenarios($dealership)) {
            return self::FAILURE;
        }

        [$dayStartsAt, $dayEndsAt] = $dealership->localDayInUtc($day);
        $deleted = Appointment::query()
            ->whereBelongsTo($dealership)
            ->where('starts_at', '>=', $dayStartsAt)
            ->where('starts_at', '<', $dayEndsAt)
            ->delete();

        $this->components->info(sprintf(
            'Testing %s (%s) against %s; removed %d existing appointment(s) on that day.',
            $day->toDateString(), $dealership->timezone, $this->option('url'), $deleted,
        ));

        $fixtureVehicles = $this->fixtureVehicles(10);

        $results = [
            $this->competeForOneTechnician($detector, $dealership, $evBatteryCheck, $fixtureVehicles, $day->setTime(9, 0)),
            $this->competeForBays($detector, $dealership, $oilChange, $day->setTime(13, 0)),
            $this->replaySameIdempotencyKey($detector, $dealership, $oilChange, $fixtureVehicles->first(), $day->setTime(15, 0)),
        ];

        $this->newLine();
        $this->table(
            ['Scenario', 'Requests', '201 / 200 / 409 / 503 / other', 'Appointments in DB', 'Double booking?', 'Result'],
            array_map(fn (array $result): array => [
                $result['scenario'],
                $result['requests'],
                implode(' / ', [$result['statuses'][201], $result['statuses'][200], $result['statuses'][409], $result['statuses'][503], $result['statuses']['other']]),
                $result['appointments'],
                $result['double_booked'] ? 'YES' : 'no',
                $result['passed'] ? 'PASS' : 'FAIL',
            ], $results),
        );

        return collect($results)->every(fn (array $result): bool => $result['passed']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A: ten different vehicles want the only EV technician at the same time; exactly one may win.
     *
     * @param  Collection<int, Vehicle>  $vehicles
     * @return array<string, mixed>
     */
    private function competeForOneTechnician(DoubleBookingDetector $detector, Dealership $dealership, ServiceType $serviceType, Collection $vehicles, CarbonImmutable $startsAt): array
    {
        $tally = $this->tally($this->sendInParallel(
            $vehicles->map(fn (Vehicle $vehicle): array => $this->bookingPayload($dealership, $serviceType, $vehicle, $startsAt))->all(),
        ));
        $appointments = $this->confirmedAppointmentsStartingAt($dealership, $startsAt);
        $doubleBooked = $this->reportDoubleBookings($detector);

        $passed = $tally['statuses'][201] === 1
            && $tally['statuses'][409] === $vehicles->count() - 1
            && $appointments === 1
            && ! $doubleBooked;

        return $this->reportScenario('A. one EV technician', $vehicles->count(), $tally, $appointments, $doubleBooked, $passed);
    }

    /**
     * B: all five demo vehicles want an oil change at the same time; four technicians but only three bays.
     *
     * @return array<string, mixed>
     */
    private function competeForBays(DoubleBookingDetector $detector, Dealership $dealership, ServiceType $serviceType, CarbonImmutable $startsAt): array
    {
        $vehicles = Vehicle::query()
            ->where('vin', 'not like', self::FIXTURE_VIN_PREFIX.'%')
            ->orderBy('id')
            ->take(5)
            ->get();

        $tally = $this->tally($this->sendInParallel(
            $vehicles->map(fn (Vehicle $vehicle): array => $this->bookingPayload($dealership, $serviceType, $vehicle, $startsAt))->all(),
        ));
        $appointments = $this->confirmedAppointmentsStartingAt($dealership, $startsAt);
        $doubleBooked = $this->reportDoubleBookings($detector);

        $passed = $vehicles->count() === 5
            && $tally['statuses'][201] === 3
            && $tally['statuses'][409] === 2
            && ($tally['reasons']['no_bay_available'] ?? 0) === 2
            && $appointments === 3
            && ! $doubleBooked;

        return $this->reportScenario('B. three bays', $vehicles->count(), $tally, $appointments, $doubleBooked, $passed);
    }

    /**
     * C: ten identical requests share one Idempotency-Key; exactly one appointment may exist and every response must point to it.
     *
     * @return array<string, mixed>
     */
    private function replaySameIdempotencyKey(DoubleBookingDetector $detector, Dealership $dealership, ServiceType $serviceType, Vehicle $vehicle, CarbonImmutable $startsAt): array
    {
        $idempotencyKey = 'concurrency-test-'.$startsAt->toDateString();
        $payload = $this->bookingPayload($dealership, $serviceType, $vehicle, $startsAt);

        $tally = $this->tally($this->sendInParallel(array_fill(0, 10, $payload), ['Idempotency-Key' => $idempotencyKey]));
        $stored = Appointment::query()->where('idempotency_key', $idempotencyKey)->get();
        $doubleBooked = $this->reportDoubleBookings($detector);

        $passed = $stored->count() === 1
            && $tally['statuses'][201] + $tally['statuses'][200] === 10
            && $tally['appointment_ids'] === [$stored->first()->id]
            && ! $doubleBooked;

        return $this->reportScenario('C. same Idempotency-Key', 10, $tally, $stored->count(), $doubleBooked, $passed);
    }

    /**
     * Send every payload at once (Http::pool runs them concurrently) to POST /api/appointments.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @param  array<string, string>  $headers
     * @return list<Response|Throwable>
     */
    private function sendInParallel(array $payloads, array $headers = []): array
    {
        $url = rtrim((string) $this->option('url'), '/').'/api/appointments';

        return array_values(Http::pool(fn (Pool $pool): array => array_map(
            fn (array $payload) => $pool->acceptJson()->timeout(60)->withHeaders($headers)->post($url, $payload),
            $payloads,
        )));
    }

    /**
     * @param  list<Response|Throwable>  $responses
     * @return array{statuses: array<int|string, int>, reasons: array<string, int>, appointment_ids: list<int>, errors: list<string>}
     */
    private function tally(array $responses): array
    {
        $tally = ['statuses' => [201 => 0, 200 => 0, 409 => 0, 503 => 0, 'other' => 0], 'reasons' => [], 'appointment_ids' => [], 'errors' => []];

        foreach ($responses as $response) {
            if (! $response instanceof Response) {
                $tally['statuses']['other']++;
                $tally['errors'][] = $response->getMessage();

                continue;
            }

            $status = $response->status();
            array_key_exists($status, $tally['statuses']) ? $tally['statuses'][$status]++ : $tally['statuses']['other']++;

            if ($status === 409) {
                $reason = (string) $response->json('reason');
                $tally['reasons'][$reason] = ($tally['reasons'][$reason] ?? 0) + 1;
            } elseif (in_array($status, [200, 201], true)) {
                $tally['appointment_ids'][] = (int) $response->json('data.id');
            } elseif ($status !== 503) {
                $tally['errors'][] = "HTTP {$status}: ".mb_strimwidth($response->body(), 0, 200, '…');
            }
        }

        $tally['appointment_ids'] = array_values(array_unique($tally['appointment_ids']));

        return $tally;
    }

    /**
     * Print one scenario's outcome and return its summary row.
     *
     * @param  array{statuses: array<int|string, int>, reasons: array<string, int>, appointment_ids: list<int>, errors: list<string>}  $tally
     * @return array{scenario: string, requests: int, statuses: array<int|string, int>, appointments: int, double_booked: bool, passed: bool}
     */
    private function reportScenario(string $scenario, int $requests, array $tally, int $appointments, bool $doubleBooked, bool $passed): array
    {
        $this->newLine();
        $this->components->twoColumnDetail("<options=bold>{$scenario}</>", $passed ? '<fg=green;options=bold>PASS</>' : '<fg=red;options=bold>FAIL</>');
        $this->components->twoColumnDetail('Responses', sprintf(
            '201×%d  200×%d  409×%d  503×%d  other×%d',
            $tally['statuses'][201], $tally['statuses'][200], $tally['statuses'][409], $tally['statuses'][503], $tally['statuses']['other'],
        ));
        $this->components->twoColumnDetail('409 reasons', $tally['reasons'] === [] ? '-' : collect($tally['reasons'])->map(fn (int $count, string $reason) => "{$reason}×{$count}")->implode('  '));
        $this->components->twoColumnDetail('Appointment ids returned', $tally['appointment_ids'] === [] ? '-' : implode(', ', $tally['appointment_ids']));
        $this->components->twoColumnDetail('Appointments in DB', (string) $appointments);
        $this->components->twoColumnDetail('Double bookings in DB', $doubleBooked ? '<fg=red>YES</>' : 'none');

        foreach (array_unique($tally['errors']) as $error) {
            $this->components->warn($error);
        }

        return [
            'scenario' => $scenario,
            'requests' => $requests,
            'statuses' => $tally['statuses'],
            'appointments' => $appointments,
            'double_booked' => $doubleBooked,
            'passed' => $passed,
        ];
    }

    /**
     * Run the self-join check and print any overlapping pairs it finds.
     */
    private function reportDoubleBookings(DoubleBookingDetector $detector): bool
    {
        $pairs = $detector->overlappingPairs();

        foreach ($pairs as $pair) {
            $this->components->error("Appointments {$pair->first_id} and {$pair->second_id} overlap on the same {$pair->shared_resource}.");
        }

        return $pairs->isNotEmpty();
    }

    private function confirmedAppointmentsStartingAt(Dealership $dealership, CarbonImmutable $startsAt): int
    {
        return Appointment::query()->whereBelongsTo($dealership)->confirmed()->where('starts_at', $startsAt->utc())->count();
    }

    /**
     * @return array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}
     */
    private function bookingPayload(Dealership $dealership, ServiceType $serviceType, Vehicle $vehicle, CarbonImmutable $startsAt): array
    {
        return [
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'dealership_id' => $dealership->id,
            'service_type_id' => $serviceType->id,
            'starts_at' => $startsAt->toIso8601String(),
        ];
    }

    /**
     * The local test date from --date, or 7 days from today; null (with an error printed) when invalid or not in the future.
     */
    private function testDay(Dealership $dealership): ?CarbonImmutable
    {
        $today = CarbonImmutable::now($dealership->timezone)->startOfDay();
        $date = $this->option('date');

        if ($date === null) {
            return $today->addDays(7);
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', (string) $date, $dealership->timezone);
        } catch (Throwable) {
            $day = null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date || ! $day->isAfter($today)) {
            $this->components->error('--date must be a future date in YYYY-MM-DD format.');

            return null;
        }

        return $day;
    }

    /**
     * The scenarios' expected counts assume the demo seed: one EV technician, four general technicians and three bays.
     */
    private function seedMatchesScenarios(Dealership $dealership): bool
    {
        $evTechnicians = Technician::query()->whereBelongsTo($dealership)->withSkill('ev')->count();
        $generalTechnicians = Technician::query()->whereBelongsTo($dealership)->withSkill('general')->count();
        $serviceBays = ServiceBay::query()->whereBelongsTo($dealership)->count();
        $demoVehicles = Vehicle::query()->where('vin', 'not like', self::FIXTURE_VIN_PREFIX.'%')->count();

        if ($evTechnicians === 1 && $generalTechnicians === 4 && $serviceBays === 3 && $demoVehicles >= 5) {
            return true;
        }

        $this->components->error(sprintf(
            'Expected the demo seed (1 EV technician, 4 general technicians, 3 bays, 5 vehicles); found %d, %d, %d, %d. Reset with: php artisan migrate:fresh --seed',
            $evTechnicians, $generalTechnicians, $serviceBays, $demoVehicles,
        ));

        return false;
    }

    /**
     * Vehicles owned by a dedicated test customer, created once and reused, so scenario A has ten distinct vehicles.
     *
     * @return Collection<int, Vehicle>
     */
    private function fixtureVehicles(int $count): Collection
    {
        $customer = Customer::firstOrCreate(
            ['email' => 'concurrency-test@example.com'],
            ['name' => 'Concurrency Test', 'phone' => '+84900000000'],
        );

        return collect(range(1, $count))->map(fn (int $number): Vehicle => Vehicle::firstOrCreate(
            ['vin' => sprintf('%s%012d', self::FIXTURE_VIN_PREFIX, $number)],
            ['customer_id' => $customer->id, 'make' => 'Test', 'model' => 'Concurrency', 'year' => 2024],
        ));
    }
}
