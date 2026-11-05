<?php

namespace App\Services;

use App\Models\Appointment;

class BookingResult
{
    /**
     * @param  bool  $wasCreated  False when an Idempotency-Key replay returned an existing appointment.
     */
    public function __construct(
        public readonly Appointment $appointment,
        public readonly bool $wasCreated,
    ) {}
}
