<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    protected $fillable = ['part_variant_id', 'booking_id', 'work_recommendation_id', 'quantity', 'status', 'created_by', 'released_at', 'consumed_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'released_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(WorkRecommendation::class, 'work_recommendation_id');
    }
}
