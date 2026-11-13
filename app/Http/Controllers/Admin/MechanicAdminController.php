<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\MechanicLeaveDay;
use App\Models\MechanicProfile;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WorkshopClosure;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MechanicAdminController extends Controller
{
    public function index(): View
    {
        $mechanics = MechanicProfile::query()->with(['user', 'specializations', 'leaveDays' => fn ($query) => $query->whereDate('leave_date', '>=', today())->orderBy('leave_date')])->orderBy('id')->get();
        $users = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'mechanic'))->whereDoesntHave('mechanicProfile')->orderBy('name')->get();
        $categories = ServiceCategory::query()->orderBy('name')->get();
        $hours = BusinessSetting::value('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]);
        $paymentPolicy = BusinessSetting::value('payment_policy', ['approval_deadline_hours' => 24]);
        $statusMode = BusinessSetting::value('booking_status_mode', ['mode' => 'automatic'])['mode'] ?? 'automatic';
        $closures = WorkshopClosure::query()->whereDate('closure_date', '>=', today())->orderBy('closure_date')->get();

        return view('admin.mechanics', compact('mechanics', 'users', 'categories', 'hours', 'paymentPolicy', 'statusMode', 'closures'));
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::unique('mechanic_profiles', 'user_id')],
            'daily_capacity_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:service_categories,id'],
        ]);
        $user = User::query()->findOrFail($data['user_id']);
        abort_unless($user->hasRole('mechanic'), 422);
        $profile = MechanicProfile::create(['user_id' => $user->id, 'daily_capacity_minutes' => $data['daily_capacity_minutes']]);
        $profile->specializations()->sync($data['category_ids'] ?? []);

        return back()->with('status', 'Mechanic profile created.');
    }

    public function update(Request $request, MechanicProfile $mechanic): RedirectResponse
    {
        $data = $request->validate([
            'daily_capacity_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'is_active' => ['required', 'boolean'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:service_categories,id'],
        ]);
        $mechanic->update(['daily_capacity_minutes' => $data['daily_capacity_minutes'], 'is_active' => $data['is_active']]);
        $mechanic->specializations()->sync($data['category_ids'] ?? []);

        return back()->with('status', 'Mechanic capacity and specializations updated.');
    }

    public function leave(Request $request, MechanicProfile $mechanic): RedirectResponse
    {
        $data = $request->validate(['leave_date' => ['required', 'date', 'after_or_equal:today'], 'reason' => ['nullable', 'string', 'max:180']]);
        MechanicLeaveDay::query()->updateOrCreate(['mechanic_profile_id' => $mechanic->id, 'leave_date' => $data['leave_date']], ['reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id]);

        return back()->with('status', 'Mechanic leave day recorded.');
    }

    public function removeLeave(MechanicProfile $mechanic, MechanicLeaveDay $leave): RedirectResponse
    {
        abort_unless((int) $leave->mechanic_profile_id === (int) $mechanic->id, 404);
        $leave->delete();

        return back()->with('status', 'Mechanic leave day removed.');
    }

    public function updateHours(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'open' => ['required', 'date_format:H:i'],
            'close' => ['required', 'date_format:H:i', 'after:open'],
            'bay_capacity' => ['required', 'integer', 'min:1', 'max:100'],
            'workdays' => ['required', 'array', 'min:1', 'max:7'],
            'workdays.*' => ['integer', 'between:1,7', 'distinct'],
            'breaks' => ['nullable', 'array', 'max:6'],
            'breaks.*.name' => ['nullable', 'string', 'max:50'],
            'breaks.*.start' => ['required_with:breaks.*.end', 'date_format:H:i'],
            'breaks.*.end' => ['required_with:breaks.*.start', 'date_format:H:i', 'after:breaks.*.start'],
            'approval_deadline_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'status_mode' => ['required', Rule::in(['automatic', 'manual'])],
        ]);
        $breaks = collect($data['breaks'] ?? [])->filter(fn ($break) => filled($break['start'] ?? null) && filled($break['end'] ?? null))->map(fn ($break): array => ['name' => $break['name'] ?: 'Workshop break', 'start' => $break['start'], 'end' => $break['end']])->values()->all();
        BusinessSetting::setValue('operating_hours', ['open' => $data['open'], 'close' => $data['close'], 'workdays' => array_map('intval', $data['workdays']), 'breaks' => $breaks, 'bay_capacity' => (int) $data['bay_capacity']]);
        BusinessSetting::setValue('payment_policy', ['approval_deadline_hours' => (int) $data['approval_deadline_hours']]);
        BusinessSetting::setValue('booking_status_mode', ['mode' => $data['status_mode']]);

        return back()->with('status', 'Workshop hours, breaks, payment deadline, and booking status mode updated.');
    }

    public function storeClosure(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'closure_date' => ['required', 'date', 'after_or_equal:today'],
            'is_closed' => ['required', 'boolean'],
            'opens_at' => ['nullable', 'date_format:H:i', 'required_with:closes_at'],
            'closes_at' => ['nullable', 'date_format:H:i', 'required_with:opens_at', 'after:opens_at'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $closure = WorkshopClosure::query()->updateOrCreate(['closure_date' => $data['closure_date']], [
            'is_closed' => (bool) $data['is_closed'],
            'opens_at' => $data['is_closed'] ? null : ($data['opens_at'] ?? null),
            'closes_at' => $data['is_closed'] ? null : ($data['closes_at'] ?? null),
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $audit->record($request, 'workshop.closure_saved', $closure, ['date' => $closure->closure_date->toDateString(), 'closed' => $closure->is_closed]);

        return back()->with('status', 'Workshop special date saved.');
    }

    public function removeClosure(Request $request, WorkshopClosure $closure, AuditLogger $audit): RedirectResponse
    {
        $audit->record($request, 'workshop.closure_removed', $closure, ['date' => $closure->closure_date->toDateString()]);
        $closure->delete();

        return back()->with('status', 'Workshop special date removed.');
    }
}
