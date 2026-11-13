<?php

namespace App\Models;

use Database\Factories\EngineBreakInProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EngineBreakInProgram extends Model
{
    /** @use HasFactory<EngineBreakInProgramFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'mechanic_profile_id', 'status', 'estimated_fuel_budget_rupiah', 'approved_fuel_budget_rupiah',
        'pending_fuel_budget_rupiah', 'pending_budget_note', 'budget_approved_at', 'customer_consent_at',
        'customer_consent_version', 'sop_reference', 'started_at', 'completed_at', 'start_odometer_km',
        'end_odometer_km', 'procedure_notes', 'final_inspection_notes', 'final_inspection_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_fuel_budget_rupiah' => 'integer',
            'approved_fuel_budget_rupiah' => 'integer',
            'pending_fuel_budget_rupiah' => 'integer',
            'budget_approved_at' => 'datetime',
            'customer_consent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'final_inspection_at' => 'datetime',
            'start_odometer_km' => 'integer',
            'end_odometer_km' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function mechanicProfile(): BelongsTo
    {
        return $this->belongsTo(MechanicProfile::class);
    }

    public function fuelExpenses(): HasMany
    {
        return $this->hasMany(EngineBreakInFuelExpense::class)->orderBy('purchased_at');
    }

    public function fuelPayments(): HasMany
    {
        return $this->hasMany(PaymentRecord::class)->where('payment_kind', 'engine_break_in_fuel')->latest('submitted_at');
    }
}
