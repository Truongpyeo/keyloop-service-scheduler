<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\BookingFailureReason;
use App\Exceptions\BookingConflictException;
use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Book the service, or return the earlier result when the Idempotency-Key was already used for the same request.
     *
     * @param  array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}  $details
     *
     * @throws BookingConflictException when no vehicle, technician or bay slot is free (409)
     * @throws ValidationException when the Idempotency-Key was used for a different payload (422)
     */
    public function book(array $details, ?string $idempotencyKey): BookingResult
    {
        $dealership = Dealership::findOrFail($details['dealership_id']);
        $serviceType = ServiceType::findOrFail($details['service_type_id']);
        $startsAt = CarbonImmutable::parse($details['starts_at'])->utc();
        $endsAt = $startsAt->addMinutes($serviceType->duration_minutes);
        $requestHash = $this->requestHash($details, $startsAt);
        $logContext = [
            'dealership_id' => $dealership->id,
            'service_type_id' => $serviceType->id,
            'vehicle_id' => (int) $details['vehicle_id'],
            'starts_at' => $startsAt->toIso8601ZuluString(),
        ];

        try {
            $result = DB::transaction(fn (): BookingResult => $this->bookWithinTransaction(
                $details, $dealership, $serviceType, $startsAt, $endsAt, $idempotencyKey, $requestHash, $logContext,
            ), 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey === null) {
                throw $exception;
            }

            // A concurrent request with the same key committed first; answer as if this one were its replay.
            $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            $result = $this->replay($existing, $requestHash, $logContext);
        }

        if ($result->wasCreated) {
            Log::info('booking.succeeded', [
                ...$logContext,
                'appointment_id' => $result->appointment->id,
                'technician_id' => $result->appointment->technician_id,
                'service_bay_id' => $result->appointment->service_bay_id,
            ]);
        }

        return $result;
    }

    /**
     * Cancel the appointment, freeing its technician and bay. Cancelling twice is a no-op.
     */
    public function cancel(Appointment $appointment): Appointment
    {
        if ($appointment->status === AppointmentStatus::Cancelled) {
            return $appointment;
        }

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        Log::info('booking.cancelled', ['appointment_id' => $appointment->id]);

        return $appointment;
    }

    /**
     * Lock the candidate rows (always technicians, then bays, then the vehicle, each by ascending id, so concurrent
     * bookings cannot deadlock each other), re-check overlaps with locking reads, then insert.
     *
     * @param  array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}  $details
     * @param  array<string, mixed>  $logContext
     */
    private function bookWithinTransaction(
        array $details,
        Dealership $dealership,
        ServiceType $serviceType,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $idempotencyKey,
        string $requestHash,
        array $logContext,
    ): BookingResult {
        $technicianIds = Technician::query()
            ->whereBelongsTo($dealership)
            ->withSkill($serviceType->required_skill)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        $serviceBayIds = ServiceBay::query()
            ->whereBelongsTo($dealership)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        Vehicle::query()->whereKey($details['vehicle_id'])->lockForUpdate()->firstOrFail();

        if ($idempotencyKey !== null) {
            $existing = Appointment::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($existing !== null) {
                return $this->replay($existing, $requestHash, $logContext);
            }
        }

        if ($this->overlappingIds('vehicle_id', collect([$details['vehicle_id']]), $startsAt, $endsAt)->isNotEmpty()) {
            $this->fail(BookingFailureReason::VehicleAlreadyBooked, $logContext);
        }

        if ($technicianIds->isEmpty()) {
            $this->fail(BookingFailureReason::NoSkilledTechnician, $logContext);
        }

        $freeTechnicianIds = $technicianIds->diff($this->overlappingIds('technician_id', $technicianIds, $startsAt, $endsAt));

        if ($freeTechnicianIds->isEmpty()) {
            $this->fail(BookingFailureReason::NoTechnicianAvailable, $logContext);
        }

        $freeServiceBayIds = $serviceBayIds->diff($this->overlappingIds('service_bay_id', $serviceBayIds, $startsAt, $endsAt));

        if ($freeServiceBayIds->isEmpty()) {
            $this->fail(BookingFailureReason::NoBayAvailable, $logContext);
        }

        $appointment = Appointment::create([
            'customer_id' => $details['customer_id'],
            'vehicle_id' => $details['vehicle_id'],
            'dealership_id' => $dealership->id,
            'service_type_id' => $serviceType->id,
            'technician_id' => $this->leastBusyTechnician($dealership, $freeTechnicianIds, $startsAt),
            'service_bay_id' => $freeServiceBayIds->min(),
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'status' => AppointmentStatus::Confirmed,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $idempotencyKey === null ? null : $requestHash,
        ]);

        return new BookingResult($appointment, wasCreated: true);
    }

    /**
     * IDs in the given column of confirmed appointments overlapping the window, read with a locking (current) read so
     * rows committed by a transaction we just waited for are visible under REPEATABLE READ.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function overlappingIds(string $column, Collection $ids, CarbonImmutable $startsAt, CarbonImmutable $endsAt): Collection
    {
        return Appointment::query()
            ->confirmed()
            ->whereIn($column, $ids)
            ->overlapping($startsAt, $endsAt)
            ->lockForUpdate()
            ->pluck($column);
    }

    /**
     * The free technician with the fewest confirmed appointments on that local day; ties go to the lowest id.
     *
     * @param  Collection<int, int>  $freeTechnicianIds
     */
    private function leastBusyTechnician(Dealership $dealership, Collection $freeTechnicianIds, CarbonImmutable $startsAt): int
    {
        [$dayStartsAt, $dayEndsAt] = $dealership->localDayInUtc($startsAt);

        $appointmentCounts = Appointment::query()
            ->confirmed()
            ->whereIn('technician_id', $freeTechnicianIds)
            ->where('starts_at', '>=', $dayStartsAt)
            ->where('starts_at', '<', $dayEndsAt)
            ->groupBy('technician_id')
            ->selectRaw('technician_id, count(*) as appointment_count')
            ->pluck('appointment_count', 'technician_id');

        return $freeTechnicianIds
            ->sort(fn (int $a, int $b): int => [(int) ($appointmentCounts[$a] ?? 0), $a] <=> [(int) ($appointmentCounts[$b] ?? 0), $b])
            ->first();
    }

    /**
     * Return the stored appointment for a replayed Idempotency-Key, refusing a key reused for a different request.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function replay(Appointment $existing, string $requestHash, array $logContext): BookingResult
    {
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            Log::warning('booking.failed', [...$logContext, 'reason' => BookingFailureReason::IdempotencyKeyMismatch->value]);

            throw ValidationException::withMessages([
                'idempotency_key' => BookingFailureReason::IdempotencyKeyMismatch->message(),
            ]);
        }

        Log::info('booking.replayed', [...$logContext, 'appointment_id' => $existing->id]);

        return new BookingResult($existing, wasCreated: false);
    }

    /**
     * @param  array<string, mixed>  $logContext
     *
     * @throws BookingConflictException
     */
    private function fail(BookingFailureReason $reason, array $logContext): never
    {
        Log::warning('booking.failed', [...$logContext, 'reason' => $reason->value]);

        throw new BookingConflictException($reason);
    }

    /**
     * SHA-256 of the canonical request: keys sorted, ids as integers and starts_at normalised to UTC,
     * so "09:00+07:00" and "02:00Z" produce the same hash.
     *
     * @param  array{customer_id: int, vehicle_id: int, dealership_id: int, service_type_id: int, starts_at: string}  $details
     */
    private function requestHash(array $details, CarbonImmutable $startsAt): string
    {
        $canonical = [
            'customer_id' => (int) $details['customer_id'],
            'dealership_id' => (int) $details['dealership_id'],
            'service_type_id' => (int) $details['service_type_id'],
            'starts_at' => $startsAt->utc()->toIso8601ZuluString(),
            'vehicle_id' => (int) $details['vehicle_id'],
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
