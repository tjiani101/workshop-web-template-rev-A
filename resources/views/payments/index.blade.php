@extends('layouts.app')

@section('title', 'Payments — MINEX')

@section('content')
<section class="workspace-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>YOUR MINEX / PAYMENTS</p><h1>Payment records.</h1><p>Submit payment information for MINEX to verify. An uploaded receipt never marks a transfer as paid by itself.</p></div><a class="text-link" href="{{ route('portal.home') }}">← Portal</a></div>
    @foreach($bookings as $booking)@include('payments._booking', ['booking' => $booking])@endforeach
    @if($bookings->isEmpty())<div class="empty-state">No approved booking balances are available.</div>@endif
    <div class="admin-pagination">{{ $bookings->links() }}</div>
</div></section>
@endsection
