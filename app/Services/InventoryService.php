<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\InventoryLocation;
use App\Models\PartVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function reserve(PartVariant $variant, float $quantity, ?User $actor, ?Booking $booking = null, ?int $recommendationId = null): StockReservation
    {
        return DB::transaction(function () use ($variant, $quantity, $actor, $booking, $recommendationId): StockReservation {
            $location = InventoryLocation::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main workshop stock', 'is_active' => true]);
            $level = StockLevel::query()->firstOrCreate(['part_variant_id' => $variant->id], ['inventory_location_id' => $location->id, 'physical_quantity' => 0, 'reserved_quantity' => 0, 'reorder_level' => 0]);
            $level = StockLevel::query()->lockForUpdate()->findOrFail($level->id);
            if ($quantity <= 0 || (float) $level->physical_quantity - (float) $level->reserved_quantity < $quantity) {
                throw ValidationException::withMessages(['stock' => 'There is not enough available stock for '.$variant->part->name.' ('.$variant->name.').']);
            }
            $level->reserved_quantity = (float) $level->reserved_quantity + $quantity;
            $level->save();
            $reservation = StockReservation::create(['part_variant_id' => $variant->id, 'booking_id' => $booking?->id, 'work_recommendation_id' => $recommendationId, 'quantity' => $quantity, 'status' => 'reserved', 'created_by' => $actor?->id]);
            StockMovement::create(['stock_level_id' => $level->id, 'movement_type' => 'reservation', 'quantity_delta' => 0, 'physical_after' => $level->physical_quantity, 'reserved_after' => $level->reserved_quantity, 'reference_type' => $booking ? 'booking' : 'recommendation', 'reference_id' => $booking?->id ?? $recommendationId, 'reason' => 'Stock held for approved work.', 'created_by' => $actor?->id]);

            return $reservation;
        });
    }

    public function release(StockReservation $reservation, User|string|null $actor, string $reason): void
    {
        DB::transaction(function () use ($reservation, $actor, $reason): void {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'reserved') {
                return;
            }
            $level = StockLevel::query()->where('part_variant_id', $reservation->part_variant_id)->lockForUpdate()->firstOrFail();
            $level->reserved_quantity = max(0, (float) $level->reserved_quantity - (float) $reservation->quantity);
            $level->save();
            $reservation->update(['status' => 'released', 'released_at' => now()]);
            StockMovement::create(['stock_level_id' => $level->id, 'movement_type' => 'release', 'quantity_delta' => 0, 'physical_after' => $level->physical_quantity, 'reserved_after' => $level->reserved_quantity, 'reference_type' => 'reservation', 'reference_id' => $reservation->id, 'reason' => $reason, 'created_by' => $actor instanceof User ? $actor->id : null]);
        });
    }

    public function releaseBooking(Booking $booking, ?User $actor, string $reason): void
    {
        $recommendationIds = $booking->workOrder()->first()?->recommendations()->pluck('id') ?? collect();
        StockReservation::query()->where('status', 'reserved')
            ->where(fn ($query) => $query->where('booking_id', $booking->id)->when($recommendationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('work_recommendation_id', $recommendationIds)))
            ->get()->each(fn (StockReservation $reservation) => $this->release($reservation, $actor, $reason));
    }

    public function consumeReservation(StockReservation $reservation, User $actor): void
    {
        DB::transaction(function () use ($reservation, $actor): void {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'reserved') {
                throw ValidationException::withMessages(['stock' => 'This stock reservation is no longer available to consume.']);
            }
            $level = StockLevel::query()->where('part_variant_id', $reservation->part_variant_id)->lockForUpdate()->firstOrFail();
            if ((float) $level->physical_quantity < (float) $reservation->quantity || (float) $level->reserved_quantity < (float) $reservation->quantity) {
                throw ValidationException::withMessages(['stock' => 'Reserved stock does not match the available physical balance.']);
            }
            $level->physical_quantity = (float) $level->physical_quantity - (float) $reservation->quantity;
            $level->reserved_quantity = (float) $level->reserved_quantity - (float) $reservation->quantity;
            $level->save();
            $reservation->update(['status' => 'consumed', 'consumed_at' => now()]);
            StockMovement::create(['stock_level_id' => $level->id, 'movement_type' => 'consumption', 'quantity_delta' => -1 * (float) $reservation->quantity, 'physical_after' => $level->physical_quantity, 'reserved_after' => $level->reserved_quantity, 'reference_type' => 'reservation', 'reference_id' => $reservation->id, 'reason' => 'Recorded actual part use on work order.', 'created_by' => $actor->id]);
        });
    }

    public function receive(PartVariant $variant, float $quantity, int $unitCost, User $actor, ?string $supplierReference = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($variant, $quantity, $unitCost, $actor, $supplierReference, $notes): void {
            if ($quantity <= 0 || $unitCost < 0) {
                throw ValidationException::withMessages(['quantity' => 'Enter a positive received quantity and non-negative unit cost.']);
            }
            $location = InventoryLocation::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main workshop stock', 'is_active' => true]);
            $level = StockLevel::query()->firstOrCreate(['part_variant_id' => $variant->id], ['inventory_location_id' => $location->id, 'physical_quantity' => 0, 'reserved_quantity' => 0, 'reorder_level' => 0]);
            $level = StockLevel::query()->lockForUpdate()->findOrFail($level->id);
            $level->physical_quantity = (float) $level->physical_quantity + $quantity;
            $level->purchase_cost_rupiah = $unitCost;
            $level->save();
            $receipt = StockReceipt::create(['stock_level_id' => $level->id, 'quantity' => $quantity, 'unit_cost_rupiah' => $unitCost, 'supplier_reference' => $supplierReference, 'notes' => $notes, 'received_by' => $actor->id]);
            StockMovement::create(['stock_level_id' => $level->id, 'movement_type' => 'receipt', 'quantity_delta' => $quantity, 'physical_after' => $level->physical_quantity, 'reserved_after' => $level->reserved_quantity, 'reference_type' => 'receipt', 'reference_id' => $receipt->id, 'reason' => $notes, 'created_by' => $actor->id]);
        });
    }
}
