<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppointmentHistory;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\EngineBreakInProgram;
use App\Models\PartVariant;
use App\Models\PaymentRecord;
use App\Models\PaymentVerification;
use App\Models\Refund;
use App\Models\WorkOrder;
use App\Models\WorkOrderHistory;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentAdminController extends Controller
{
    public function index(): View
    {
        $payments = PaymentRecord::query()->with(['booking', 'verifications'])->latest('submitted_at')->paginate(30);
        $refunds = Refund::query()->with('booking')->latest()->paginate(30, ['*'], 'refund_page');

        return view('admin.payments', compact('payments', 'refunds'));
    }

    public function verify(Request $request, PaymentRecord $payment, InventoryService $inventory, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:verified,rejected'], 'note' => ['nullable', 'string', 'max:2000']]);
        $booking = DB::transaction(function () use ($request, $payment, $data, $inventory): Booking {
            $record = PaymentRecord::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($record->status === 'submitted', 409);
            $booking = Booking::query()->lockForUpdate()->findOrFail($record->booking_id);
            $record->update(['status' => $data['decision']]);
            PaymentVerification::create(['payment_id' => $record->id, 'decision' => $data['decision'], 'note' => $data['note'] ?? null, 'verified_by' => $request->user()->id]);

            if ($record->payment_kind === 'engine_break_in_fuel') {
                $program = EngineBreakInProgram::query()->lockForUpdate()->findOrFail($record->engine_break_in_program_id);
                if ($data['decision'] === 'verified') {
                    $verifiedFuelPayments = (int) $program->fuelPayments()->where('status', 'verified')->sum('amount_rupiah');
                    $customerFuelDue = (int) $program->fuelExpenses()->sum('customer_contribution_rupiah');
                    if ($verifiedFuelPayments > $customerFuelDue) {
                        throw ValidationException::withMessages(['decision' => 'Verified fuel payments cannot exceed the customer contribution recorded from receipts.']);
                    }
                }

                return $booking;
            }

            if ($data['decision'] === 'rejected') {
                $paid = (int) $booking->payments()->where('status', 'verified')->where('payment_kind', '!=', 'engine_break_in_fuel')->sum('amount_rupiah');
                $refunded = (int) $booking->refunds()->where('status', 'processed')->sum('amount_rupiah');
                $netPaid = max(0, $paid - $refunded);
                $booking->update(['amount_paid_rupiah' => $paid, 'payment_status' => $refunded > 0 ? 'partially_refunded' : ($netPaid > 0 ? 'partial' : 'due')]);

                return $booking;
            }

            $paid = (int) $booking->payments()->where('status', 'verified')->where('payment_kind', '!=', 'engine_break_in_fuel')->sum('amount_rupiah');
            $refunded = (int) $booking->refunds()->where('status', 'processed')->sum('amount_rupiah');
            $netPaid = max(0, $paid - $refunded);
            $total = (int) $booking->approved_total_rupiah + (int) $booking->additional_work_rupiah;
            $depositTarget = (int) ceil(((int) $booking->approved_total_rupiah * (int) ($booking->customer_type === 'guest' ? 100 : $booking->payment_percentage)) / 100);
            $canConfirm = $netPaid >= $depositTarget;
            $booking->update(['amount_paid_rupiah' => $paid, 'payment_status' => $refunded > 0 ? 'partially_refunded' : ($netPaid >= $total ? 'paid' : ($netPaid > 0 ? 'partial' : 'due'))]);

            if ($canConfirm && $booking->status === 'awaiting_payment') {
                $from = $booking->status;
                if ($booking->usesAutomaticStatusControl()) {
                    $booking->update(['status' => 'confirmed']);
                    BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $from, 'to_status' => 'confirmed', 'changed_by' => $request->user()->id, 'note' => 'Required booking deposit verified.']);
                }
                $appointment = $booking->appointments()->where('status', 'reserved')->first();
                if ($appointment) {
                    $appointment->update(['status' => 'confirmed', 'reserved_until' => null]);
                    AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'confirmed', 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => 'Required payment verified.', 'changed_by' => $request->user()->id]);
                }

                $partsReady = true;
                $reservationByItem = [];
                try {
                    foreach ($booking->items as $item) {
                        if ($item->part_variant_id) {
                            $reservationByItem['booking-'.$item->id] = $inventory->reserve($item->partVariant()->firstOrFail(), (float) $item->quantity, $request->user(), $booking);
                        }
                        if ($item->item_type === 'package') {
                            foreach (($item->catalog_snapshot['items'] ?? []) as $index => $component) {
                                if (($component['selected'] ?? false) && isset($component['variant']['id'])) {
                                    $variant = PartVariant::query()->findOrFail($component['variant']['id']);
                                    $quantity = (float) $item->quantity * (float) ($component['quantity'] ?? 1);
                                    $reservationByItem['package-'.$item->id.'-'.$index] = $inventory->reserve($variant, $quantity, $request->user(), $booking);
                                }
                            }
                        }
                    }
                } catch (ValidationException) {
                    $inventory->releaseBooking($booking, $request->user(), 'Incomplete initial stock reservation after payment confirmation.');
                    $reservationByItem = [];
                    $partsReady = false;
                }
                $workOrder = WorkOrder::firstOrCreate(['booking_id' => $booking->id], ['appointment_id' => $appointment?->id, 'status' => $partsReady ? 'scheduled' : 'waiting_parts', 'created_by' => $request->user()->id]);
                if ($workOrder->wasRecentlyCreated) {
                    foreach ($booking->items as $item) {
                        if ($item->item_type === 'package') {
                            foreach (($item->catalog_snapshot['items'] ?? []) as $index => $component) {
                                if (! ($component['selected'] ?? false)) {
                                    continue;
                                }
                                $variantId = $component['variant']['id'] ?? null;
                                $workOrder->items()->create([
                                    'booking_item_id' => $item->id,
                                    'part_variant_id' => $variantId,
                                    'stock_reservation_id' => $reservationByItem['package-'.$item->id.'-'.$index]->id ?? null,
                                    'label_snapshot' => $component['label'],
                                    'quantity' => (float) $item->quantity * (float) ($component['quantity'] ?? 1),
                                    'unit_price_rupiah' => $component['extra_price_rupiah'] ?? $component['variant']['selling_price_rupiah'] ?? $component['service']['labor_price_rupiah'] ?? null,
                                    'status' => 'pending',
                                ]);
                            }
                        } else {
                            $workOrder->items()->create(['booking_item_id' => $item->id, 'part_variant_id' => $item->part_variant_id, 'stock_reservation_id' => $reservationByItem['booking-'.$item->id]->id ?? null, 'label_snapshot' => $item->label_snapshot, 'quantity' => $item->quantity, 'unit_price_rupiah' => $item->unit_price_rupiah ?? $item->labor_price_rupiah, 'status' => 'pending']);
                        }
                    }
                    foreach (['Check motorcycle condition and odometer', 'Confirm approved work scope', 'Record inspection findings', 'Confirm parts used', 'Explain completed work to rider'] as $index => $label) {
                        $workOrder->checklists()->create(['label' => $label, 'sort_order' => $index + 1]);
                    }
                }
            }

            return $booking;
        });
        $audit->record($request, 'payment.'.($payment->payment_kind === 'engine_break_in_fuel' ? 'engine_break_in_fuel.' : '').$data['decision'], $payment, ['booking_id' => $booking->id, 'program_id' => $payment->engine_break_in_program_id, 'amount_rupiah' => (int) $payment->amount_rupiah, 'note' => $data['note'] ?? null]);

        if ($booking->customer) {
            $isFuelPayment = $payment->payment_kind === 'engine_break_in_fuel';
            $notifications->send($booking->customer, $data['decision'] === 'verified' ? 'payment_verified' : 'payment_rejected', $isFuelPayment ? 'Engine Break-In fuel payment '.($data['decision'] === 'verified' ? 'verified' : 'needs attention') : ($data['decision'] === 'verified' ? 'Payment verified' : 'Payment needs attention'), $data['decision'] === 'verified' ? ($isFuelPayment ? 'Your separate Engine Break-In fuel contribution for '.$booking->booking_number.' has been recorded.' : 'Your payment for '.$booking->booking_number.' has been recorded.') : 'Your payment for '.$booking->booking_number.' could not be verified. '.$data['note'], route('portal.bookings.show', $booking->id), ['booking_id' => $booking->id, 'payment_id' => $payment->id]);
        }

        return back()->with('status', 'Payment decision recorded. The submitted payment record remains in the ledger.');
    }

    public function proof(PaymentRecord $payment)
    {
        abort_unless($payment->proof_disk && $payment->proof_path && Storage::disk($payment->proof_disk)->exists($payment->proof_path), 404);

        return Storage::disk($payment->proof_disk)->download($payment->proof_path, basename($payment->proof_path));
    }

    public function requestRefund(Request $request, Booking $booking, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['amount_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'], 'method' => ['required', 'in:bank_transfer,qris,cash'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($booking, $data): void {
            $record = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $verified = (int) $record->payments()->where('status', 'verified')->where('payment_kind', '!=', 'engine_break_in_fuel')->sum('amount_rupiah');
            $alreadyRefundedOrPending = (int) $record->refunds()->whereIn('status', ['pending', 'processed'])->sum('amount_rupiah');
            if ((int) $data['amount_rupiah'] > max(0, $verified - $alreadyRefundedOrPending)) {
                throw ValidationException::withMessages(['amount_rupiah' => 'The requested refund cannot exceed verified payments minus prior refunds.']);
            }
            Refund::create(['booking_id' => $record->id, 'amount_rupiah' => (int) $data['amount_rupiah'], 'method' => $data['method'], 'status' => 'pending', 'reason' => $data['reason']]);
        });
        $audit->record($request, 'refund.requested', $booking, ['amount_rupiah' => (int) $data['amount_rupiah'], 'reason' => $data['reason']]);

        return back()->with('status', 'Refund request recorded for processing.');
    }

    public function processRefund(Request $request, Refund $refund, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:processed,rejected'], 'reference' => ['nullable', 'string', 'max:120'], 'note' => ['required', 'string', 'max:2000']]);
        $booking = DB::transaction(function () use ($request, $refund, $data): Booking {
            $record = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            abort_unless($record->status === 'pending', 409);
            $booking = Booking::query()->lockForUpdate()->findOrFail($record->booking_id);
            $record->update(['status' => $data['decision'], 'reference' => $data['reference'] ?? null, 'processing_note' => $data['note'], 'processed_by' => $request->user()->id, 'processed_at' => $data['decision'] === 'processed' ? now() : null]);
            $refunded = (int) $booking->refunds()->where('status', 'processed')->sum('amount_rupiah');
            $grossPaid = (int) $booking->payments()->where('status', 'verified')->where('payment_kind', '!=', 'engine_break_in_fuel')->sum('amount_rupiah');
            $fullyRefunded = $grossPaid > 0 && $refunded >= $grossPaid;
            $status = $fullyRefunded ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : $booking->payment_status);
            $fromBookingStatus = $booking->status;
            $booking->update(['refund_amount_rupiah' => $refunded, 'payment_status' => $status, 'status' => $fullyRefunded ? 'refunded' : $booking->status]);
            if ($fullyRefunded && $fromBookingStatus !== 'refunded') {
                BookingStatusHistory::create(['booking_id' => $booking->id, 'from_status' => $fromBookingStatus, 'to_status' => 'refunded', 'changed_by' => $request->user()->id, 'note' => 'All verified payments were refunded.']);
            }
            if ($fullyRefunded && $booking->status === 'refunded') {
                foreach ($booking->appointments()->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->get() as $appointment) {
                    $appointment->update(['status' => 'cancelled', 'reserved_until' => null]);
                    AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'cancelled', 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => 'The approved booking was fully refunded.', 'changed_by' => $request->user()->id]);
                }
                app(InventoryService::class)->releaseBooking($booking, $request->user(), 'Booking was fully refunded.');
                $workOrder = $booking->workOrder()->first();
                if ($workOrder && in_array($workOrder->status, ['scheduled', 'waiting_parts'], true)) {
                    $fromWorkOrderStatus = $workOrder->status;
                    $workOrder->update(['status' => 'cancelled']);
                    WorkOrderHistory::create(['work_order_id' => $workOrder->id, 'from_status' => $fromWorkOrderStatus, 'to_status' => 'cancelled', 'changed_by' => $request->user()->id, 'note' => 'Booking was fully refunded.']);
                }
            }

            return $booking;
        });
        $audit->record($request, 'refund.'.$data['decision'], $refund, ['booking_id' => $booking->id, 'amount_rupiah' => (int) $refund->amount_rupiah, 'reference' => $data['reference'] ?? null, 'note' => $data['note']]);
        if ($booking->customer) {
            $notifications->send($booking->customer, 'refund_'.$data['decision'], $data['decision'] === 'processed' ? 'Refund recorded' : 'Refund request declined', 'Your refund request for '.$booking->booking_number.' was '.$data['decision'].'.', route('portal.bookings.show', $booking->id), ['booking_id' => $booking->id, 'refund_id' => $refund->id]);
        }

        return back()->with('status', 'Refund decision recorded.');
    }
}
