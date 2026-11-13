<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_level_id', 'movement_type', 'quantity_delta', 'physical_after', 'reserved_after', 'reference_type', 'reference_id', 'reason', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:2', 'physical_after' => 'decimal:2', 'reserved_after' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
