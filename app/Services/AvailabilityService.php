<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Dealership;
use App\Models\ServiceBay;
use App\Models\ServiceType;
use App\Models\Technician;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Bookings start on this grid of minutes, measured in the dealership's local time.
     */
    public const SLOT_MINUTES = 30;

    /**
     * List the start times on one local day where a skilled technician and a bay are both free for the whole service.
     *
     * Uses at most three queries (technicians, bays, appointments) and computes overlaps in memory.
     *
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    public function slotsFor(Dealership $dealership, ServiceType $serviceType, CarbonImmutable $day): array
    {
        $technicianIds = Technician::query()
            ->whereBelongsTo($dealership)
            ->withSkill($serviceType->required_skill)
            ->pluck('id');

        if ($technicianIds->isEmpty()) {
            return [];
        }

        $serviceBayIds = ServiceBay::query()->whereBelongsTo($dealership)->pluck('id');

        if ($serviceBayIds->isEmpty()) {
            return [];
        }

        [$dayStartsAt, $dayEndsAt] = $dealership->localDayInUtc($day);

        $appointments = Appointment::query()
            ->whereBelongsTo($dealership)
            ->confirmed()
            ->overlapping($dayStartsAt, $dayEndsAt)
            ->get(['technician_id', 'service_bay_id', 'starts_at', 'ends_at']);

        $now = CarbonImmutable::now();
        $closesAt = $dealership->closesOn($day);
        $slots = [];

        for (
            $slotStartsAt = $dealership->opensOn($day);
            ($slotEndsAt = $slotStartsAt->addMinutes($serviceType->duration_minutes))->lessThanOrEqualTo($closesAt);
            $slotStartsAt = $slotStartsAt->addMinutes(self::SLOT_MINUTES)
        ) {
            if ($slotStartsAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $busy = $appointments->filter(
                fn (Appointment $appointment) => $appointment->starts_at->lessThan($slotEndsAt)
                    && $appointment->ends_at->greaterThan($slotStartsAt)
            );

            if ($this->hasFree($technicianIds, $busy->pluck('technician_id'))
                && $this->hasFree($serviceBayIds, $busy->pluck('service_bay_id'))) {
                $slots[] = ['starts_at' => $slotStartsAt, 'ends_at' => $slotEndsAt];
            }
        }

        return $slots;
    }

    /**
     * @param  Collection<int, int>  $resourceIds
     * @param  Collection<int, int>  $busyIds
     */
    private function hasFree(Collection $resourceIds, Collection $busyIds): bool
    {
        return $resourceIds->diff($busyIds)->isNotEmpty();
    }
}
