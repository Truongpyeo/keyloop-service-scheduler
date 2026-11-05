<?php

namespace App\Models;

use Database\Factories\TechnicianFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['dealership_id', 'name'])]
class Technician extends Model
{
    /** @use HasFactory<TechnicianFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Dealership, $this>
     */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    /**
     * @return HasMany<TechnicianSkill, $this>
     */
    public function skills(): HasMany
    {
        return $this->hasMany(TechnicianSkill::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Limit the query to technicians who have the given skill.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withSkill(Builder $query, string $skill): void
    {
        $query->whereHas('skills', fn (Builder $skills) => $skills->where('skill', $skill));
    }
}
