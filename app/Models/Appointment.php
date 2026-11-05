<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id',
    'vehicle_id',
    'dealership_id',
    'service_type_id',
    'technician_id',
    'service_bay_id',
    'starts_at',
    'ends_at',
    'status',
    'idempotency_key',
    'request_hash',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Dealership, $this>
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    /**
     * @return BelongsTo<ServiceType, $this>
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * @return BelongsTo<Technician, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * @return BelongsTo<ServiceBay, $this>
     */
    public function serviceBay(): BelongsTo
    {
        return $this->belongsTo(ServiceBay::class);
    }

    /**
     * Limit the query to appointments that still hold their resources.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function confirmed(Builder $query): void
    {
        $query->where('status', AppointmentStatus::Confirmed);
    }

    /**
     * Limit the query to appointments overlapping [startsAt, endsAt).
     *
     * Appointments never span more than one local day, so the extra lower bound on starts_at
     * is always safe and keeps the scan on the (resource_id, starts_at) indexes short.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt->utc())
            ->where('starts_at', '>', $startsAt->utc()->subDay())
            ->where('ends_at', '>', $startsAt->utc());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
        ];
    }
}
