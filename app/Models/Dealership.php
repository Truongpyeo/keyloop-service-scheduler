<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DealershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'opens_at', 'closes_at'])]
class Dealership extends Model
{
    /** @use HasFactory<DealershipFactory> */
    use HasFactory;

    /**
     * @return HasMany<Technician, $this>
     */
    public function technicians(): HasMany
    {
        return $this->hasMany(Technician::class);
    }

    /**
     * @return HasMany<ServiceBay, $this>
     */
    public function serviceBays(): HasMany
    {
        return $this->hasMany(ServiceBay::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Opening time on the local date of the given instant, in the dealership's timezone.
     */
    public function opensOn(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimezone($this->timezone)->setTimeFromTimeString($this->opens_at);
    }

    /**
     * Closing time on the local date of the given instant, in the dealership's timezone.
     */
    public function closesOn(CarbonImmutable $day): CarbonImmutable
    {
        return $day->setTimezone($this->timezone)->setTimeFromTimeString($this->closes_at);
    }

    /**
     * Whether a service starting and ending at the given instants fits inside one local day's opening hours.
     */
    public function isOpenThroughout(CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return $startsAt->greaterThanOrEqualTo($this->opensOn($startsAt))
            && $endsAt->lessThanOrEqualTo($this->closesOn($startsAt));
    }

    /**
     * The local calendar day containing the given instant, as a [start, end) range in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function localDayInUtc(CarbonImmutable $day): array
    {
        $startOfDay = $day->setTimezone($this->timezone)->startOfDay();

        return [$startOfDay->utc(), $startOfDay->addDay()->utc()];
    }
}
