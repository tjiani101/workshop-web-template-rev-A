<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\EngineBreakInProgram;
use App\Models\PaymentRecord;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $bookings = $request->user()->bookings()->with('payments')->whereIn('status', ['awaiting_payment', 'confirmed', 'scheduled', 'in_progress', 'waiting_parts', 'completed'])->latest()->paginate(20);

        return view('payments.index', compact('bookings'));
    }

    public function submit(Request $request, string $reference, NotificationService $notifications): RedirectResponse
    {
        $record = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($record->user_id && (int) $record->user_id !== (int) $request->user()?->id) {
            abort(404);
        }
        if ($request->user() && ! $request->user()->hasRole('customer')) {
            abort(403);
        }
        abort_unless($record->approved_total_rupiah !== null && ! in_array($record->status, ['cancelled', 'rejected', 'refunded'], true), 409);
        if ($record->status === 'awaiting_payment' && $record->payment_deadline_at?->isPast()) {
            throw ValidationException::withMessages(['amount_rupiah' => 'The initial payment deadline has passed. Contact the workshop about the expired booking.']);
        }
        $validated = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'method' => ['required', Rule::in(['bank_transfer', 'qris', 'cash'])],
            'customer_reference' => ['nullable', 'string', 'max:120'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $payment = DB::transaction(function () use ($request, $record, $validated): PaymentRecord {
            $booking = Booking::query()->lockForUpdate()->findOrFail($record->id);
            if ($booking->payments()->where('status', 'submitted')->where('payment_kind', '!=', 'engine_break_in_fuel')->exists()) {
                throw ValidationException::withMessages(['amount_rupiah' => 'A payment is already awaiting workshop verification.']);
            }
            $target = (int) ceil(((int) $booking->approved_total_rupiah * (int) ($booking->customer_type === 'guest' ? 100 : $booking->payment_percentage)) / 100);
            $previouslyVerified = (int) $booking->payments()->where('status', 'verified')->where('payment_kind', '!=', 'engine_break_in_fuel')->sum('amount_rupiah');
            $processedRefunds = (int) $booking->refunds()->where('status', 'processed')->sum('amount_rupiah');
            $netPaid = max(0, $previouslyVerified - $processedRefunds);
            $totalDue = (int) $booking->approved_total_rupiah + (int) $booking->additional_work_rupiah;
            $depositDue = max(0, $target - $netPaid);
            $amountDue = max(0, $totalDue - $netPaid);
            if ($amountDue === 0 || $validated['amount_rupiah'] > $amountDue || $netPaid < $target && $validated['amount_rupiah'] < $depositDue) {
                throw ValidationException::withMessages(['amount_rupiah' => $amountDue === 0 ? 'There is no outstanding amount for this booking.' : 'Enter at least the remaining approved deposit and no more than the current balance.']);
            }
            $proofPath = $request->file('proof')?->store('minex/payment-proofs', 'local');
            $payment = PaymentRecord::create([
                'booking_id' => $booking->id,
                'payment_reference' => 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'payment_kind' => $netPaid < $target ? 'deposit' : 'balance',
                'method' => $validated['method'],
                'amount_rupiah' => (int) $validated['amount_rupiah'],
                'status' => 'submitted',
                'customer_reference' => $validated['customer_reference'] ?? null,
                'proof_disk' => $proofPath ? 'local' : null,
                'proof_path' => $proofPath,
                'customer_note' => $validated['customer_note'] ?? null,
                'submitted_by' => $request->user()?->id,
                'submitted_at' => now(),
            ]);
            $booking->update(['payment_status' => 'verification_pending']);

            return $payment;
        });
        $record->refresh();
        $notifications->sendToRoles(['owner', 'admin'], 'payment_submitted', 'Payment submitted for verification', $record->booking_number.' has a payment awaiting manual review.', route('admin.payments.index'), ['booking_id' => $record->id, 'payment_id' => $payment->id]);

        return back()->with('status', 'Payment record submitted. The workshop will verify the transaction before marking it as paid.');
    }

    public function submitBreakInFuel(Request $request, string $reference, NotificationService $notifications): RedirectResponse
    {
        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($booking->user_id && (int) $booking->user_id !== (int) $request->user()?->id) {
            abort(404);
        }
        if ($request->user() && ! $request->user()->hasRole('customer')) {
            abort(403);
        }
        $validated = $request->validate([
            'amount_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'method' => ['required', Rule::in(['bank_transfer', 'qris', 'cash'])],
            'customer_reference' => ['nullable', 'string', 'max:120'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $payment = DB::transaction(function () use ($request, $booking, $validated): PaymentRecord {
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $program = EngineBreakInProgram::query()->where('booking_id', $lockedBooking->id)->lockForUpdate()->firstOrFail();
            if ($program->fuelPayments()->where('status', 'submitted')->exists()) {
                throw ValidationException::withMessages(['amount_rupiah' => 'A fuel contribution payment is already awaiting workshop verification.']);
            }
            $contributionDue = (int) $program->fuelExpenses()->sum('customer_contribution_rupiah');
            $verified = (int) $program->fuelPayments()->where('status', 'verified')->sum('amount_rupiah');
            $due = max(0, $contributionDue - $verified);
            if ($due === 0 || (int) $validated['amount_rupiah'] > $due) {
                throw ValidationException::withMessages(['amount_rupiah' => $due === 0 ? 'There is no outstanding break-in fuel contribution.' : 'Enter no more than the current outstanding fuel contribution.']);
            }
            $proofPath = $request->file('proof')?->store('minex/payment-proofs', 'local');

            return PaymentRecord::create([
                'booking_id' => $lockedBooking->id,
                'engine_break_in_program_id' => $program->id,
                'payment_reference' => 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                'payment_kind' => 'engine_break_in_fuel',
                'method' => $validated['method'],
                'amount_rupiah' => (int) $validated['amount_rupiah'],
                'status' => 'submitted',
                'customer_reference' => $validated['customer_reference'] ?? null,
                'proof_disk' => $proofPath ? 'local' : null,
                'proof_path' => $proofPath,
                'customer_note' => $validated['customer_note'] ?? null,
                'submitted_by' => $request->user()?->id,
                'submitted_at' => now(),
            ]);
        });
        $notifications->sendToRoles(['owner', 'admin'], 'payment_submitted', 'Engine Break-In fuel contribution submitted', $booking->booking_number.' has a separate fuel contribution payment awaiting manual review.', route('admin.payments.index'), ['booking_id' => $booking->id, 'payment_id' => $payment->id]);

        return back()->with('status', 'Fuel contribution payment submitted for workshop verification.');
    }
}
