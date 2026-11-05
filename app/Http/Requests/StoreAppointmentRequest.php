<?php

namespace App\Http\Requests;

use App\Enums\BookingFailureReason;
use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceType;
use App\Models\Vehicle;
use App\Services\AvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    /**
     * ISO 8601 date-time that must carry an explicit UTC offset or "Z".
     */
    private const ISO_8601_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    private BookingFailureReason $failureReason = BookingFailureReason::ValidationFailed;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists(Customer::class, 'id')],
            'vehicle_id' => ['required', 'integer', Rule::exists(Vehicle::class, 'id')],
            'dealership_id' => ['required', 'integer', Rule::exists(Dealership::class, 'id')],
            'service_type_id' => ['required', 'integer', Rule::exists(ServiceType::class, 'id')],
            'starts_at' => ['required', 'string', 'regex:'.self::ISO_8601_WITH_OFFSET, 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.regex' => 'The starts at field must be an ISO 8601 date-time with a UTC offset or Z, e.g. 2026-11-05T09:00:00+07:00.',
        ];
    }

    /**
     * Business rules that need the referenced records; they run only once the payload itself is well-formed.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (strlen((string) $this->idempotencyKey()) > 255) {
                    $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must not be longer than 255 characters.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateBookingRules($validator);
            },
        ];
    }

    /**
     * The optional Idempotency-Key header, with an empty value treated as absent.
     */
    public function idempotencyKey(): ?string
    {
        $idempotencyKey = $this->header('Idempotency-Key');

        return $idempotencyKey === null || $idempotencyKey === '' ? null : $idempotencyKey;
    }

    /**
     * Log the rejected booking before Laravel turns the failure into a 422 response.
     */
    protected function failedValidation(Validator $validator): void
    {
        Log::warning('booking.failed', [
            'reason' => $this->failureReason->value,
            'dealership_id' => $this->input('dealership_id'),
            'service_type_id' => $this->input('service_type_id'),
            'starts_at' => $this->input('starts_at'),
            'errors' => array_keys($validator->errors()->messages()),
        ]);

        parent::failedValidation($validator);
    }

    private function validateBookingRules(Validator $validator): void
    {
        if (Vehicle::find($this->integer('vehicle_id'))->customer_id !== $this->integer('customer_id')) {
            $validator->errors()->add('vehicle_id', 'The selected vehicle does not belong to the customer.');
        }

        $dealership = Dealership::findOrFail($this->integer('dealership_id'));
        $serviceType = ServiceType::findOrFail($this->integer('service_type_id'));
        $startsAt = CarbonImmutable::parse((string) $this->input('starts_at'));
        $localStartsAt = $startsAt->setTimezone($dealership->timezone);

        if ($localStartsAt->minute % AvailabilityService::SLOT_MINUTES !== 0 || $localStartsAt->second !== 0 || $localStartsAt->microsecond !== 0) {
            $validator->errors()->add('starts_at', 'The starts at field must fall on a 30-minute boundary in the dealership\'s local time.');
        }

        if (! $startsAt->isAfter(CarbonImmutable::now())) {
            $validator->errors()->add('starts_at', 'The starts at field must be in the future.');
        }

        if (! $dealership->isOpenThroughout($startsAt, $startsAt->addMinutes($serviceType->duration_minutes))) {
            $this->failureReason = BookingFailureReason::OutsideOpeningHours;

            $validator->errors()->add('starts_at', sprintf(
                'The service must start at or after %s and finish by %s (%s).',
                substr($dealership->opens_at, 0, 5),
                substr($dealership->closes_at, 0, 5),
                $dealership->timezone,
            ));
        }
    }
}
