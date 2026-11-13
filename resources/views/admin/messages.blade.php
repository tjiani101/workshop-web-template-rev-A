@extends('layouts.app')

@section('title', 'Contact inbox — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / CONTACT INBOX</p><h1>Messages from riders.</h1><p>Review contact form submissions and keep their handling status current.</p></div><a class="text-link" href="{{ route('admin.dashboard') }}">← Workspace</a></div>
    @forelse($messages as $message)<article class="admin-message"><div class="admin-message-head"><div><span class="eyebrow">{{ $message->created_at->format('d M Y · H:i') }} / {{ strtoupper($message->status) }}</span><h2>{{ $message->subject ?: 'General enquiry' }}</h2><p><strong>{{ $message->name }}</strong> · <a href="mailto:{{ $message->email }}">{{ $message->email }}</a></p></div><form class="admin-message-status" action="{{ route('admin.messages.update', $message) }}" method="POST">@csrf @method('PATCH')<label>Status<select name="status">@foreach(['new', 'read', 'replied', 'closed'] as $status)<option value="{{ $status }}" @selected($message->status === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label><button class="button button-outline button-small" type="submit">Save</button></form></div><p class="admin-message-body">{{ $message->message }}</p></article>@empty<div class="empty-state">No contact messages yet.</div>@endforelse
    <div class="admin-pagination">{{ $messages->links() }}</div>
</div></section>
@endsection
