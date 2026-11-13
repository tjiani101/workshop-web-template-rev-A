<?php

namespace App\Models;

use Database\Factories\EngineBreakInFuelExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineBreakInFuelExpense extends Model
{
    /** @use HasFactory<EngineBreakInFuelExpenseFactory> */
    use HasFactory;

    protected $fillable = [
        'engine_break_in_program_id', 'purchased_at', 'quantity_liters', 'expense_rupiah',
        'customer_contribution_rupiah', 'minex_subsidy_rupiah', 'receipt_path', 'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
            'quantity_liters' => 'decimal:3',
            'expense_rupiah' => 'integer',
            'customer_contribution_rupiah' => 'integer',
            'minex_subsidy_rupiah' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EngineBreakInProgram::class, 'engine_break_in_program_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
