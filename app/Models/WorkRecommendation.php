<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkRecommendation extends Model
{
    protected $fillable = ['work_order_id', 'inspection_finding_id', 'service_id', 'part_id', 'part_variant_id', 'mechanic_user_id', 'title', 'reason', 'quantity', 'labor_price_rupiah', 'part_price_rupiah', 'urgency', 'fitment_status', 'fitment_note', 'fitment_confirmed_by', 'fitment_confirmed_at', 'customer_decision', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'labor_price_rupiah' => 'integer', 'part_price_rupiah' => 'integer', 'decided_at' => 'datetime', 'fitment_confirmed_at' => 'datetime'];
    }

    public function getEstimatedTotalRupiahAttribute(): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $this->quantity, 2), 2, '0');
        $hundredths = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        $unit = (int) $this->labor_price_rupiah + (int) $this->part_price_rupiah;

        return intdiv(($hundredths * $unit) + 50, 100);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(InspectionFinding::class, 'inspection_finding_id');
    }

    public function partVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class);
    }

    public function mechanicUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mechanic_user_id');
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'recommendation_id');
    }

    public function decisionHistory(): HasMany
    {
        return $this->hasMany(RecommendationDecisionHistory::class, 'work_recommendation_id')->orderBy('created_at');
    }
}
