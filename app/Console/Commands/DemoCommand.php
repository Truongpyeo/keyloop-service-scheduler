<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceType;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

#[Signature('app:demo
    {--url=http://localhost:8000 : Base URL of the running API}')]
#[Description('Walk through the booking flow over the REST API for tomorrow, printing every request and response')]
class DemoCommand extends Command
{
    private const DEALERSHIP_ID = 1;

    private const DEMO_VIN_PREFIX = 'DEMA';

    private int $failedSteps = 0;

    /**
     * Run availability → book → replay → conflict → invalid input → cancel → availability against the live API.
     */
    public function handle(): int
    {
        $dealership = Dealership::findOrFail(self::DEALERSHIP_ID);
        $evBatteryCheck = ServiceType::where('name', 'EV battery check')->firstOrFail();
        [$bookingVehicle, $competingVehicle] = $this->demoVehicles();
        $tomorrow = CarbonImmutable::now($dealership->timezone)->addDay()->toDateString();
        $availabilityPath = "/api/dealerships/{$dealership->id}/availability?service_type_id={$evBatteryCheck->id}&date={$tomorrow}";
        $idempotencyKey = 'demo-'.Str::uuid();

        $this->components->info("Demo against {$this->option('url')} for {$tomorrow} ({$dealership->timezone}), service: {$evBatteryCheck->name}.");

        try {
            $availability = $this->step(1, 'List free EV slots for tomorrow', 'GET', $availabilityPath, expectedStatus: 200);
            $slot = $availability->json('data.slots.0.starts_at');

            if ($slot === null) {
                $this->components->error('No free EV slot tomorrow; nothing to book.');

                return self::FAILURE;
            }

            $booking = $this->bookingPayload($dealership, $evBatteryCheck, $bookingVehicle, $slot);

            $created = $this->step(2, "Book the first free slot ({$slot})", 'POST', '/api/appointments', $booking, ['Idempotency-Key' => $idempotencyKey], 201);
            $appointmentId = $created->json('data.id');

            if ($appointmentId === null) {
                $this->components->error('The booking was not created, so the remaining steps cannot run.');

                return self::FAILURE;
            }

            $replayed = $this->step(3, 'Send the same request again with the same Idempotency-Key', 'POST', '/api/appointments', $booking, ['Idempotency-Key' => $idempotencyKey], 200);
            $this->check($replayed->json('data.id') === $appointmentId, "Replay returned the same appointment id ({$appointmentId}).");

            $conflict = $this->step(4, 'Book the same slot for another vehicle (the only EV technician is now busy)', 'POST', '/api/appointments', $this->bookingPayload($dealership, $evBatteryCheck, $competingVehicle, $slot), expectedStatus: 409);
            $this->check($conflict->json('reason') === 'no_technician_available', 'Conflict reason is no_technician_available.');

            $this->step(5, 'Send starts_at without a UTC offset', 'POST', '/api/appointments', [...$booking, 'starts_at' => substr($slot, 0, 19)], expectedStatus: 422);

            $cancelled = $this->step(6, 'Cancel the appointment', 'PATCH', "/api/appointments/{$appointmentId}/cancel", expectedStatus: 200);
            $this->check($cancelled->json('data.status') === 'cancelled', 'Appointment status is cancelled.');

            $freedAvailability = $this->step(7, 'List free EV slots again', 'GET', $availabilityPath, expectedStatus: 200);
            $this->check(collect($freedAvailability->json('data.slots'))->contains('starts_at', $slot), "Slot {$slot} is free again.");
        } catch (ConnectionException $exception) {
            $this->components->error("Cannot reach the API at {$this->option('url')}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();

        if ($this->failedSteps > 0) {
            $this->components->error("{$this->failedSteps} check(s) did not match the expected result.");

            return self::FAILURE;
        }

        $this->components->info('All demo steps returned the expected results.');

        return self::SUCCESS;
    }

    /**
     * Send one request and print it with its status and a shortened body.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $headers
     */
    private function step(int $number, string $title, string $method, string $path, ?array $body = null, array $headers = [], int $expectedStatus = 200): Response
    {
        $this->newLine();
        $this->line("<options=bold>Step {$number}. {$title}</>");
        $this->line("  → {$method} {$path}".collect($headers)->map(fn (string $value, string $name) => "  [{$name}: {$value}]")->implode(''));

        if ($body !== null) {
            $this->line('    '.json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $response = Http::baseUrl(rtrim((string) $this->option('url'), '/'))
            ->acceptJson()
            ->withHeaders($headers)
            ->timeout(30)
            ->send($method, $path, $body === null ? [] : ['json' => $body]);

        $this->check($response->status() === $expectedStatus, "← HTTP {$response->status()} (expected {$expectedStatus})");
        $this->line('    '.Str::limit($response->body(), 300));

        return $response;
    }

    private function check(bool $passed, string $message): void
    {
        if (! $passed) {
            $this->failedSteps++;
        }

        $this->line(($passed ? '  <fg=green>✔</> ' : '  <fg=red>✘</> ').$message);
    }

    /**
     * @return array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}
     */
    private function bookingPayload(Dealership $dealership, ServiceType $serviceType, Vehicle $vehicle, string $startsAt): array
    {
        return [
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'dealership_id' => $dealership->id,
            'service_type_id' => $serviceType->id,
            'starts_at' => $startsAt,
        ];
    }

    /**
     * Two vehicles owned by a dedicated demo customer, created once and reused, so the demo never touches
     * the seeded vehicles or the app:concurrency-test fixtures.
     *
     * @return array{0: Vehicle, 1: Vehicle}
     */
    private function demoVehicles(): array
    {
        $customer = Customer::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo Customer', 'phone' => '+84900000001'],
        );

        return array_map(fn (int $number): Vehicle => Vehicle::firstOrCreate(
            ['vin' => sprintf('%s%013d', self::DEMO_VIN_PREFIX, $number)],
            ['customer_id' => $customer->id, 'make' => 'VinFast', 'model' => 'VF 8', 'year' => 2024],
        ), [1, 2]);
    }
}
