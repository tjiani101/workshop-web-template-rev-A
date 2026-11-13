<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingChangeRequest extends Model
{
    protected $fillable = ['booking_id', 'requester_id', 'request_type', 'requested_start_at', 'customer_note', 'status', 'reviewed_by', 'reviewed_at', 'admin_note'];

    protected function casts(): array
    {
        return ['requested_start_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
