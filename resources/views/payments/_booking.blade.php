@php
    $total = (int) $booking->approved_total_rupiah + (int) $booking->additional_work_rupiah;
    $grossPaid = (int) $booking->amount_paid_rupiah;
    $refunded = (int) $booking->refund_amount_rupiah;
    $paid = max(0, $grossPaid - $refunded);
    $due = max(0, $total - $paid);
    $deposit = (int) ceil(((int) $booking->approved_total_rupiah * (int) ($booking->customer_type === 'guest' ? 100 : $booking->payment_percentage)) / 100);
    $minimumPayment = max(1, $deposit - $paid);
    $pendingPayment = $booking->payments->firstWhere('status', 'submitted');
@endphp
<article class="admin-card payment-card"><div class="admin-section-line"><div><p class="eyebrow">{{ $booking->booking_number }} / {{ strtoupper(str_replace('_', ' ', $booking->payment_status)) }}</p><h2>{{ $booking->vehicle_snapshot['brand'] ?? '' }} {{ $booking->vehicle_snapshot['model'] ?? '' }}</h2></div><strong class="payment-balance">Rp {{ number_format($due, 0, ',', '.') }}<small>outstanding</small></strong></div>
    <div class="payment-breakdown"><span>Approved work</span><strong>Rp {{ number_format($booking->approved_total_rupiah, 0, ',', '.') }}</strong><span>Approved additional work</span><strong>Rp {{ number_format($booking->additional_work_rupiah, 0, ',', '.') }}</strong><span>Verified payments</span><strong>− Rp {{ number_format($grossPaid, 0, ',', '.') }}</strong>@if($refunded)<span>Refunds processed</span><strong>+ Rp {{ number_format($refunded, 0, ',', '.') }}</strong>@endif</div>
    @if($booking->payments->isNotEmpty())<div class="payment-ledger">@foreach($booking->payments as $payment)<div><span>{{ $payment->payment_reference }} · {{ strtoupper(str_replace('_', ' ', $payment->payment_kind)) }}</span><strong>Rp {{ number_format($payment->amount_rupiah, 0, ',', '.') }}</strong><small>{{ strtoupper($payment->status) }} · {{ $payment->submitted_at?->format('d M Y · H:i') }}</small></div>@endforeach</div>@endif
    @if($pendingPayment)<p class="admin-note">Payment {{ $pendingPayment->payment_reference }} is awaiting manual verification.</p>
    @elseif($due > 0 && ! in_array($booking->status, ['cancelled', 'rejected', 'refunded'], true))<form class="admin-form admin-form-grid payment-submit-form" action="{{ route('bookings.payments.submit', $booking->reference) }}" method="POST" enctype="multipart/form-data">@csrf
        <label>Amount (Rp)<input type="number" name="amount_rupiah" min="{{ $minimumPayment }}" max="{{ $due }}" value="{{ old('amount_rupiah', $minimumPayment) }}" required></label>
        <label>Method<select name="method" required>@foreach(['bank_transfer' => 'Bank transfer', 'qris' => 'Merchant QRIS', 'cash' => 'Cash at the workshop'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
        <label>Transfer or receipt reference<input name="customer_reference" maxlength="120"></label><label>Proof file <span class="field-help">Optional, private upload (JPG, PNG, WebP, or PDF)</span><input type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
        <label class="span-two">Note<textarea name="customer_note" rows="2" maxlength="1000"></textarea></label>
        <p class="field-help span-two">Minimum first payment: Rp {{ number_format($minimumPayment, 0, ',', '.') }}. Payment stays pending until an administrator checks the actual transfer or cash receipt.</p>
        <button class="button button-primary span-two" type="submit">Submit payment for verification</button>
    </form>@endif
</article>
