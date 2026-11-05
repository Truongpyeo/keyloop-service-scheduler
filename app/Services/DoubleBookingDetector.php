<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DoubleBookingDetector
{
    /**
     * Pairs of confirmed appointments that overlap in time while sharing a technician, a bay or a vehicle.
     *
     * Plain SQL self-join, independent of the booking code it is meant to check. An empty result means no double booking.
     *
     * @return Collection<int, object{first_id: int, second_id: int, shared_resource: string}>
     */
    public function overlappingPairs(): Collection
    {
        $confirmed = AppointmentStatus::Confirmed->value;

        return collect(DB::select(<<<'SQL'
            SELECT a.id AS first_id,
                   b.id AS second_id,
                   CASE
                       WHEN a.technician_id = b.technician_id THEN 'technician'
                       WHEN a.service_bay_id = b.service_bay_id THEN 'service_bay'
                       ELSE 'vehicle'
                   END AS shared_resource
            FROM appointments a
            JOIN appointments b ON a.id < b.id
            WHERE a.status = ?
              AND b.status = ?
              AND a.starts_at < b.ends_at
              AND b.starts_at < a.ends_at
              AND (a.technician_id = b.technician_id
                   OR a.service_bay_id = b.service_bay_id
                   OR a.vehicle_id = b.vehicle_id)
            ORDER BY a.id, b.id
            SQL, [$confirmed, $confirmed]));
    }
}
