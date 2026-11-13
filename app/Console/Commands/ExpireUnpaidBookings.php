<?php

namespace App\Console\Commands;

use App\Models\AppointmentHistory;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Refund;
use App\Services\InventoryService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireUnpaidBookings extends Command
{
    protected $signature = 'minex:expire-unpaid-bookings';

    protected $description = 'Cancel approved bookings whose initial payment deadline passed and release temporary reservations.';

    public function handle(InventoryService $inventory, NotificationService $notifications): int
    {
        $expiredCount = 0;
        Booking::query()->where('status', 'awaiting_payment')->whereNotNull('payment_deadline_at')->where('payment_deadline_at', '<=', now())->orderBy('id')->chunkById(100, function ($bookings) use (&$expiredCount, $inventory, $notifications): void {
            foreach ($bookings as $booking) {
                $expired = DB::transaction(function () use ($booking, $inventory): ?Booking {
                    $locked = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                    if ($locked->status !== 'awaiting_payment' || ! $locked->payment_deadline_at?->isPast()) {
                        return null;
                    }
                    $from = $locked->status;
                    $verifiedAmount = (int) $locked->payments()->where('status', 'verified')->sum('amount_rupiah');
                    $reservedOrProcessedRefund = (int) $locked->refunds()->whereIn('status', ['pending', 'processed'])->sum('amount_rupiah');
                    $refundDue = max(0, $verifiedAmount - $reservedOrProcessedRefund);
                    $locked->update([
                        'status' => 'cancelled',
                        'payment_status' => $refundDue > 0 ? 'refund_pending' : 'expired',
                        'amount_paid_rupiah' => $verifiedAmount,
                        'refund_amount_rupiah' => (int) $locked->refunds()->where('status', 'processed')->sum('amount_rupiah'),
                    ]);
                    BookingStatusHistory::create(['booking_id' => $locked->id, 'from_status' => $from, 'to_status' => 'cancelled', 'note' => 'Initial payment deadline expired.']);
                    foreach ($locked->appointments()->where('status', 'reserved')->get() as $appointment) {
                        $appointment->update(['status' => 'cancelled', 'reserved_until' => null]);
                        AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => 'cancelled', 'previous_start_at' => $appointment->scheduled_start_at, 'previous_end_at' => $appointment->scheduled_end_at, 'new_start_at' => $appointment->scheduled_start_at, 'new_end_at' => $appointment->scheduled_end_at, 'note' => 'Initial payment deadline expired.', 'changed_by' => null]);
                    }
                    $inventory->releaseBooking($locked, null, 'Booking expired before initial payment confirmation.');
                    if ($refundDue > 0) {
                        Refund::create(['booking_id' => $locked->id, 'amount_rupiah' => $refundDue, 'method' => $locked->payment_method ?: 'bank_transfer', 'status' => 'pending', 'reason' => 'Verified partial payment returned after booking payment deadline expired.']);
                    }
                    AuditLog::create(['actor_id' => null, 'event_type' => 'booking.payment_expired', 'subject_type' => Booking::class, 'subject_id' => $locked->id, 'metadata' => ['refund_due_rupiah' => $refundDue], 'ip_address' => null]);

                    return $locked;
                });
                if (! $expired) {
                    continue;
                }
                $expiredCount++;
                if ($expired->customer) {
                    $notifications->send($expired->customer, 'booking_payment_expired', 'Booking payment window ended', $expired->booking_number.' was cancelled because the initial payment deadline passed.'.($expired->refund_amount_rupiah || $expired->payment_status === 'refund_pending' ? ' MINEX has a refund request to process for verified payments.' : ''), route('portal.bookings.show', $expired->id), ['booking_id' => $expired->id]);
                }
            }
        });

        $this->info("Expired {$expiredCount} booking(s).");

        return self::SUCCESS;
    }
}
