<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $fillable = ['booking_id', 'appointment_id', 'status', 'odometer_km', 'check_in_notes', 'completion_notes', 'actual_work_minutes', 'checked_in_at', 'started_at', 'completed_at', 'handed_over_at', 'created_by'];

    protected function casts(): array
    {
        return ['odometer_km' => 'integer', 'actual_work_minutes' => 'integer', 'checked_in_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'handed_over_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(WorkRecommendation::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(WorkOrderChecklist::class)->orderBy('sort_order');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WorkOrderHistory::class)->orderBy('created_at');
    }
}
