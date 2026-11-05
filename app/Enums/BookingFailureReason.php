<?php

namespace App\Enums;

enum BookingFailureReason: string
{
    case ValidationFailed = 'validation_failed';
    case OutsideOpeningHours = 'outside_opening_hours';
    case IdempotencyKeyMismatch = 'idempotency_key_mismatch';
    case VehicleAlreadyBooked = 'vehicle_already_booked';
    case NoSkilledTechnician = 'no_skilled_technician';
    case NoTechnicianAvailable = 'no_technician_available';
    case NoBayAvailable = 'no_bay_available';

    /**
     * Human-readable explanation returned to API clients.
     */
    public function message(): string
    {
        return match ($this) {
            self::ValidationFailed => 'The booking request is invalid.',
            self::OutsideOpeningHours => 'The service must start and finish within the dealership opening hours.',
            self::IdempotencyKeyMismatch => 'Idempotency-Key reused with a different payload.',
            self::VehicleAlreadyBooked => 'The vehicle already has an appointment that overlaps this time.',
            self::NoSkilledTechnician => 'No technician at this dealership has the skill this service requires.',
            self::NoTechnicianAvailable => 'No skilled technician is free for the whole requested time.',
            self::NoBayAvailable => 'No service bay is free for the whole requested time.',
        };
    }
}
