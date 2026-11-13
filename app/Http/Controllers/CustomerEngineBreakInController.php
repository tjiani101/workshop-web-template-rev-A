<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\EngineBreakInFuelExpense;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerEngineBreakInController extends Controller
{
    public function approveBudget(Request $request, int $booking, AuditLogger $audit): RedirectResponse
    {
        $record = $request->user()->bookings()->with('engineBreakInProgram')->findOrFail($booking);
        $this->approveProgramBudget($record, $request, $audit);

        return back()->with('status', 'Fuel budget approved. The break-in program may proceed within this limit.');
    }

    public function approveBudgetByReference(Request $request, string $reference, AuditLogger $audit): RedirectResponse
    {
        $record = Booking::query()->with('engineBreakInProgram')->where('reference', $reference)->firstOrFail();
        if ($record->user_id !== null && $record->user_id !== $request->user()?->id) {
            abort(404);
        }
        $this->approveProgramBudget($record, $request, $audit);

        return back()->with('status', 'Fuel budget approved. The break-in program may proceed within this limit.');
    }

    private function approveProgramBudget(Booking $record, Request $request, AuditLogger $audit): void
    {
        $program = $record->engineBreakInProgram;
        abort_unless($program && in_array($program->status, ['awaiting_budget', 'budget_pending'], true) && $program->pending_fuel_budget_rupiah !== null, 409);
        $previousBudget = $program->approved_fuel_budget_rupiah;
        $program->update([
            'approved_fuel_budget_rupiah' => $program->pending_fuel_budget_rupiah,
            'budget_approved_at' => now(),
            'pending_fuel_budget_rupiah' => null,
            'pending_budget_note' => null,
            'status' => $program->started_at ? 'in_progress' : 'ready',
            'updated_by' => $request->user()->id,
        ]);
        $audit->record($request, 'engine_break_in.budget_customer_approved', $program, ['booking_id' => $record->id, 'previous_budget_rupiah' => $previousBudget, 'approved_budget_rupiah' => $program->approved_fuel_budget_rupiah]);
    }

    public function receipt(Request $request, int $booking, int $expense): StreamedResponse
    {
        $record = $request->user()->bookings()->findOrFail($booking);
        $fuelExpense = EngineBreakInFuelExpense::query()->whereKey($expense)
            ->whereHas('program', fn ($query) => $query->where('booking_id', $record->id))->firstOrFail();

        return Storage::disk('local')->download($fuelExpense->receipt_path);
    }

    public function receiptByReference(Request $request, string $reference, int $expense): StreamedResponse
    {
        $record = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($record->user_id !== null && $record->user_id !== $request->user()?->id) {
            abort(404);
        }
        $fuelExpense = EngineBreakInFuelExpense::query()->whereKey($expense)
            ->whereHas('program', fn ($query) => $query->where('booking_id', $record->id))->firstOrFail();

        return Storage::disk('local')->download($fuelExpense->receipt_path);
    }
}
