<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = ['booking_id', 'scheduled_start_at', 'scheduled_end_at', 'status', 'reserved_until', 'overnight', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['scheduled_start_at' => 'datetime', 'scheduled_end_at' => 'datetime', 'reserved_until' => 'datetime', 'overnight' => 'boolean'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function mechanicAssignments(): HasMany
    {
        return $this->hasMany(MechanicAssignment::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentHistory::class)->orderBy('created_at');
    }
}
