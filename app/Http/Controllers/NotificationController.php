<?php

namespace App\Http\Controllers;

use App\Models\InternalNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()->internalNotifications()->latest()->paginate(30);

        return view('notifications.index', compact('notifications'));
    }

    public function markRead(Request $request, InternalNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return redirect($notification->url ?: route('portal.notifications.index'));
    }
}
