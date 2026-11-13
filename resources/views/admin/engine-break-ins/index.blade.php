@extends('layouts.app')

@section('title', 'Engine Break-In Programs — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container">
    <div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / ENGINE BREAK-IN</p><h1>Engine Break-In Programs.</h1><p>Full-day reservations, engine-specific procedure records, customer-approved fuel limits, and receipt-backed 80/20 allocation.</p></div><a class="text-link" href="{{ route('admin.catalog.index') }}">← Catalog</a></div>
    @if(session('status'))<div class="admin-note"><p>{{ session('status') }}</p></div>@endif
    @if($errors->any())<div class="admin-error">{{ $errors->first() }}</div>@endif
    <div class="admin-subnav"><a href="{{ route('admin.bookings.index') }}">Bookings</a><a href="{{ route('admin.mechanics.index') }}">Mechanics &amp; skills</a><a href="{{ route('admin.labor-pricing.index') }}">Labor rates</a></div>
    @forelse($programs as $program)
        @php($booking = $program->booking)
        @php($appointment = $booking->appointments->first())
        @php($expenses = $program->fuelExpenses)
        @php($fuelPaid = (int) $program->fuelPayments->where('status', 'verified')->sum('amount_rupiah'))
        @php($fuelDue = max(0, (int) $expenses->sum('customer_contribution_rupiah') - $fuelPaid))
        <article class="admin-card admin-model-section">
            <p class="eyebrow">{{ $booking->booking_number }} / {{ strtoupper(str_replace('_', ' ', $program->status)) }}</p>
            <h2>{{ $booking->customer_name }} · {{ $booking->vehicle_snapshot['brand'] ?? '' }} {{ $booking->vehicle_snapshot['model'] ?? '' }}</h2>
            <p>Full day: {{ $appointment?->scheduled_start_at?->format('D, d M Y · H:i') ?? 'Not scheduled' }}–{{ $appointment?->scheduled_end_at?->format('H:i') ?? '—' }} · Mechanic: {{ $program->mechanicProfile?->user?->name ?? $appointment?->mechanicAssignments?->map(fn ($assignment) => $assignment->mechanic?->user?->name)->filter()->join(', ') ?? 'Not assigned' }}</p>
            <p class="field-help">Fuel budget: {{ $program->approved_fuel_budget_rupiah !== null ? 'Rp '.number_format($program->approved_fuel_budget_rupiah, 0, ',', '.') : 'Not approved' }} · Recorded expense: Rp {{ number_format($expenses->sum('expense_rupiah'), 0, ',', '.') }} · Customer 80%: Rp {{ number_format($expenses->sum('customer_contribution_rupiah'), 0, ',', '.') }} · MINEX 20%: Rp {{ number_format($expenses->sum('minex_subsidy_rupiah'), 0, ',', '.') }} · Customer payment verified: Rp {{ number_format($fuelPaid, 0, ',', '.') }} · Outstanding: Rp {{ number_format($fuelDue, 0, ',', '.') }}</p>
            @if($program->pending_fuel_budget_rupiah !== null)<div class="admin-note"><strong>Customer approval pending: Rp {{ number_format($program->pending_fuel_budget_rupiah, 0, ',', '.') }}</strong><p>{{ $program->pending_budget_note }}</p></div>@endif

            @if(!$program->started_at && $program->approved_fuel_budget_rupiah === null && $program->pending_fuel_budget_rupiah === null)
                <form class="admin-form admin-form-grid" action="{{ route('admin.engine-break-ins.budget.update', $program) }}" method="POST">@csrf @method('PATCH')<span class="eyebrow span-two">REQUEST INITIAL FUEL BUDGET</span><label>Estimated budget (Rp)<input type="number" name="estimated_fuel_budget_rupiah" min="1" required></label><label>Budget basis / note<input name="budget_note" maxlength="1000" required></label><button class="button button-outline button-small span-two" type="submit">Send budget for customer approval</button></form>
            @endif

            @if($program->status === 'ready' && $appointment)
                <form class="admin-form admin-form-grid" action="{{ route('admin.engine-break-ins.start', $program) }}" method="POST">@csrf<span class="eyebrow span-two">START BREAK-IN</span><label>Assigned mechanic<select name="mechanic_profile_id" required><option value="">Choose the assigned mechanic</option>@foreach($appointment->mechanicAssignments as $assignment)<option value="{{ $assignment->mechanic_profile_id }}" @selected($program->mechanic_profile_id === $assignment->mechanic_profile_id)>{{ $assignment->mechanic?->user?->name }}</option>@endforeach</select></label><label>Engine-specific approved SOP reference<input name="sop_reference" maxlength="180" required placeholder="Approved internal SOP identifier"></label><label>Start odometer (km)<input type="number" name="start_odometer_km" min="0" required></label><label class="span-two">Procedure / operating notes<textarea name="procedure_notes" rows="3" maxlength="5000" required></textarea></label><button class="button button-primary span-two" type="submit">Record start</button></form>
            @elseif($program->status === 'ready')
                <p class="admin-note">A confirmed full-day appointment and assigned mechanic are required before starting.</p>
            @endif

            @if($program->started_at)
                <div class="admin-note"><strong>Start record</strong><p>{{ $program->started_at->format('d M Y · H:i') }} · {{ $program->start_odometer_km }} km · SOP {{ $program->sop_reference }}</p><p>{{ $program->procedure_notes }}</p></div>
            @endif

            @if($program->status === 'in_progress')
                <form class="admin-form admin-form-grid" action="{{ route('admin.engine-break-ins.fuel-expenses.store', $program) }}" method="POST" enctype="multipart/form-data">@csrf<span class="eyebrow span-two">FUEL RECEIPT / APPROVED LIMIT RP {{ number_format($program->approved_fuel_budget_rupiah, 0, ',', '.') }}</span><label>Purchase time<input type="datetime-local" name="purchased_at" required></label><label>Fuel quantity (liters)<input type="number" name="quantity_liters" min="0.001" max="99999" step="0.001"></label><label>Expense (Rp)<input type="number" name="expense_rupiah" min="1" required></label><label>Receipt (image or PDF)<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" required></label><label class="span-two">Note<input name="note" maxlength="1500"></label><button class="button button-outline button-small span-two" type="submit">Record fuel expense</button></form>
                <form class="admin-form admin-form-grid" action="{{ route('admin.engine-break-ins.budget-increase', $program) }}" method="POST">@csrf @method('PATCH')<span class="eyebrow span-two">REQUEST BUDGET INCREASE</span><label>New total limit (Rp)<input type="number" name="pending_fuel_budget_rupiah" min="{{ $program->approved_fuel_budget_rupiah + 1 }}" required></label><label>Reason / projected need<input name="pending_budget_note" maxlength="1000" required></label><button class="button button-outline button-small span-two" type="submit">Pause and ask customer</button></form>
                <form class="admin-form admin-form-grid" action="{{ route('admin.engine-break-ins.complete', $program) }}" method="POST">@csrf<span class="eyebrow span-two">FINAL INSPECTION</span><label>End odometer (km)<input type="number" name="end_odometer_km" min="{{ $program->start_odometer_km }}" required></label><label class="span-two">Inspection findings<textarea name="final_inspection_notes" rows="3" maxlength="5000" required></textarea></label><button class="button button-primary span-two" type="submit">Complete break-in</button></form>
            @endif

            @if($program->completed_at)<div class="admin-note"><strong>Completed {{ $program->completed_at->format('d M Y · H:i') }}</strong><p>Odometer {{ $program->start_odometer_km }}–{{ $program->end_odometer_km }} km · Final inspection {{ $program->final_inspection_at?->format('d M Y · H:i') }}</p><p>{{ $program->final_inspection_notes }}</p></div>@endif
            @if($expenses->isNotEmpty())<div class="booking-item-list"><span class="eyebrow">FUEL RECEIPTS</span>@foreach($expenses as $expense)<div><strong>{{ $expense->purchased_at->format('d M Y · H:i') }} · Rp {{ number_format($expense->expense_rupiah, 0, ',', '.') }}</strong><small>{{ $expense->quantity_liters ? $expense->quantity_liters.' L · ' : '' }}Customer Rp {{ number_format($expense->customer_contribution_rupiah, 0, ',', '.') }} · MINEX Rp {{ number_format($expense->minex_subsidy_rupiah, 0, ',', '.') }} · {{ $expense->note }}</small><a href="{{ route('admin.engine-break-ins.fuel-expenses.receipt', [$program, $expense]) }}">Open receipt ↗</a></div>@endforeach</div>@endif
            <p><a class="text-link" href="{{ route('admin.bookings.show', $booking) }}">Open booking and quote ↗</a></p>
        </article>
    @empty
        <div class="empty-state">No Engine Break-In bookings yet.</div>
    @endforelse
    {{ $programs->links() }}
</div></section>
@endsection
