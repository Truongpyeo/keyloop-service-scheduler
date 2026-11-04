<?php

namespace App\Models;

use Database\Factories\TechnicianSkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['technician_id', 'skill'])]
class TechnicianSkill extends Model
{
    /** @use HasFactory<TechnicianSkillFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Technician, $this>
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
