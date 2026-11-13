<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    protected $fillable = ['inventory_location_id', 'part_variant_id', 'physical_quantity', 'reserved_quantity', 'reorder_level', 'purchase_cost_rupiah'];

    protected function casts(): array
    {
        return ['physical_quantity' => 'decimal:2', 'reserved_quantity' => 'decimal:2', 'reorder_level' => 'decimal:2', 'purchase_cost_rupiah' => 'integer'];
    }

    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->physical_quantity - (float) $this->reserved_quantity;
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class, 'part_variant_id');
    }
}
