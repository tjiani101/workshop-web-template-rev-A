<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\BusinessSetting;
use App\Models\MechanicProfile;
use App\Models\PartVariant;
use App\Models\WorkOrderHistory;
use App\Models\WorkRecommendation;
use App\Services\AdditionalWorkDecisionService;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\WorkshopCapacityService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingAdminController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_approval', 'awaiting_payment', 'confirmed', 'scheduled', 'checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed', 'cancelled', 'no_show', 'rejected', 'refunded'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $status = $filters['status'] ?? null;
        $query = trim($filters['q'] ?? '');
        $bookings = Booking::query()->with('customer')->when($status, fn ($builder) => $builder->where('status', $status))
            ->when($query !== '', fn ($builder) => $builder->where(function ($search) use ($query): void {
                $search->where('booking_number', 'like', '%'.$query.'%')
                    ->orWhere('customer_name', 'like', '%'.$query.'%')
                    ->orWhere('customer_email', 'like', '%'.$query.'%')
                    ->orWhere('customer_phone', 'like', '%'.$query.'%');
            }))->latest('submitted_at')->paginate(25)->withQueryString();

        return view('admin.bookings', compact('bookings', 'status', 'query'));
    }

    public function calendar(Request $request): View
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $weekStart = Carbon::parse($data['date'] ?? today()->toDateString())->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $appointments = Appointment::query()->with(['booking', 'mechanicAssignments.mechanic.user'])
            ->whereBetween('scheduled_start_at', [$weekStart, $weekEnd])->orderBy('scheduled_start_at')->get();
        $appointmentsByDate = $appointments->groupBy(fn (Appointment $appointment): string => $appointment->scheduled_start_at->toDateString());

        return view('admin.calendar', compact('weekStart', 'weekEnd', 'appointmentsByDate'));
    }

    public function show(int $booking): View
    {
        $booking = Booking::query()->with(['items', 'customer', 'engineBuildConfiguration.pistonVariant', 'engineBuildConfiguration.blockVariant', 'engineBuildConfiguration.injectorVariant', 'engineBreakInProgram.fuelExpenses', 'engineBreakInProgram.fuelPayments', 'statusHistory.actor', 'appointments.mechanicAssignments.mechanic.user', 'refunds', 'changeRequests.requester', 'workOrder.items.stockReservation', 'workOrder.items.partVariant.part', 'workOrder.recommendations.workOrderItems.stockReservation', 'workOrder.recommendations.decisionHistory.actor'])->findOrFail($booking);
        $allowedVariantIds = $booking->items->flatMap(fn ($item): array => collect(data_get($item->catalog_snapshot, 'items', []))->pluck('variant.id')->filter()->map(fn ($id): int => (int) $id)->all())->unique();

        return view('admin.booking-detail', ['booking' => $booking, 'buildVariants' => PartVariant::query()->with('part.brand')->whereIn('id', $allowedVariantIds)->orderBy('part_id')->orderBy('name')->get(), 'mechanics' => MechanicProfile::query()->with(['user', 'specializations'])->where('is_active', true)->orderBy('id')->get(), 'globalStatusMode' => BusinessSetting::value('booking_status_mode', ['mode' => 'automatic'])['mode'] ?? 'automatic']);
    }

    public function updateStatus(Request $request, int $booking, AuditLogger $audit, NotificationService $notifications, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending_approval', 'awaiting_payment', 'confirmed', 'scheduled', 'checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed', 'cancelled', 'no_show', 'rejected', 'refunded'])],
            'status_control_mode' => ['required', Rule::in(['inherit', 'automatic', 'manual'])],
            'status_note' => ['required', 'string', 'max:2000'],
        ]);
        $record = Booking::query()->with('workOrder')->findOrFail($booking);
        if ($data['status'] === 'refunded' && $record->payment_status !== 'refunded') {
            throw ValidationException::withMessages(['status' => 'Record and process a full refund in the payment ledger before setting this booking to refunded.']);
        }
        if (in_array($data['status'], ['cancelled', 'rejected', 'no_show'], true) && $record->workOrder?->started_at) {
            throw ValidationException::withMessages(['status' => 'This work order has started. Close or hand over the workshop work before changing it to a terminal booking status.']);
        }

        DB::transaction(function () use ($request, $record, $data): void {
            $locked = Booking::query()->lockForUpdate()->findOrFail($record->id);
            $from = $locked->status;
            $mode = $data['status_control_mode'] === 'inherit' ? null : $data['status_control_mode'];
            $locked->update(['status' => $data['status'], 'status_control_mode' => $mode]);
            if ($from !== $data['status']) {
                BookingStatusHistory::create(['booking_id' => $locked->id, 'from_status' => $from, 'to_status' => $data['status'], 'changed_by' => $request->user()->id, 'note' => $data['status_note'], 'metadata' => ['manual_override' => true, 'status_control_mode' => $mode ?? 'inherit']]);
            }

            $terminal = match ($data['status']) {
                'cancelled', 'rejected', 'refunded' => 'cancelled',
                'no_show' => 'no_show',
                default => null,
            };
            if ($terminal) {
                foreach ($locked->appointments()->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->lockForUpdate()->get() as $appointment) {
                    $appointment->update(['status' => $terminal, 'reserved_until' => null]);
                    AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => $terminal, 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => $data['status_note'], 'changed_by' => $request->user()->id]);
                }
                if (in_array($data['status'], ['cancelled', 'rejected', 'no_show'], true) && $locked->workOrder && $locked->workOrder->status !== 'cancelled') {
                    $fromWorkOrder = $locked->workOrder->status;
                    $locked->workOrder->update(['status' => 'cancelled']);
                    WorkOrderHistory::create(['work_order_id' => $locked->workOrder->id, 'from_status' => $fromWorkOrder, 'to_status' => 'cancelled', 'changed_by' => $request->user()->id, 'note' => $data['status_note']]);
                }
            } else {
                $appointmentStatus = match ($data['status']) {
                    'confirmed' => 'confirmed', 'scheduled' => 'scheduled', 'checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed' => $data['status'],
                    default => null,
                };
                if ($appointmentStatus) {
                    $appointment = $locked->appointments()->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])->latest()->first();
                    if ($appointment && $appointment->status !== $appointmentStatus) {
                        $previous = $appointment->status;
                        $appointment->update(['status' => $appointmentStatus, 'reserved_until' => null, 'overnight' => $appointmentStatus === 'overnight' || $appointment->overnight]);
                        AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => $appointmentStatus, 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => $data['status_note'], 'changed_by' => $request->user()->id]);
                    }
                }
            }
        });

        if (in_array($data['status'], ['cancelled', 'rejected', 'refunded', 'no_show'], true)) {
            $inventory->releaseBooking($record, $request->user(), 'Booking status changed to '.$data['status'].'.');
        }
        $audit->record($request, 'booking.status_manually_updated', $record, ['to' => $data['status'], 'mode' => $data['status_control_mode'], 'reason' => $data['status_note']]);
        $record->refresh();
        if ($record->customer) {
            $notifications->send($record->customer, 'booking_status_updated', 'Booking status updated', $record->booking_number.' is now '.str_replace('_', ' ', $data['status']).'. '.$data['status_note'], route('portal.bookings.show', $record->id), ['booking_id' => $record->id]);
        }

        return back()->with('status', 'Booking status and control mode updated with an audit record.');
    }

    public function approve(Request $request, int $booking, WorkshopCapacityService $capacity, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $record = Booking::query()->with(['items', 'engineBuildConfiguration'])->findOrFail($booking);
        abort_unless($record->status === 'pending_approval', 409);
        $validated = $request->validate([
            'approved_total_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'scheduled_start_at' => ['required', 'date', 'after:now'],
            'mechanic_profile_ids' => ['required', 'array', 'min:1', 'max:8'],
            'mechanic_profile_ids.*' => ['required', 'integer', 'distinct', 'exists:mechanic_profiles,id'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'qris', 'cash'])],
            'admin_notes' => ['nullable', 'string', 'max:3000'],
            'appointment_notes' => ['nullable', 'string', 'max:2000'],
            'override_specialization' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->boolean('override_specialization'))],
            'fitment_review_confirmed' => ['nullable', 'boolean'],
            'fitment_review_note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($record->requires_fitment_confirmation)],
        ]);
        if ($record->requires_fitment_confirmation && ! $request->boolean('fitment_review_confirmed')) {
            throw ValidationException::withMessages(['fitment_review_confirmed' => 'Confirm that the selected parts have been checked for this motorcycle before approving the booking.']);
        }
        if ($record->isBoreUpBooking() && ($record->engineBuildConfiguration?->compatibility_status !== 'compatible' || blank($record->engineBuildConfiguration?->compatibility_verification_note))) {
            throw ValidationException::withMessages(['engine_build' => 'Verify the selected Bore-Up components and motorcycle compatibility before approving this quotation.']);
        }
        $minimum = max(0, (int) $record->subtotal_rupiah - (int) $record->discount_rupiah);
        if (! $record->has_unpriced_items && $validated['approved_total_rupiah'] < $minimum) {
            throw ValidationException::withMessages(['approved_total_rupiah' => 'The approved total cannot be below the server-calculated booking amount.']);
        }

        DB::transaction(function () use ($record, $validated, $request, $capacity): void {
            $booking = Booking::query()->lockForUpdate()->findOrFail($record->id);
            if ($booking->status !== 'pending_approval') {
                abort(409);
            }
            $from = $booking->status;
            $booking->update([
                'approved_total_rupiah' => (int) $validated['approved_total_rupiah'],
                'estimated_duration_minutes' => (int) $validated['estimated_duration_minutes'],
                'additional_work_rupiah' => 0,
                'payment_method' => $validated['payment_method'],
                'payment_status' => 'due',
                'status' => 'awaiting_payment',
                'approved_at' => now(),
                'payment_deadline_at' => now()->addHours((int) BusinessSetting::value('payment_policy', ['approval_deadline_hours' => 24])['approval_deadline_hours']),
                'admin_notes' => $validated['admin_notes'] ?? null,
            ]);
            $appointment = $capacity->reserve($booking, Carbon::parse($validated['scheduled_start_at']), array_map('intval', $validated['mechanic_profile_ids']), (int) $validated['estimated_duration_minutes'], $request->boolean('override_specialization'), $validated['override_reason'] ?? null, (int) $request->user()->id, $validated['appointment_notes'] ?? null);
            if ($booking->requiresFullDayReservation()) {
                $booking->update(['estimated_duration_minutes' => $appointment->scheduled_start_at->diffInMinutes($appointment->scheduled_end_at)]);
            }
            BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $from, 'to_status' => 'awaiting_payment', 'changed_by' => $request->user()->id, 'note' => $booking->requires_fitment_confirmation ? 'Booking approved after motorcycle part fitment was reviewed.' : 'Booking approved; appointment reserved pending payment.', 'metadata' => ['appointment_id' => $appointment->id, 'approved_total_rupiah' => $booking->approved_total_rupiah, 'payment_deadline_at' => $booking->payment_deadline_at?->toIso8601String(), 'fitment_review' => $booking->requires_fitment_confirmation ? ['confirmed' => true, 'note' => $validated['fitment_review_note']] : null]]);
        });

        $audit->record($request, 'booking.approved', $record, ['approved_total_rupiah' => (int) $validated['approved_total_rupiah'], 'duration_minutes' => (int) $record->fresh()->estimated_duration_minutes]);
        $record->refresh();
        if ($record->customer) {
            $notifications->send($record->customer, 'booking_approved', 'Booking approved; payment is due', $record->booking_number.' was approved. Submit the requested initial payment before '.$record->payment_deadline_at?->format('d M Y H:i').'.', route('portal.bookings.show', $record->id), ['booking_id' => $record->id]);
        }

        return back()->with('status', 'Booking approved and appointment temporarily reserved for 24 hours.');
    }

    public function reject(Request $request, int $booking, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($request, $booking, $data): void {
            $record = Booking::query()->lockForUpdate()->findOrFail($booking);
            abort_unless($record->status === 'pending_approval', 409);
            $from = $record->status;
            $record->update(['status' => 'rejected', 'admin_notes' => $data['rejection_reason']]);
            BookingStatusHistory::create(['booking_id' => $record->id, 'from_status' => $from, 'to_status' => 'rejected', 'changed_by' => $request->user()->id, 'note' => $data['rejection_reason']]);
        });
        $audit->record($request, 'booking.rejected', Booking::query()->findOrFail($booking), ['reason' => $data['rejection_reason']]);
        $record = Booking::query()->with('customer')->findOrFail($booking);
        if ($record->customer) {
            $notifications->send($record->customer, 'booking_rejected', 'Booking request update', $record->booking_number.' was not approved. '.$data['rejection_reason'], route('portal.bookings.show', $record->id), ['booking_id' => $record->id]);
        }

        return redirect()->route('admin.bookings.show', $booking)->with('status', 'Booking request rejected.');
    }

    public function decideRecommendation(Request $request, int $recommendation, AdditionalWorkDecisionService $decisions, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $record = WorkRecommendation::query()->with('workOrder.booking.customer')->findOrFail($recommendation);
        abort_unless($record->customer_decision !== 'pending', 409);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'decision_note' => ['required', 'string', 'max:1000'],
        ]);
        $previous = $record->customer_decision;
        $booking = $record->workOrder->booking;
        $updated = $decisions->decide($booking, $record->id, $data['decision'], $request->user(), $data['decision_note'], true);
        $audit->record($request, 'recommendation.admin_override', $updated, ['booking_id' => $booking->id, 'from' => $previous, 'to' => $data['decision'], 'reason' => $data['decision_note']]);

        if ($booking->customer) {
            $notifications->send($booking->customer, 'recommendation_admin_override', 'MINEX updated an additional-work decision', $updated->title.' was changed by an administrator. '.$data['decision_note'], route('portal.bookings.show', $booking->id), ['booking_id' => $booking->id, 'recommendation_id' => $updated->id]);
        }

        return redirect()->route('admin.bookings.show', $booking->id)->with('status', 'Additional-work decision updated and audited.');
    }

    public function reschedule(Request $request, int $booking, WorkshopCapacityService $capacity, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_start_at' => ['required', 'date', 'after:now'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'mechanic_profile_ids' => ['required', 'array', 'min:1', 'max:8'],
            'mechanic_profile_ids.*' => ['required', 'integer', 'distinct', 'exists:mechanic_profiles,id'],
            'appointment_notes' => ['nullable', 'string', 'max:2000'],
            'override_specialization' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->boolean('override_specialization'))],
        ]);
        $record = Booking::query()->findOrFail($booking);
        abort_unless(in_array($record->status, ['awaiting_payment', 'confirmed', 'scheduled'], true), 409);
        $appointment = $capacity->reserve($record, Carbon::parse($data['scheduled_start_at']), array_map('intval', $data['mechanic_profile_ids']), (int) $data['estimated_duration_minutes'], $request->boolean('override_specialization'), $data['override_reason'] ?? null, (int) $request->user()->id, $data['appointment_notes'] ?? null);
        $record->update(['estimated_duration_minutes' => (int) $data['estimated_duration_minutes']]);
        $record->workOrder()->update(['appointment_id' => $appointment->id]);
        AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'rescheduled', 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => $data['appointment_notes'] ?? 'Appointment changed by an administrator.', 'changed_by' => $request->user()->id]);
        $audit->record($request, 'appointment.rescheduled', $appointment, ['booking_id' => $record->id, 'starts_at' => $appointment->scheduled_start_at?->toIso8601String()]);
        $record->refresh();
        if ($record->customer) {
            $notifications->send($record->customer, 'appointment_changed', 'Appointment changed', $record->booking_number.' has a new appointment on '.$appointment->scheduled_start_at->format('d M Y · H:i').'.', route('portal.bookings.show', $record->id), ['booking_id' => $record->id, 'appointment_id' => $appointment->id]);
        }

        return back()->with('status', 'Appointment updated and capacity rechecked.');
    }
}
