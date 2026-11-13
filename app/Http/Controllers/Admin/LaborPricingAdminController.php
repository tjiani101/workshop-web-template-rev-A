<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Services\AuditLogger;
use App\Services\LaborPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LaborPricingAdminController extends Controller
{
    public function index(LaborPricingService $pricing): View
    {
        return view('admin.labor-pricing', ['rates' => $pricing->rates()]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'rates' => ['required', 'array'],
            'rates.cvt.amount_rupiah' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.frame.amount_rupiah' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.engine.amount_rupiah' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.electrical_diagnosis.amount_rupiah' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.electrical_installation.amount_rupiah' => ['required', 'integer', 'min:0', 'max:100000000'],
            'rates.*.interval_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'rates.*.minimum_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'rates.*.active' => ['required', 'boolean'],
            'rates.*.provisional' => ['required', 'boolean'],
        ]);
        foreach ($validated['rates'] as $key => $rate) {
            if ($rate['minimum_minutes'] < 1) {
                throw ValidationException::withMessages(["rates.{$key}.minimum_minutes" => 'Minimum billable time must be positive.']);
            }
            if (str_starts_with($key, 'electrical_') && $rate['active'] && $rate['provisional']) {
                throw ValidationException::withMessages(["rates.{$key}.provisional" => 'Confirm the proposed electrical rate before activating it.']);
            }
        }
        $before = BusinessSetting::value('labor_rates', []);
        BusinessSetting::setValue('labor_rates', $validated['rates']);
        $audit->record($request, 'labor_rates.updated', null, ['before' => $before, 'after' => $validated['rates']]);

        return back()->with('status', 'Labor rates and billing intervals updated. Existing booking snapshots are unchanged.');
    }
}
