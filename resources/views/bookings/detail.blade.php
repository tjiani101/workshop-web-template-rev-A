@extends('layouts.app')

@section('title', $booking->booking_number.' — MINEX')

@section('content')
<section class="plain-page-hero">
    <div class="container">
        <p class="eyebrow"><span class="red-rule"></span>YOUR MINEX / BOOKING</p>
        <h1>{{ $booking->booking_number }}</h1>
        <p>{{ str_replace('_', ' ', ucfirst($booking->status)) }} · {{ $booking->created_at->format('d M Y') }}</p>
    </div>
</section>
<section class="section-pad">
    <div class="container booking-received-grid">
        <article class="booking-receipt">
            <span class="eyebrow">REQUEST DETAILS</span>
            <div class="booking-receipt-row"><span>Motorcycle</span><strong>{{ $booking->vehicle_snapshot['brand'] }} {{ $booking->vehicle_snapshot['model'] }}@if($booking->vehicle_snapshot['year']) · {{ $booking->vehicle_snapshot['year'] }}@endif</strong></div>
            <div class="booking-receipt-row"><span>Contact phone</span><strong>{{ $booking->customer_phone }}</strong></div>
            <div class="booking-receipt-row"><span>Preferred visit</span><strong>{{ $booking->preferred_start_at?->format('D, d M Y · H:i') ?? 'To be arranged' }} · pending confirmation</strong></div>
            @foreach($booking->appointments->whereNotIn('status', ['cancelled', 'no_show']) as $appointment)<div class="booking-receipt-row"><span>Workshop appointment</span><strong>{{ $appointment->scheduled_start_at->format('D, d M Y · H:i') }}–{{ $appointment->scheduled_end_at->format('H:i') }} · {{ str_replace('_', ' ', ucfirst($appointment->status)) }}</strong></div><div class="booking-receipt-row"><span>Assigned mechanic{{ $appointment->mechanicAssignments->count() === 1 ? '' : 's' }}</span><strong>{{ $appointment->mechanicAssignments->map(fn ($assignment) => $assignment->mechanic->user->name)->join(', ') ?: 'MINEX will assign a mechanic' }}</strong></div>@endforeach
            <div class="booking-receipt-row"><span>Payment preference</span><strong>{{ $booking->payment_percentage }}%</strong></div>
            @if($booking->has_unpriced_items)
                <p class="booking-note">Some selected items need a confirmed price before the workshop can approve the request.</p>
            @endif
            @if($booking->requires_fitment_confirmation)
                <p class="part-warning">A selected part needs fitment confirmation for this motorcycle.</p>
            @endif
            <div class="booking-receipt-items">
                @foreach($booking->items as $item)
                    <article><strong>{{ $item->label_snapshot }}</strong><span>{{ $item->line_total_rupiah ? 'Rp '.number_format($item->line_total_rupiah, 0, ',', '.') : 'Price to be confirmed' }}</span>
                        @if($item->item_type === 'service')<small>Fixed labor quote: {{ $item->labor_price_rupiah !== null ? 'Rp '.number_format($item->labor_price_rupiah, 0, ',', '.') : 'To be confirmed before approval' }} · Actual work time is recorded internally and does not increase this quote.</small>@endif
                        @if($item->item_type === 'package')@php($packageSnapshot = $item->catalog_snapshot ?? [])<small>Configured package base: {{ data_get($packageSnapshot, 'base_price_rupiah') !== null ? 'Rp '.number_format(data_get($packageSnapshot, 'base_price_rupiah'), 0, ',', '.') : 'To be confirmed' }} · Fixed labor: {{ data_get($packageSnapshot, 'labor_price_rupiah') !== null ? 'Rp '.number_format(data_get($packageSnapshot, 'labor_price_rupiah'), 0, ',', '.') : 'To be confirmed' }} · Discount: Rp {{ number_format(data_get($packageSnapshot, 'discount_rupiah', 0), 0, ',', '.') }}</small><ul>@foreach(data_get($packageSnapshot, 'items', []) as $component)@if($component['selected'] ?? false)<li>{{ $component['label'] }} · Qty {{ $component['quantity'] }}@if(!empty($component['part'])) · {{ $component['part']['brand'] ?? 'Unbranded' }} {{ $component['part']['name'] }}@endif @if(!empty($component['variant'])) / {{ $component['variant']['name'] }}@endif · {{ data_get($component, 'component_line_price_rupiah') !== null ? 'Rp '.number_format(data_get($component, 'component_line_price_rupiah'), 0, ',', '.') : 'Component price included in package or awaiting confirmation' }}</li>@endif @endforeach</ul>@endif
                    </article>
                @endforeach
            </div>
            @if($booking->engineBuildConfiguration)<div class="booking-receipt-row"><span>Engine build compatibility</span><strong>{{ str_replace('_', ' ', ucfirst($booking->engineBuildConfiguration->compatibility_status)) }}@if($booking->engineBuildConfiguration->calculated_displacement_cc) · {{ number_format((float) $booking->engineBuildConfiguration->calculated_displacement_cc, 2, ',', '.') }} cc @endif</strong></div>@endif
            @if($booking->engineBreakInProgram)
                @php($breakIn = $booking->engineBreakInProgram)
                <section class="booking-policy"><span class="eyebrow">ENGINE BREAK-IN / {{ strtoupper(str_replace('_', ' ', $breakIn->status)) }}</span><p>Full workshop-day reservation. Fuel is recorded separately at 80% customer contribution and 20% MINEX subsidy. Expenses beyond the customer-approved budget pause the program pending your approval.</p>
                    @if($breakIn->approved_fuel_budget_rupiah !== null)<p>Approved fuel limit: Rp {{ number_format($breakIn->approved_fuel_budget_rupiah, 0, ',', '.') }}.</p>@endif
                    @if($breakIn->pending_fuel_budget_rupiah !== null)<p>Budget proposed: Rp {{ number_format($breakIn->pending_fuel_budget_rupiah, 0, ',', '.') }} · {{ $breakIn->pending_budget_note }}</p><form action="{{ route('portal.bookings.break-in-budget.approve', $booking->id) }}" method="POST">@csrf<button class="button button-primary button-small" type="submit">Approve this fuel budget</button></form>@endif
                    @if($breakIn->fuelExpenses->isNotEmpty())<div class="booking-receipt-items"><strong>Documented fuel expenses</strong>@foreach($breakIn->fuelExpenses as $expense)<article><span>{{ $expense->purchased_at->format('d M Y · H:i') }} · {{ $expense->quantity_liters ? $expense->quantity_liters.' L · ' : '' }}Total Rp {{ number_format($expense->expense_rupiah, 0, ',', '.') }}</span><small>You: Rp {{ number_format($expense->customer_contribution_rupiah, 0, ',', '.') }} · MINEX: Rp {{ number_format($expense->minex_subsidy_rupiah, 0, ',', '.') }}</small><a href="{{ route('portal.bookings.break-in-fuel.receipt', [$booking->id, $expense->id]) }}">View receipt ↗</a></article>@endforeach</div>@endif
                    @include('bookings._break-in-fuel-payment', ['booking' => $booking, 'breakIn' => $breakIn])
                    @if($breakIn->completed_at)<p>Completed {{ $breakIn->completed_at->format('d M Y · H:i') }} · {{ $breakIn->final_inspection_notes }}</p>@endif
                </section>
            @endif
            <a class="button button-outline" href="{{ route('portal.home') }}">Return to your portal <span>↗</span></a>
        </article>
        <aside class="booking-receipt-aside">
            <span class="eyebrow">REQUEST HISTORY</span>
            <ol class="booking-history">
                @foreach($booking->statusHistory as $event)
                    <li><strong>{{ str_replace('_', ' ', ucfirst($event->to_status)) }}</strong><small>{{ $event->created_at->format('d M Y · H:i') }}</small>@if($event->note)<p>{{ $event->note }}</p>@endif</li>
                @endforeach
            </ol>
            <p>Payment instructions are provided only after MINEX confirms the approved amount. Your current request does not collect a payment.</p>
            @if(in_array($booking->status, ['pending_approval', 'awaiting_payment', 'confirmed', 'scheduled'], true))
                @if(!$booking->changeRequests->contains('status', 'pending'))
                    <form class="admin-form" action="{{ route('portal.bookings.change-requests.store', $booking->id) }}" method="POST">@csrf
                        <span class="eyebrow">REQUEST A CHANGE</span>
                        <label>Request type<select name="request_type" required><option value="cancel">Cancel this booking</option>@if($booking->appointments->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->isNotEmpty())<option value="reschedule">Request another appointment time</option>@endif</select></label>
                        @if($booking->appointments->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->isNotEmpty())<label>Requested new arrival<input type="datetime-local" name="requested_start_at" min="{{ now()->addMinutes(30)->format('Y-m-d\TH:i') }}"></label>@endif
                        <label>Note<textarea name="customer_note" rows="2" maxlength="2000" placeholder="Add context for the workshop"></textarea></label>
                        <button class="button button-outline button-small" type="submit">Send request</button>
                    </form>
                @else
                    <p class="booking-note">A booking change request is already waiting for MINEX review.</p>
                @endif
            @endif
            @foreach($booking->changeRequests as $changeRequest)<div class="booking-note"><strong>{{ ucfirst($changeRequest->request_type) }} request · {{ ucfirst($changeRequest->status) }}</strong>@if($changeRequest->requested_start_at)<p>Requested: {{ $changeRequest->requested_start_at->format('D, d M Y · H:i') }}</p>@endif @if($changeRequest->customer_note)<p>{{ $changeRequest->customer_note }}</p>@endif @if($changeRequest->admin_note)<small>MINEX: {{ $changeRequest->admin_note }}</small>@endif</div>@endforeach
        </aside>
    </div>
</section>
<section class="section-pad"><div class="container customer-report-layout">@include('bookings._customer-work-report', ['booking' => $booking])@if($booking->approved_total_rupiah !== null)@include('payments._booking', ['booking' => $booking])@endif</div></section>
@endsection
