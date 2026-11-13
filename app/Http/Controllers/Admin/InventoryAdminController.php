<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PartVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\WorkOrderItem;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InventoryAdminController extends Controller
{
    public function index(): View
    {
        $variants = PartVariant::query()->with(['part.brand', 'stockLevel'])->whereHas('part')->orderBy('part_id')->orderBy('name')->paginate(40);

        return view('admin.inventory', compact('variants'));
    }

    public function receive(Request $request, PartVariant $variant, InventoryService $inventory, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'unit_cost_rupiah' => ['required', 'integer', 'min:0'], 'supplier_reference' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $inventory->receive($variant, (float) $data['quantity'], (int) $data['unit_cost_rupiah'], $request->user(), $data['supplier_reference'] ?? null, $data['notes'] ?? null);
        $audit->record($request, 'inventory.received', $variant, ['quantity' => (string) $data['quantity'], 'unit_cost_rupiah' => (int) $data['unit_cost_rupiah']]);

        return back()->with('status', 'Stock receipt recorded and physical stock updated.');
    }

    public function update(Request $request, PartVariant $variant, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['quantity_delta' => ['required', 'numeric', 'not_in:0', 'between:-100000,100000'], 'reorder_level' => ['required', 'numeric', 'min:0', 'max:1000000'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $variant, $data): void {
            $level = StockLevel::query()->where('part_variant_id', $variant->id)->lockForUpdate()->firstOrFail();
            $newPhysical = (float) $level->physical_quantity + (float) $data['quantity_delta'];
            if ($newPhysical < (float) $level->reserved_quantity) {
                abort(422, 'This adjustment would reduce physical stock below its reserved quantity.');
            }
            $level->physical_quantity = $newPhysical;
            $level->reorder_level = (float) $data['reorder_level'];
            $level->save();
            StockMovement::create(['stock_level_id' => $level->id, 'movement_type' => 'adjustment', 'quantity_delta' => $data['quantity_delta'], 'physical_after' => $level->physical_quantity, 'reserved_after' => $level->reserved_quantity, 'reference_type' => 'admin_adjustment', 'reason' => $data['reason'], 'created_by' => $request->user()->id]);
        });
        $audit->record($request, 'inventory.adjusted', $variant, ['quantity_delta' => (string) $data['quantity_delta'], 'reason' => $data['reason']]);

        return back()->with('status', 'Stock adjustment and reason saved in the movement history.');
    }

    public function reserveWorkOrderItem(Request $request, WorkOrderItem $item, InventoryService $inventory, AuditLogger $audit): RedirectResponse
    {
        abort_unless($item->part_variant_id && ! $item->stock_reservation_id && $item->status === 'pending', 409);
        $reservation = $inventory->reserve($item->partVariant()->firstOrFail(), (float) $item->quantity, $request->user(), $item->workOrder()->firstOrFail()->booking()->firstOrFail());
        $item->update(['stock_reservation_id' => $reservation->id]);
        $audit->record($request, 'inventory.work_order_reserved', $item, ['stock_reservation_id' => $reservation->id, 'quantity' => (string) $item->quantity]);

        return back()->with('status', 'Stock reserved for this approved work-order item.');
    }
}
