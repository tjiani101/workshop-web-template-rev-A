@extends('layouts.app')

@section('title', 'Workshop calendar — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / CAPACITY</p><h1>Workshop calendar.</h1><p>Appointments, assigned mechanics, booking status, and estimated work windows.</p></div><a class="text-link" href="{{ route('admin.bookings.index') }}">← Bookings</a></div>
    <form class="admin-form admin-form-grid" action="{{ route('admin.calendar.index') }}" method="GET"><label>Choose a date in the week<input type="date" name="date" value="{{ request('date', today()->toDateString()) }}"></label><div><button class="button button-red button-small" type="submit">Show week</button></div></form>
    <div class="admin-section-links"><a class="button button-outline button-small" href="{{ route('admin.calendar.index', ['date' => $weekStart->copy()->subWeek()->toDateString()]) }}">← Previous week</a><strong>{{ $weekStart->format('d M') }} – {{ $weekEnd->format('d M Y') }}</strong><a class="button button-outline button-small" href="{{ route('admin.calendar.index', ['date' => $weekStart->copy()->addWeek()->toDateString()]) }}">Next week →</a></div>
    @foreach(\Carbon\CarbonPeriod::create($weekStart, $weekEnd) as $date)<section class="admin-model-section"><div class="admin-section-line"><div><p class="eyebrow">{{ strtoupper($date->format('l')) }}</p><h2>{{ $date->format('d F Y') }}</h2></div></div>
        @forelse($appointmentsByDate->get($date->toDateString(), collect()) as $appointment)<a class="admin-record booking-record-link" href="{{ route('admin.bookings.show', $appointment->booking) }}"><div><span class="record-number">{{ $appointment->scheduled_start_at->format('H:i') }}–{{ $appointment->scheduled_end_at->format('H:i') }} · {{ strtoupper(str_replace('_', ' ', $appointment->status)) }}</span><strong class="record-title">{{ $appointment->booking->booking_number }} · {{ $appointment->booking->customer_name }}</strong><small>{{ $appointment->booking->vehicle_snapshot['brand'] ?? '' }} {{ $appointment->booking->vehicle_snapshot['model'] ?? '' }} · {{ $appointment->mechanicAssignments->map(fn ($assignment) => $assignment->mechanic->user->name)->join(', ') ?: 'Mechanic not assigned' }}</small></div><b>↗</b></a>@empty<p class="field-help">No appointment reservations recorded for this date.</p>@endforelse
    </section>@endforeach
</div></section>
@endsection
