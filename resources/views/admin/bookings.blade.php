@extends('layouts.app')

@section('title', 'Bookings — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / BOOKINGS</p><h1>Booking requests.</h1><p>Review prices, confirm the scope, and reserve mechanics against real workshop capacity.</p></div><a class="text-link" href="{{ route('admin.dashboard') }}">← Workspace</a></div>
    @if(session('status'))<div class="admin-note"><p>{{ session('status') }}</p></div>@endif
    <form class="admin-form admin-form-grid" action="{{ route('admin.bookings.index') }}" method="GET"><label>Search booking, rider, email, or phone<input name="q" value="{{ $query }}" maxlength="120"></label><label>Status<select name="status"><option value="">All statuses</option>@foreach(['pending_approval' => 'Pending approval', 'awaiting_payment' => 'Awaiting payment', 'confirmed' => 'Confirmed', 'scheduled' => 'Scheduled', 'checked_in' => 'Checked in', 'inspection' => 'Inspection', 'waiting_customer_approval' => 'Waiting for rider', 'in_progress' => 'In progress', 'waiting_parts' => 'Waiting for parts', 'overnight' => 'Overnight', 'completed' => 'Finished', 'cancelled' => 'Cancelled', 'no_show' => 'No show', 'rejected' => 'Rejected', 'refunded' => 'Refunded'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label><div><button class="button button-red button-small" type="submit">Filter</button> <a class="text-link" href="{{ route('admin.bookings.index') }}">Clear</a></div></form>
    <div class="admin-section-links"><a class="button button-outline button-small" href="{{ route('admin.calendar.index') }}">Workshop calendar ↗</a></div>
    @forelse($bookings as $booking)<a class="admin-record booking-record-link" href="{{ route('admin.bookings.show', $booking) }}"><div><span class="record-number">{{ $booking->booking_number }} · {{ $booking->submitted_at?->format('d M Y H:i') }}</span><strong class="record-title">{{ $booking->customer_name }}</strong><small>{{ $booking->vehicle_snapshot['brand'] ?? '' }} {{ $booking->vehicle_snapshot['model'] ?? '' }} · {{ count($booking->items) }} line(s)</small></div><span class="record-status">{{ str_replace('_', ' ', $booking->status) }}</span><b>↗</b></a>@empty<div class="empty-state">No bookings match this view.</div>@endforelse
    <div class="admin-pagination">{{ $bookings->links() }}</div>
</div></section>
@endsection
