<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\BookingService;

class CancelAppointmentController extends Controller
{
    /**
     * Cancel the appointment; cancelling an already cancelled appointment also returns 200.
     */
    public function __invoke(Appointment $appointment, BookingService $bookings): AppointmentResource
    {
        return AppointmentResource::make(
            $bookings->cancel($appointment)->load(AppointmentController::RESPONSE_RELATIONS)
        );
    }
}
