<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;

class AppointmentController extends Controller
{
    /**
     * Relations every appointment response includes.
     */
    public const RESPONSE_RELATIONS = ['dealership', 'vehicle', 'serviceType', 'technician', 'serviceBay'];

    /**
     * Book an appointment: 201 when created, 200 when an Idempotency-Key replay returns the earlier booking.
     */
    public function store(StoreAppointmentRequest $request, BookingService $bookings): JsonResponse
    {
        $result = $bookings->book($request->validated(), $request->idempotencyKey());

        return AppointmentResource::make($result->appointment->load(self::RESPONSE_RELATIONS))
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }

    /**
     * Show one appointment with its technician, bay, service type and vehicle.
     */
    public function show(Appointment $appointment): AppointmentResource
    {
        return AppointmentResource::make($appointment->load(self::RESPONSE_RELATIONS));
    }
}
