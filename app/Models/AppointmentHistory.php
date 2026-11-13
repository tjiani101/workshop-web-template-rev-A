<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['appointment_id', 'event_type', 'previous_start_at', 'previous_end_at', 'new_start_at', 'new_end_at', 'note', 'changed_by', 'created_at'];

    protected function casts(): array
    {
        return ['previous_start_at' => 'datetime', 'previous_end_at' => 'datetime', 'new_start_at' => 'datetime', 'new_end_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
