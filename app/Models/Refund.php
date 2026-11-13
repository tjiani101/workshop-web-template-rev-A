<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $fillable = ['booking_id', 'amount_rupiah', 'method', 'status', 'reference', 'reason', 'processing_note', 'processed_by', 'processed_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'processed_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
