<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = ['expense_number', 'category', 'description', 'amount_rupiah', 'expense_date', 'payment_method', 'reference', 'receipt_disk', 'receipt_path', 'entered_by', 'status', 'voided_by', 'voided_at', 'void_reason'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'expense_date' => 'date', 'voided_at' => 'datetime'];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
