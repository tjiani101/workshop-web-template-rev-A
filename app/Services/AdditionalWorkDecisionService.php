<?php

namespace App\Services;

use App\Models\AppointmentHistory;
use App\Models\Booking;
use App\Models\RecommendationDecisionHistory;
use App\Models\User;
use App\Models\WorkOrderHistory;
use App\Models\WorkOrderItem;
use App\Models\WorkRecommendation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdditionalWorkDecisionService
{
    public function decide(Booking $booking, int $recommendationId, string $decision, ?User $actor, ?string $note = null, bool $adminOverride = false): WorkRecommendation
    {
        return DB::transaction(function () use ($booking, $recommendationId, $decision, $actor, $note, $adminOverride): WorkRecommendation {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            abort_unless($lockedBooking->approved_total_rupiah !== null, 409);
            $workOrder = $lockedBooking->workOrder()->lockForUpdate()->firstOrFail();
            $recommendation = WorkRecommendation::query()->where('work_order_id', $workOrder->id)->lockForUpdate()->findOrFail($recommendationId);
            $previousDecision = $recommendation->customer_decision;

            if (in_array($workOrder->status, ['completed', 'handed_over'], true)) {
                throw ValidationException::withMessages(['decision' => 'This work order is closed. Ask MINEX to create a follow-up request.']);
            }

            if ($previousDecision === $decision) {
                return $recommendation->fresh();
            }

            if ($decision === 'accepted' && $recommendation->part_variant_id && ! in_array($recommendation->fitment_status, ['compatible', 'confirmed'], true)) {
                throw ValidationException::withMessages(['decision' => 'The mechanic must confirm this part fits your motorcycle before you can approve it.']);
            }

            $latestItem = $recommendation->workOrderItems()->latest('id')->lockForUpdate()->first();
            $item = $latestItem && in_array($latestItem->status, ['pending', 'in_progress'], true) ? $latestItem : null;
            if ($latestItem && ! $item && ! in_array($latestItem->status, ['cancelled_by_customer', 'cancelled_by_admin'], true)) {
                throw ValidationException::withMessages(['decision' => 'Work has already been recorded for this item. Contact an administrator if the record needs correction.']);
            }
            if ($item?->status === 'in_progress' && ! $adminOverride) {
                throw ValidationException::withMessages(['decision' => 'Work has started on this item. Contact MINEX if the decision needs to change.']);
            }
            if ($item && ! in_array($item->status, ['pending', 'in_progress'], true)) {
                throw ValidationException::withMessages(['decision' => 'This item can no longer be changed.']);
            }
            if ($adminOverride && blank($note)) {
                throw ValidationException::withMessages(['decision_note' => 'Record a reason for the administrator override.']);
            }

            if ($previousDecision === 'accepted' && $decision === 'declined') {
                if ($item?->stockReservation?->status === 'reserved') {
                    app(InventoryService::class)->release($item->stockReservation, $actor, 'Approved additional work was declined before completion.');
                }
                $item?->update([
                    'status' => $adminOverride ? 'cancelled_by_admin' : 'cancelled_by_customer',
                    'mechanic_notes' => trim(implode("\n", array_filter([$item->mechanic_notes, $note]))),
                ]);
            }

            if ($decision === 'accepted' && $previousDecision !== 'accepted') {
                $reservation = $recommendation->part_variant_id
                    ? app(InventoryService::class)->reserve($recommendation->partVariant()->firstOrFail(), (float) $recommendation->quantity, $actor, null, $recommendation->id)
                    : null;
                $unitPrice = (int) $recommendation->labor_price_rupiah + (int) $recommendation->part_price_rupiah;
                WorkOrderItem::create([
                    'work_order_id' => $recommendation->work_order_id,
                    'recommendation_id' => $recommendation->id,
                    'part_variant_id' => $recommendation->part_variant_id,
                    'stock_reservation_id' => $reservation?->id,
                    'label_snapshot' => $recommendation->title,
                    'quantity' => $recommendation->quantity,
                    'unit_price_rupiah' => $unitPrice,
                    'status' => 'pending',
                ]);
            }

            $delta = 0;
            if ($previousDecision === 'accepted') {
                $delta -= $recommendation->estimated_total_rupiah;
            }
            if ($decision === 'accepted') {
                $delta += $recommendation->estimated_total_rupiah;
            }
            $lockedBooking->update(['additional_work_rupiah' => max(0, (int) $lockedBooking->additional_work_rupiah + $delta)]);

            $recommendation->update([
                'customer_decision' => $decision,
                'decided_by' => $actor?->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            RecommendationDecisionHistory::create([
                'work_recommendation_id' => $recommendation->id,
                'from_decision' => $previousDecision,
                'to_decision' => $decision,
                'acted_by' => $actor?->id,
                'actor_type' => $adminOverride ? 'admin' : ($actor ? 'customer' : 'guest'),
                'note' => $note,
            ]);

            if ($workOrder->status === 'waiting_customer_approval' && ! $workOrder->recommendations()->where('customer_decision', 'pending')->exists()) {
                $workOrder->update(['status' => 'in_progress', 'started_at' => $workOrder->started_at ?? now()]);
                WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => 'waiting_customer_approval', 'to_status' => 'in_progress', 'changed_by' => $actor?->id, 'note' => 'Decisions were recorded for all additional work recommendations.']);
                $appointment = $workOrder->appointment;
                if ($appointment && $appointment->status !== 'in_progress') {
                    $appointment->update(['status' => 'in_progress']);
                    AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'in_progress', 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => 'Customer decisions received for all recommendations.', 'changed_by' => $actor?->id]);
                }
            }

            return $recommendation->fresh(['workOrder']);
        });
    }
}
