<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('customer'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        DB::transaction(function () use ($request, $user, $validated): void {
            $before = $user->only(['name', 'email', 'phone']);
            $user->update($validated);
            AuditLog::create([
                'actor_id' => $user->id,
                'event_type' => 'customer.profile_updated_self',
                'subject_type' => $user::class,
                'subject_id' => $user->id,
                'metadata' => ['before' => $before, 'after' => $user->only(array_keys($before))],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Your contact details have been updated.');
    }
}
