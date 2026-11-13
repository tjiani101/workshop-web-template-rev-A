<?php

namespace App\Http\Controllers;

use App\Models\AppointmentHistory;
use App\Models\BookingStatusHistory;
use App\Models\Inspection;
use App\Models\InspectionMedia;
use App\Models\PartVariant;
use App\Models\Service;
use App\Models\WorkOrder;
use App\Models\WorkOrderChecklist;
use App\Models\WorkOrderHistory;
use App\Models\WorkOrderItem;
use App\Models\WorkRecommendation;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MechanicWorkOrderController extends Controller
{
    public function show(Request $request, WorkOrder $workOrder): View
    {
        $this->assertAssigned($request, $workOrder);
        $workOrder->load(['booking.items', 'booking.payments', 'appointment.mechanicAssignments.mechanic.user', 'items', 'checklists', 'inspections.findings.media', 'recommendations']);

        return view('work-orders.show', [
            'workOrder' => $workOrder,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'variants' => PartVariant::query()->with('part')->where('is_active', true)->whereHas('part', fn ($query) => $query->where('is_active', true))->orderBy('part_id')->orderBy('name')->get(),
        ]);
    }

    public function updateStatus(Request $request, WorkOrder $workOrder, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $this->assertAssigned($request, $workOrder);
        $data = $request->validate(['status' => ['required', Rule::in(['checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed', 'handed_over'])], 'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'], 'note' => ['nullable', 'string', 'max:2000'], 'actual_work_minutes' => ['nullable', 'integer', 'min:0', 'max:1440']]);
        $transitions = [
            'scheduled' => ['checked_in'],
            'checked_in' => ['inspection'],
            'inspection' => ['waiting_customer_approval', 'in_progress', 'waiting_parts'],
            'waiting_customer_approval' => ['in_progress', 'waiting_parts'],
            'waiting_parts' => ['checked_in', 'in_progress', 'overnight'],
            'in_progress' => ['waiting_customer_approval', 'waiting_parts', 'overnight', 'completed'],
            'overnight' => ['in_progress', 'waiting_parts'],
            'completed' => ['handed_over'],
        ];
        $to = $data['status'];
        if (! in_array($to, $transitions[$workOrder->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'That work-order status transition is not allowed.']);
        }
        if ($to === 'in_progress' && $workOrder->recommendations()->where('customer_decision', 'pending')->exists()) {
            throw ValidationException::withMessages(['status' => 'Wait for the customer to decide on every recommendation before starting additional work.']);
        }
        if ($to === 'completed') {
            if ($workOrder->recommendations()->where('customer_decision', 'pending')->exists()) {
                throw ValidationException::withMessages(['status' => 'Wait for the customer to decide on every recommendation before completing the job.']);
            }
            if ($workOrder->items()->whereNotIn('status', ['completed', 'skipped'])->exists() || $workOrder->checklists()->where('is_complete', false)->exists()) {
                throw ValidationException::withMessages(['status' => 'Complete the work items and checklist before closing the work order.']);
            }
        }
        $netPaid = max(0, (int) $workOrder->booking->amount_paid_rupiah - (int) $workOrder->booking->refund_amount_rupiah);
        if ($to === 'handed_over' && $netPaid < (int) $workOrder->booking->approved_total_rupiah + (int) $workOrder->booking->additional_work_rupiah) {
            throw ValidationException::withMessages(['status' => 'Record verified payment of the outstanding balance before vehicle handover.']);
        }
        $fromStatus = $workOrder->status;

        DB::transaction(function () use ($request, $workOrder, $data, $to, $fromStatus): void {
            $from = $fromStatus;
            $attrs = ['status' => $to];
            if ($to === 'checked_in') {
                $attrs['checked_in_at'] = now();
                $attrs['odometer_km'] = $data['odometer_km'] ?? null;
                $attrs['check_in_notes'] = $data['note'] ?? null;
                if ($workOrder->booking->customer_vehicle_id && $data['odometer_km'] !== null) {
                    $workOrder->booking->customerVehicle()->where('user_id', $workOrder->booking->user_id)->update(['odometer_km' => $data['odometer_km']]);
                }
            }
            if ($to === 'in_progress' && ! $workOrder->started_at) {
                $attrs['started_at'] = now();
            }
            if ($to === 'completed') {
                $attrs['completed_at'] = now();
                $attrs['completion_notes'] = $data['note'] ?? null;
                $attrs['actual_work_minutes'] = $data['actual_work_minutes'] ?? null;
            }
            if ($to === 'handed_over') {
                $attrs['handed_over_at'] = now();
            }
            $workOrder->update($attrs);
            WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => $from, 'to_status' => $to, 'changed_by' => $request->user()->id, 'note' => $data['note'] ?? null]);
            $this->syncAppointmentStatus($workOrder, match ($to) {
                'checked_in' => 'checked_in',
                'inspection' => 'inspection',
                'waiting_customer_approval' => 'waiting_customer_approval',
                'in_progress' => 'in_progress',
                'waiting_parts' => 'waiting_parts',
                'overnight' => 'overnight',
                'completed' => 'completed',
                'handed_over' => 'handed_over',
                default => null,
            }, $request->user()->id, $data['note'] ?? 'Work order status updated.');
        });

        $audit->record($request, 'work_order.status_changed', $workOrder, ['from' => $fromStatus, 'to' => $to]);
        $customer = $workOrder->booking->customer;
        if ($customer) {
            [$title, $body] = match ($to) {
                'checked_in' => ['Motorcycle checked in', 'Your motorcycle is checked in at MINEX.'],
                'inspection' => ['Inspection in progress', 'The mechanic is checking your motorcycle.'],
                'waiting_customer_approval' => ['Your decision is needed', 'The mechanic has added inspection findings or a work recommendation for your review.'],
                'in_progress' => ['Approved work is underway', 'The mechanic is working on the scope you approved.'],
                'waiting_parts' => ['Waiting for parts', 'The workshop is arranging parts for your approved work.'],
                'overnight' => ['Motorcycle staying overnight', 'The work order now records an overnight workshop stay.'],
                'completed' => ['Workshop work completed', 'The mechanic has completed the approved work.'],
                'handed_over' => ['Motorcycle handed over', 'Your motorcycle handover was recorded.'],
                default => ['Workshop update', $workOrder->booking->booking_number.' changed to '.str_replace('_', ' ', $to).'.'],
            };
            $notifications->send($customer, 'work_order_'.$to, $title, $body, route('portal.bookings.show', $workOrder->booking_id), ['booking_id' => $workOrder->booking_id, 'work_order_id' => $workOrder->id]);
        }

        return back()->with('status', 'Work order updated to '.str_replace('_', ' ', $to).'.');
    }

    public function storeFinding(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->assertAssigned($request, $workOrder);
        abort_unless(in_array($workOrder->status, ['checked_in', 'inspection', 'in_progress'], true), 409);
        $data = $request->validate([
            'category' => ['required', Rule::in(['condition', 'damage', 'worn_component', 'oil', 'cvt', 'brakes', 'electrical', 'other'])],
            'severity' => ['required', Rule::in(['observation', 'monitor', 'recommended', 'urgent'])],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:4000'],
            'technical_finding' => ['nullable', 'string', 'max:4000'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $inspection = $workOrder->inspections()->where('status', 'draft')->latest()->first();
        if (! $inspection) {
            $inspection = Inspection::create(['work_order_id' => $workOrder->id, 'mechanic_user_id' => $request->user()->id, 'status' => 'draft']);
        }
        $finding = $inspection->findings()->create(collect($data)->except('photos')->all() + ['created_by' => $request->user()->id]);
        foreach ($request->file('photos', []) as $photo) {
            $path = $photo->store('minex/inspection-media', 'local');
            InspectionMedia::create(['inspection_finding_id' => $finding->id, 'storage_disk' => 'local', 'storage_path' => $path, 'original_name' => Str::limit(basename($photo->getClientOriginalName()), 180, ''), 'mime_type' => $photo->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $photo->getSize(), 'uploaded_by' => $request->user()->id]);
        }
        if ($workOrder->status === 'checked_in') {
            $workOrder->update(['status' => 'inspection']);
            WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => 'checked_in', 'to_status' => 'inspection', 'changed_by' => $request->user()->id, 'note' => 'Inspection findings started.']);
            $this->syncAppointmentStatus($workOrder, 'inspection', $request->user()->id, 'Inspection findings started.');
        }

        return back()->with('status', 'Inspection finding saved with private media storage.');
    }

    public function submitInspection(Request $request, Inspection $inspection, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $workOrder = $inspection->workOrder;
        $this->assertAssigned($request, $workOrder);
        abort_unless($inspection->status === 'draft' && $inspection->findings()->exists(), 409);
        $fromStatus = $workOrder->status;
        $inspection->update(['status' => 'submitted', 'submitted_at' => now(), 'summary' => $request->validate(['summary' => ['nullable', 'string', 'max:4000']])['summary'] ?? null]);
        $workOrder->update(['status' => 'waiting_customer_approval']);
        WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => $fromStatus, 'to_status' => 'waiting_customer_approval', 'changed_by' => $request->user()->id, 'note' => 'Inspection submitted for customer review.']);
        $this->syncAppointmentStatus($workOrder, 'waiting_customer_approval', $request->user()->id, 'Inspection submitted for customer review.');
        $audit->record($request, 'inspection.submitted', $inspection, ['work_order_id' => $workOrder->id]);
        if ($workOrder->booking->customer) {
            $notifications->send($workOrder->booking->customer, 'inspection_submitted', 'Inspection report is ready', 'View the inspection findings and any mechanic recommendations for '.$workOrder->booking->booking_number.'.', route('portal.bookings.show', $workOrder->booking_id), ['booking_id' => $workOrder->booking_id]);
        }

        return back()->with('status', 'Inspection report submitted.');
    }

    public function recommend(Request $request, WorkOrder $workOrder, NotificationService $notifications): RedirectResponse
    {
        $this->assertAssigned($request, $workOrder);
        abort_unless(in_array($workOrder->status, ['inspection', 'in_progress', 'waiting_customer_approval'], true), 409);
        $data = $request->validate([
            'inspection_finding_id' => ['nullable', 'integer', 'exists:inspection_findings,id'],
            'service_id' => ['nullable', 'integer', 'exists:services,id', 'required_without:part_variant_id'],
            'part_variant_id' => ['nullable', 'integer', 'exists:part_variants,id'],
            'fitment_confirmed' => ['nullable', 'boolean'],
            'fitment_note' => ['nullable', 'string', 'max:1000'],
            'title' => ['required', 'string', 'max:180'],
            'reason' => ['required', 'string', 'max:3000'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99'],
            'urgency' => ['required', Rule::in(['optional', 'recommended', 'urgent'])],
        ]);
        if (! empty($data['inspection_finding_id']) && ! $workOrder->inspections()->whereHas('findings', fn ($query) => $query->whereKey($data['inspection_finding_id']))->exists()) {
            throw ValidationException::withMessages(['inspection_finding_id' => 'Select a finding in this work order inspection.']);
        }
        $service = isset($data['service_id']) ? Service::query()->where('is_active', true)->findOrFail($data['service_id']) : null;
        $variant = isset($data['part_variant_id']) ? PartVariant::query()->with(['part', 'compatibleModels'])->where('is_active', true)->whereHas('part', fn ($query) => $query->where('is_active', true))->findOrFail($data['part_variant_id']) : null;
        $fitmentStatus = null;
        if ($variant) {
            $modelId = $workOrder->booking->vehicle_snapshot['vehicle_model_id'] ?? null;
            $fitment = $modelId ? $variant->compatibleModels->firstWhere('id', (int) $modelId) : null;
            $recordedStatus = $fitment?->pivot->compatibility_status ?? 'unverified';
            if ($recordedStatus === 'incompatible') {
                throw ValidationException::withMessages(['part_variant_id' => 'This variant is recorded as incompatible with the motorcycle in this booking.']);
            }
            if ($recordedStatus === 'compatible') {
                $fitmentStatus = 'compatible';
            } else {
                if (! ($data['fitment_confirmed'] ?? false) || blank($data['fitment_note'] ?? null)) {
                    throw ValidationException::withMessages(['fitment_confirmed' => 'Confirm this part fits the customer motorcycle and record what you checked.']);
                }
                $fitmentStatus = 'confirmed';
            }
        }
        $labor = $service?->labor_price_rupiah ?? 0;
        $parts = $variant?->selling_price_rupiah ?? $variant?->part?->selling_price_rupiah ?? 0;
        if ($service && $service->labor_price_rupiah === null || $variant && $variant->selling_price_rupiah === null && $variant->part?->selling_price_rupiah === null) {
            throw ValidationException::withMessages(['service_id' => 'A selected catalog item has no approved price. Ask an administrator to price it before recommending it.']);
        }
        $fromStatus = $workOrder->status;
        $recommendation = WorkRecommendation::create([
            'work_order_id' => $workOrder->id,
            'inspection_finding_id' => $data['inspection_finding_id'] ?? null,
            'service_id' => $service?->id,
            'part_id' => $variant?->part_id,
            'part_variant_id' => $variant?->id,
            'mechanic_user_id' => $request->user()->id,
            'title' => $data['title'],
            'reason' => $data['reason'],
            'quantity' => $data['quantity'],
            'labor_price_rupiah' => $labor,
            'part_price_rupiah' => $parts,
            'urgency' => $data['urgency'],
            'fitment_status' => $fitmentStatus,
            'fitment_note' => $variant ? (($fitmentStatus === 'compatible') ? 'Verified compatible in the catalog for this motorcycle.' : trim($data['fitment_note'])) : null,
            'fitment_confirmed_by' => $variant && $fitmentStatus === 'confirmed' ? $request->user()->id : null,
            'fitment_confirmed_at' => $variant && $fitmentStatus === 'confirmed' ? now() : null,
        ]);
        $workOrder->update(['status' => 'waiting_customer_approval']);
        WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => $fromStatus, 'to_status' => 'waiting_customer_approval', 'changed_by' => $request->user()->id, 'note' => 'Additional work recommendation submitted.']);
        $this->syncAppointmentStatus($workOrder, 'waiting_customer_approval', $request->user()->id, 'Additional work recommendation submitted.');
        if ($workOrder->booking->customer) {
            $notifications->send($workOrder->booking->customer, 'additional_work_recommended', 'The mechanic found another item to discuss', $recommendation->title.' is ready for your decision.', route('portal.bookings.show', $workOrder->booking_id), ['booking_id' => $workOrder->booking_id, 'recommendation_id' => $recommendation->id]);
        }

        return back()->with('status', 'Recommendation and server-priced estimate sent for customer approval.');
    }

    public function updateItem(Request $request, WorkOrder $workOrder, WorkOrderItem $item, InventoryService $inventory): RedirectResponse
    {
        $this->assertAssigned($request, $workOrder);
        abort_unless((int) $item->work_order_id === (int) $workOrder->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['in_progress', 'completed', 'skipped'])], 'actual_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'mechanic_notes' => ['nullable', 'string', 'max:2000']]);
        $allowedFromStatuses = $data['status'] === 'in_progress' ? ['pending'] : ['pending', 'in_progress'];
        abort_unless(in_array($item->status, $allowedFromStatuses, true), 409);
        if ($data['status'] === 'in_progress' && $item->part_variant_id && ! $item->stockReservation) {
            throw ValidationException::withMessages(['status' => 'Reserve the required part before starting this item.']);
        }
        DB::transaction(function () use ($request, $item, $data, $inventory): void {
            $reservation = $item->stockReservation;
            if ($data['status'] === 'completed') {
                if ($item->part_variant_id && ! $reservation) {
                    throw ValidationException::withMessages(['status' => 'The required part has not been reserved in inventory.']);
                }
                if ($reservation) {
                    $inventory->consumeReservation($reservation, $request->user());
                }
            } elseif ($reservation?->status === 'active') {
                $inventory->release($reservation, $request->user(), 'Work item was marked skipped.');
            }
            $item->update([
                'status' => $data['status'],
                'actual_minutes' => $data['actual_minutes'] ?? $item->actual_minutes,
                'mechanic_notes' => $data['mechanic_notes'] ?? $item->mechanic_notes,
                'completed_by' => $data['status'] === 'in_progress' ? $item->completed_by : $request->user()->id,
                'completed_at' => in_array($data['status'], ['completed', 'skipped'], true) ? now() : null,
            ]);
        });

        return back()->with('status', 'Work item updated.');
    }

    public function updateChecklist(Request $request, WorkOrder $workOrder, WorkOrderChecklist $checklist): RedirectResponse
    {
        $this->assertAssigned($request, $workOrder);
        abort_unless((int) $checklist->work_order_id === (int) $workOrder->id, 404);
        $checklist->update(['is_complete' => $request->boolean('is_complete'), 'completed_by' => $request->boolean('is_complete') ? $request->user()->id : null, 'completed_at' => $request->boolean('is_complete') ? now() : null]);

        return back()->with('status', 'Checklist saved.');
    }

    public function media(Request $request, InspectionMedia $media)
    {
        $media->load('finding.inspection.workOrder.appointment.mechanicAssignments.mechanic');
        $workOrder = $media->finding->inspection->workOrder;
        $this->assertAssigned($request, $workOrder);
        abort_unless(Storage::disk($media->storage_disk)->exists($media->storage_path), 404);

        return Storage::disk($media->storage_disk)->response($media->storage_path, $media->original_name, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function assertAssigned(Request $request, WorkOrder $workOrder): void
    {
        abort_unless($request->user()->mechanicProfile && $workOrder->appointment()->whereHas('mechanicAssignments', fn ($query) => $query->where('mechanic_profile_id', $request->user()->mechanicProfile->id))->exists(), 404);
    }

    private function syncAppointmentStatus(WorkOrder $workOrder, ?string $status, ?int $actorId, ?string $note): void
    {
        $bookingStatus = match ($status) {
            'checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight' => $status,
            'completed' => 'completed',
            default => null,
        };
        $booking = $workOrder->booking;
        if ($bookingStatus && $booking->usesAutomaticStatusControl() && $booking->status !== $bookingStatus) {
            $fromBookingStatus = $booking->status;
            $booking->update(['status' => $bookingStatus]);
            BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $fromBookingStatus, 'to_status' => $bookingStatus, 'changed_by' => $actorId, 'note' => $note ?: 'Booking status updated from a workshop event.', 'metadata' => ['work_order_id' => $workOrder->id, 'event_driven' => true]]);
        }

        $appointment = $workOrder->appointment;
        if (! $appointment || ! $status || $appointment->status === $status) {
            return;
        }
        $appointment->update(['status' => $status, 'overnight' => $status === 'overnight' || $appointment->overnight]);
        AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => $status, 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => $note, 'changed_by' => $actorId]);
    }
}
