<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockReceipt extends Model
{
    public $timestamps = false;

    protected $fillable = ['stock_level_id', 'quantity', 'unit_cost_rupiah', 'supplier_reference', 'notes', 'received_by', 'received_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_cost_rupiah' => 'integer', 'received_at' => 'datetime'];
    }
}
