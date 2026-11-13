<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentRecord extends Model
{
    protected $table = 'payments';

    protected $fillable = ['booking_id', 'engine_break_in_program_id', 'payment_reference', 'payment_kind', 'method', 'amount_rupiah', 'status', 'customer_reference', 'proof_disk', 'proof_path', 'customer_note', 'submitted_by', 'submitted_at'];

    protected function casts(): array
    {
        return ['amount_rupiah' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function engineBreakInProgram(): BelongsTo
    {
        return $this->belongsTo(EngineBreakInProgram::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PaymentVerification::class, 'payment_id');
    }
}
