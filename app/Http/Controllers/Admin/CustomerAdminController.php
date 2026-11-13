<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerAdminController extends Controller
{
    public function index(Request $request): View
    {
        $customers = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'customer'))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.addcslashes(trim((string) $request->input('search')), '%_\\\\').'%';
                $query->where(fn ($matches) => $matches->where('name', 'like', $search)->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search));
            })
            ->withCount(['customerVehicles', 'bookings'])
            ->with(['customerVehicles.model.brand', 'bookings' => fn ($query) => $query->latest()->limit(3)])
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.customers', compact('customers'));
    }

    public function update(Request $request, int $customer): RedirectResponse
    {
        $record = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'customer'))->findOrFail($customer);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($record->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'customer_status' => ['required', Rule::in(['active', 'suspended'])],
            'customer_admin_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        DB::transaction(function () use ($request, $record, $validated): void {
            $before = $record->only(['name', 'email', 'phone', 'customer_status', 'customer_admin_notes']);
            $record->update($validated);
            AuditLog::create([
                'actor_id' => $request->user()->id,
                'event_type' => 'customer.profile_updated',
                'subject_type' => User::class,
                'subject_id' => $record->id,
                'metadata' => ['before' => $before, 'after' => $record->only(array_keys($before))],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', 'Customer record updated.');
    }
}
