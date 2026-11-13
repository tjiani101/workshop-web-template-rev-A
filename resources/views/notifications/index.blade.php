@extends('layouts.app')

@section('title', 'Notifications — MINEX')

@section('content')
<section class="workspace-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>YOUR MINEX / UPDATES</p><h1>Notifications.</h1><p>Booking, payment, appointment, and workshop updates.</p></div><a class="text-link" href="{{ url()->previous() }}">← Back</a></div>
    @forelse($notifications as $notification)<article class="admin-message {{ $notification->read_at ? 'notification-read' : '' }}"><div class="admin-message-head"><div><span class="eyebrow">{{ $notification->created_at->format('d M Y · H:i') }} / {{ strtoupper(str_replace('_', ' ', $notification->type)) }}</span><h2>{{ $notification->title }}</h2></div>@if(! $notification->read_at)<form action="{{ route('portal.notifications.read', $notification) }}" method="POST">@csrf @method('PATCH')<button class="button button-outline button-small" type="submit">Open and mark read</button></form>@else<a class="text-link" href="{{ $notification->url ?: route('portal.notifications.index') }}">Open ↗</a>@endif</div><p class="admin-message-body">{{ $notification->body }}</p></article>@empty<div class="empty-state">No notifications yet.</div>@endforelse
    <div class="admin-pagination">{{ $notifications->links() }}</div>
</div></section>
@endsection
