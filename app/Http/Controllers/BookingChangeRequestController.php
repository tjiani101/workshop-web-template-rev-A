<?php

namespace App\Http\Controllers;

use App\Models\AppointmentHistory;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\BookingStatusHistory;
use App\Models\WorkOrderHistory;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\NotificationService;
use App\Services\WorkshopCapacityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingChangeRequestController extends Controller
{
    public function store(Request $request, int $bookingId): RedirectResponse
    {
        $booking = $request->user()->bookings()->findOrFail($bookingId);
        abort_unless(in_array($booking->status, ['pending_approval', 'awaiting_payment', 'confirmed', 'scheduled'], true), 409);
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['cancel', 'reschedule'])],
            'requested_start_at' => ['nullable', 'date', 'after:now', Rule::requiredIf($request->input('request_type') === 'reschedule')],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['request_type'] === 'reschedule' && ! $booking->appointments()->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->exists()) {
            throw ValidationException::withMessages(['request_type' => 'A reschedule request needs an active appointment.']);
        }
        if ($booking->changeRequests()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['request_type' => 'A change request is already waiting for MINEX review.']);
        }

        $booking->changeRequests()->create([
            'requester_id' => $request->user()->id,
            'request_type' => $data['request_type'],
            'requested_start_at' => $data['request_type'] === 'reschedule' ? $data['requested_start_at'] : null,
            'customer_note' => $data['customer_note'] ?? null,
            'status' => 'pending',
        ]);

        return back()->with('status', 'Your booking change request was sent to MINEX for review.');
    }

    public function review(Request $request, BookingChangeRequest $changeRequest, WorkshopCapacityService $capacity, InventoryService $inventory, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'declined'])],
            'admin_note' => ['required', 'string', 'max:2000'],
        ]);
        abort_unless($changeRequest->status === 'pending', 409);
        $booking = $changeRequest->booking()->with('workOrder')->firstOrFail();

        if ($data['decision'] === 'accepted' && $changeRequest->request_type === 'cancel') {
            if ($booking->workOrder?->started_at) {
                throw ValidationException::withMessages(['decision' => 'This motorcycle has entered workshop work. Handle the service and payment records before cancelling the booking.']);
            }
            DB::transaction(function () use ($request, $booking, $changeRequest, $data): void {
                $lockedRequest = BookingChangeRequest::query()->lockForUpdate()->findOrFail($changeRequest->id);
                $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                abort_unless($lockedRequest->status === 'pending', 409);
                $from = $lockedBooking->status;
                $lockedBooking->update(['status' => 'cancelled']);
                BookingStatusHistory::create(['booking_id' => $lockedBooking->id, 'from_status' => $from, 'to_status' => 'cancelled', 'changed_by' => $request->user()->id, 'note' => $data['admin_note'], 'metadata' => ['change_request_id' => $lockedRequest->id, 'request_type' => 'cancel']]);
                foreach ($lockedBooking->appointments()->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->lockForUpdate()->get() as $appointment) {
                    $appointment->update(['status' => 'cancelled', 'reserved_until' => null]);
                    AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'cancelled', 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => $data['admin_note'], 'changed_by' => $request->user()->id]);
                }
                if ($lockedBooking->workOrder && $lockedBooking->workOrder->status !== 'cancelled') {
                    $fromWorkOrder = $lockedBooking->workOrder->status;
                    $lockedBooking->workOrder->update(['status' => 'cancelled']);
                    WorkOrderHistory::create(['work_order_id' => $lockedBooking->workOrder->id, 'from_status' => $fromWorkOrder, 'to_status' => 'cancelled', 'changed_by' => $request->user()->id, 'note' => $data['admin_note']]);
                }
                $lockedRequest->update(['status' => 'accepted', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'admin_note' => $data['admin_note']]);
            });
            $inventory->releaseBooking($booking, $request->user(), 'Booking cancellation request accepted.');
        } elseif ($data['decision'] === 'accepted' && $changeRequest->request_type === 'reschedule') {
            $appointment = $booking->appointments()->with('mechanicAssignments')->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->latest()->first();
            if (! $appointment || ! $changeRequest->requested_start_at) {
                throw ValidationException::withMessages(['decision' => 'The booking no longer has a reschedulable appointment.']);
            }
            $mechanicIds = $appointment->mechanicAssignments->pluck('mechanic_profile_id')->map(fn ($id) => (int) $id)->all();
            $newAppointment = $capacity->reserve($booking, $changeRequest->requested_start_at, $mechanicIds, (int) $booking->estimated_duration_minutes, false, null, (int) $request->user()->id, $data['admin_note']);
            $booking->workOrder()->update(['appointment_id' => $newAppointment->id]);
            $changeRequest->update(['status' => 'accepted', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'admin_note' => $data['admin_note']]);
        } else {
            $changeRequest->update(['status' => 'declined', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'admin_note' => $data['admin_note']]);
        }

        $audit->record($request, 'booking.change_request_reviewed', $changeRequest, ['booking_id' => $booking->id, 'type' => $changeRequest->request_type, 'decision' => $data['decision'], 'note' => $data['admin_note']]);
        if ($booking->customer) {
            $body = $changeRequest->request_type === 'reschedule' && $data['decision'] === 'accepted'
                ? 'Your appointment was moved to '.$changeRequest->requested_start_at?->format('D, d M Y · H:i').'. '.$data['admin_note']
                : 'Your '.strtolower($changeRequest->request_type).' request was '.$data['decision'].'. '.$data['admin_note'];
            $notifications->send($booking->customer, 'booking_change_request_'.$data['decision'], 'Booking change request update', $body, route('portal.bookings.show', $booking->id), ['booking_id' => $booking->id, 'change_request_id' => $changeRequest->id]);
        }

        return back()->with('status', 'Booking change request '.$data['decision'].'.');
    }
}
