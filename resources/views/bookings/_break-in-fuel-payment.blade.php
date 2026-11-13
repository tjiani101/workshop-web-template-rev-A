@php
    $fuelContributionTotal = (int) $breakIn->fuelExpenses->sum('customer_contribution_rupiah');
    $fuelContributionPaid = (int) $breakIn->fuelPayments->where('status', 'verified')->sum('amount_rupiah');
    $fuelContributionDue = max(0, $fuelContributionTotal - $fuelContributionPaid);
    $pendingFuelPayment = $breakIn->fuelPayments->firstWhere('status', 'submitted');
@endphp
<div class="admin-price-line"><span>Fuel contribution paid</span><strong>Rp {{ number_format($fuelContributionPaid, 0, ',', '.') }}</strong></div>
<div class="admin-price-line"><span>Fuel contribution due</span><strong>Rp {{ number_format($fuelContributionDue, 0, ',', '.') }}</strong></div>
@if($pendingFuelPayment)
    <p class="booking-note">Fuel contribution payment {{ $pendingFuelPayment->payment_reference }} is awaiting workshop verification.</p>
@elseif($fuelContributionDue > 0)
    <form class="admin-form admin-form-grid" action="{{ route('bookings.break-in-fuel-payments.store', $booking->reference) }}" method="POST" enctype="multipart/form-data">@csrf
        <span class="eyebrow span-two">SEPARATE FUEL CONTRIBUTION PAYMENT</span>
        <label>Amount (Rp)<input type="number" name="amount_rupiah" min="1" max="{{ $fuelContributionDue }}" value="{{ old('amount_rupiah', $fuelContributionDue) }}" required></label>
        <label>Method<select name="method" required>@foreach(['bank_transfer' => 'Bank transfer', 'qris' => 'QRIS', 'cash' => 'Cash at the workshop'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label>Transfer or receipt reference<input name="customer_reference" maxlength="120"></label>
        <label>Proof file <span class="field-help">Optional, private upload</span><input type="file" name="proof" accept="image/jpeg,image/png,image/webp,application/pdf"></label>
        <label class="span-two">Note<textarea name="customer_note" rows="2" maxlength="1000"></textarea></label>
        <button class="button button-primary button-small span-two" type="submit">Submit separate fuel payment</button>
    </form>
@endif
