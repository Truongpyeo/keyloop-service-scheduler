<?php

namespace App\Models;

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
}
