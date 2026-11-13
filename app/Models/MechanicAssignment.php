<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MechanicAssignment extends Model
{
    protected $fillable = ['appointment_id', 'mechanic_profile_id', 'assigned_minutes', 'specialization_override', 'override_reason', 'assigned_by'];

    protected function casts(): array
    {
        return ['assigned_minutes' => 'integer', 'specialization_override' => 'boolean'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(MechanicProfile::class, 'mechanic_profile_id');
    }
}
