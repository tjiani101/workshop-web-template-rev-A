<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    protected $fillable = ['work_order_id', 'booking_item_id', 'recommendation_id', 'part_variant_id', 'stock_reservation_id', 'label_snapshot', 'quantity', 'unit_price_rupiah', 'status', 'actual_minutes', 'mechanic_notes', 'completed_by', 'completed_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price_rupiah' => 'integer', 'actual_minutes' => 'integer', 'completed_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function bookingItem(): BelongsTo
    {
        return $this->belongsTo(BookingItem::class);
    }

    public function stockReservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class);
    }

    public function partVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class);
    }
}
