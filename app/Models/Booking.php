<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_number', 'reference', 'submission_token', 'user_id', 'customer_vehicle_id', 'customer_type',
        'customer_name', 'customer_email', 'customer_phone', 'vehicle_snapshot', 'status', 'payment_status',
        'status_control_mode',
        'payment_percentage', 'payment_method', 'subtotal_rupiah', 'discount_rupiah', 'approved_total_rupiah',
        'additional_work_rupiah', 'amount_paid_rupiah', 'refund_amount_rupiah', 'estimated_duration_minutes',
        'customer_notes', 'admin_notes', 'submitted_at', 'approved_at', 'payment_deadline_at', 'preferred_start_at', 'operation_consent_at', 'has_unpriced_items', 'requires_fitment_confirmation', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'vehicle_snapshot' => 'array',
            'subtotal_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'approved_total_rupiah' => 'integer',
            'additional_work_rupiah' => 'integer',
            'amount_paid_rupiah' => 'integer',
            'refund_amount_rupiah' => 'integer',
            'has_unpriced_items' => 'boolean',
            'requires_fitment_confirmation' => 'boolean',
            'is_demo' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'payment_deadline_at' => 'datetime',
            'preferred_start_at' => 'datetime',
            'operation_consent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customerVehicle(): BelongsTo
    {
        return $this->belongsTo(CustomerVehicle::class, 'customer_vehicle_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)->orderBy('id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->orderBy('created_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderByDesc('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function workOrder(): HasOne
    {
        return $this->hasOne(WorkOrder::class);
    }

    public function engineBreakInProgram(): HasOne
    {
        return $this->hasOne(EngineBreakInProgram::class);
    }

    public function engineBuildConfiguration(): HasOne
    {
        return $this->hasOne(EngineBuildConfiguration::class);
    }

    public function requiresFullDayReservation(): bool
    {
        return $this->items->contains(fn (BookingItem $item): bool => (bool) data_get($item->catalog_snapshot, 'service.requires_full_day'));
    }

    public function isBoreUpBooking(): bool
    {
        return $this->items->contains(fn (BookingItem $item): bool => str_starts_with((string) data_get($item->catalog_snapshot, 'package.slug'), 'bore-up-'));
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(BookingChangeRequest::class)->latest();
    }

    public function usesAutomaticStatusControl(): bool
    {
        $mode = $this->status_control_mode ?? BusinessSetting::value('booking_status_mode', ['mode' => 'automatic'])['mode'] ?? 'automatic';

        return $mode === 'automatic';
    }
}
