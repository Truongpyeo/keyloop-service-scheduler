<?php

namespace App\Http\Resources;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $this->dealership->timezone;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'starts_at' => $this->bothTimezones($this->starts_at, $timezone),
            'ends_at' => $this->bothTimezones($this->ends_at, $timezone),
            'timezone' => $timezone,
            'customer_id' => $this->customer_id,
            'dealership' => [
                'id' => $this->dealership->id,
                'name' => $this->dealership->name,
            ],
            'vehicle' => [
                'id' => $this->vehicle->id,
                'vin' => $this->vehicle->vin,
                'make' => $this->vehicle->make,
                'model' => $this->vehicle->model,
                'year' => $this->vehicle->year,
            ],
            'service_type' => [
                'id' => $this->serviceType->id,
                'name' => $this->serviceType->name,
                'duration_minutes' => $this->serviceType->duration_minutes,
            ],
            'technician' => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
            ],
            'service_bay' => [
                'id' => $this->serviceBay->id,
                'name' => $this->serviceBay->name,
            ],
        ];
    }

    /**
     * @return array{utc: string, local: string}
     */
    private function bothTimezones(CarbonImmutable $instant, string $timezone): array
    {
        return [
            'utc' => $instant->toIso8601ZuluString(),
            'local' => $instant->setTimezone($timezone)->toIso8601String(),
        ];
    }
}
