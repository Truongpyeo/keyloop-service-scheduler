<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowAvailabilityRequest;
use App\Models\Dealership;
use App\Models\ServiceType;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DealershipAvailabilityController extends Controller
{
    /**
     * List bookable start times for a service type on one local date at the dealership.
     */
    public function __invoke(ShowAvailabilityRequest $request, Dealership $dealership, AvailabilityService $availability): JsonResponse
    {
        $serviceType = ServiceType::findOrFail($request->integer('service_type_id'));
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $request->string('date')->toString(), $dealership->timezone);

        $slots = $availability->slotsFor($dealership, $serviceType, $day);

        return response()->json([
            'data' => [
                'dealership_id' => $dealership->id,
                'service_type_id' => $serviceType->id,
                'date' => $day->toDateString(),
                'timezone' => $dealership->timezone,
                'slots' => array_map(fn (array $slot): array => [
                    'starts_at' => $slot['starts_at']->toIso8601String(),
                    'ends_at' => $slot['ends_at']->toIso8601String(),
                ], $slots),
            ],
        ]);
    }
}
