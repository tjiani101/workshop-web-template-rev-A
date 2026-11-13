<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingItem extends Model
{
    protected $fillable = [
        'booking_id', 'service_id', 'package_id', 'part_id', 'part_variant_id', 'item_type', 'label_snapshot',
        'description_snapshot', 'quantity', 'labor_price_rupiah', 'unit_price_rupiah', 'discount_rupiah',
        'line_total_rupiah', 'duration_minutes', 'catalog_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'labor_price_rupiah' => 'integer',
            'unit_price_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'line_total_rupiah' => 'integer',
            'catalog_snapshot' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function partVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class);
    }
}
