<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\EngineBreakInFuelExpense;
use App\Models\EngineBreakInProgram;
use App\Models\MechanicProfile;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EngineBreakInController extends Controller
{
    public function index(): View
    {
        $programs = EngineBreakInProgram::query()->with([
            'booking.customer',
            'booking.appointments' => fn ($query) => $query->whereNotIn('status', ['cancelled', 'no_show'])->latest(),
            'booking.appointments.mechanicAssignments.mechanic.user',
            'booking.workOrder',
            'mechanicProfile.user',
            'fuelExpenses.recorder',
            'fuelPayments',
        ])->latest()->paginate(25);

        return view('admin.engine-break-ins.index', compact('programs'));
    }

    public function setInitialBudget(Request $request, EngineBreakInProgram $program, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['estimated_fuel_budget_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'], 'budget_note' => ['required', 'string', 'max:1000']]);
        abort_if($program->started_at || $program->approved_fuel_budget_rupiah !== null, 409);
        $program->update([
            'estimated_fuel_budget_rupiah' => (int) $data['estimated_fuel_budget_rupiah'],
            'pending_fuel_budget_rupiah' => (int) $data['estimated_fuel_budget_rupiah'],
            'pending_budget_note' => $data['budget_note'],
            'status' => 'awaiting_budget',
            'updated_by' => $request->user()->id,
        ]);
        $audit->record($request, 'engine_break_in.initial_budget_requested', $program, ['amount_rupiah' => (int) $data['estimated_fuel_budget_rupiah'], 'note' => $data['budget_note']]);
        $this->notifyCustomer($program, $notifications, 'Approve Engine Break-In fuel budget', 'MINEX proposed a separate fuel budget of Rp '.number_format((int) $data['estimated_fuel_budget_rupiah'], 0, ',', '.').'. Fuel is charged at 80% to you and 20% to MINEX.');

        return back()->with('status', 'Fuel budget sent to the customer for approval.');
    }

    public function requestBudgetIncrease(Request $request, EngineBreakInProgram $program, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate(['pending_fuel_budget_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'], 'pending_budget_note' => ['required', 'string', 'max:1000']]);
        abort_unless($program->started_at && in_array($program->status, ['in_progress', 'budget_pending'], true) && $program->approved_fuel_budget_rupiah !== null, 409);
        if ((int) $data['pending_fuel_budget_rupiah'] <= $program->approved_fuel_budget_rupiah) {
            throw ValidationException::withMessages(['pending_fuel_budget_rupiah' => 'The requested budget must be higher than the currently approved limit.']);
        }
        $program->update(['pending_fuel_budget_rupiah' => (int) $data['pending_fuel_budget_rupiah'], 'pending_budget_note' => $data['pending_budget_note'], 'status' => 'budget_pending', 'updated_by' => $request->user()->id]);
        $audit->record($request, 'engine_break_in.budget_increase_requested', $program, ['previous_limit_rupiah' => $program->approved_fuel_budget_rupiah, 'requested_limit_rupiah' => (int) $data['pending_fuel_budget_rupiah'], 'note' => $data['pending_budget_note']]);
        $this->notifyCustomer($program, $notifications, 'Approve Engine Break-In budget increase', 'The recorded fuel budget needs to increase to Rp '.number_format((int) $data['pending_fuel_budget_rupiah'], 0, ',', '.').'. The program is paused until you approve or contact MINEX.');

        return back()->with('status', 'The break-in program is paused while the customer reviews the budget increase.');
    }

    public function start(Request $request, EngineBreakInProgram $program, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'mechanic_profile_id' => ['required', 'integer', 'exists:mechanic_profiles,id'],
            'sop_reference' => ['required', 'string', 'max:180'],
            'start_odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'procedure_notes' => ['required', 'string', 'max:5000'],
        ]);
        abort_unless($program->status === 'ready' && $program->approved_fuel_budget_rupiah !== null && $program->customer_consent_at, 409);
        $workOrder = $program->booking()->with('appointments.mechanicAssignments')->firstOrFail()->workOrder;
        abort_unless($workOrder, 409);
        $appointment = Appointment::query()->with('mechanicAssignments')->where('booking_id', $program->booking_id)->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])->latest()->first();
        abort_unless($appointment && $appointment->mechanicAssignments->contains('mechanic_profile_id', (int) $data['mechanic_profile_id']), 422, 'Select an authorized mechanic assigned to this full-day appointment.');
        $mechanic = MechanicProfile::query()->whereKey($data['mechanic_profile_id'])->where('is_active', true)->firstOrFail();
        $before = $program->only(['status', 'mechanic_profile_id', 'approved_fuel_budget_rupiah']);
        $program->update([
            'mechanic_profile_id' => $mechanic->id,
            'status' => 'in_progress',
            'sop_reference' => $data['sop_reference'],
            'start_odometer_km' => (int) $data['start_odometer_km'],
            'procedure_notes' => $data['procedure_notes'],
            'started_at' => now(),
            'updated_by' => $request->user()->id,
        ]);
        $audit->record($request, 'engine_break_in.started', $program, ['before' => $before, 'mechanic_profile_id' => $mechanic->id, 'sop_reference' => $data['sop_reference'], 'start_odometer_km' => (int) $data['start_odometer_km']]);

        return back()->with('status', 'Break-in program started under the recorded engine-specific SOP.');
    }

    public function recordFuelExpense(Request $request, EngineBreakInProgram $program, AuditLogger $audit, NotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'purchased_at' => ['required', 'date'],
            'quantity_liters' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'expense_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'note' => ['nullable', 'string', 'max:1500'],
        ]);
        $receiptPath = $request->file('receipt')->store('engine-break-in-receipts', 'local');
        try {
            $expense = DB::transaction(function () use ($program, $request, $data, $receiptPath): ?EngineBreakInFuelExpense {
                $locked = EngineBreakInProgram::query()->lockForUpdate()->findOrFail($program->id);
                abort_unless($locked->status === 'in_progress' && $locked->approved_fuel_budget_rupiah !== null, 409);
                $priorExpense = (int) $locked->fuelExpenses()->sum('expense_rupiah');
                $priorCustomerShare = (int) $locked->fuelExpenses()->sum('customer_contribution_rupiah');
                $newTotal = $priorExpense + (int) $data['expense_rupiah'];
                if ($newTotal > $locked->approved_fuel_budget_rupiah) {
                    $locked->update([
                        'pending_fuel_budget_rupiah' => $newTotal,
                        'pending_budget_note' => 'A projected fuel expense would exceed the current approved limit. Approve the updated limit before recording this receipt or continuing the program.',
                        'status' => 'budget_pending',
                        'updated_by' => $request->user()->id,
                    ]);

                    return null;
                }
                $customerContribution = (int) round($newTotal * 0.8, 0, PHP_ROUND_HALF_UP) - $priorCustomerShare;

                return $locked->fuelExpenses()->create([
                    'purchased_at' => $data['purchased_at'],
                    'quantity_liters' => $data['quantity_liters'] ?? null,
                    'expense_rupiah' => (int) $data['expense_rupiah'],
                    'customer_contribution_rupiah' => $customerContribution,
                    'minex_subsidy_rupiah' => (int) $data['expense_rupiah'] - $customerContribution,
                    'receipt_path' => $receiptPath,
                    'note' => $data['note'] ?? null,
                    'recorded_by' => $request->user()->id,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($receiptPath);
            throw $exception;
        }
        if ($expense === null) {
            Storage::disk('local')->delete($receiptPath);
            $program->refresh();
            $audit->record($request, 'engine_break_in.budget_increase_requested', $program, ['requested_limit_rupiah' => $program->pending_fuel_budget_rupiah, 'note' => $program->pending_budget_note, 'trigger' => 'expense_budget_check']);
            $this->notifyCustomer($program, $notifications, 'Engine Break-In paused for fuel budget approval', 'A projected fuel expense would exceed the approved limit. Review and approve the updated limit before the program continues.');

            return back()->withErrors(['expense_rupiah' => 'Fuel budget reached. The program is paused while the customer reviews the projected limit.']);
        }
        $audit->record($request, 'engine_break_in.fuel_expense_recorded', $expense, ['program_id' => $program->id, 'expense_rupiah' => $expense->expense_rupiah, 'customer_contribution_rupiah' => $expense->customer_contribution_rupiah, 'minex_subsidy_rupiah' => $expense->minex_subsidy_rupiah]);

        return back()->with('status', 'Fuel receipt recorded with the 80/20 customer and MINEX split.');
    }

    public function complete(Request $request, EngineBreakInProgram $program, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'end_odometer_km' => ['required', 'integer', 'min:0', 'max:9999999'],
            'final_inspection_notes' => ['required', 'string', 'max:5000'],
        ]);
        abort_unless($program->status === 'in_progress' && $program->start_odometer_km !== null, 409);
        if ((int) $data['end_odometer_km'] < $program->start_odometer_km) {
            throw ValidationException::withMessages(['end_odometer_km' => 'The end odometer reading cannot be lower than the recorded start.']);
        }
        $program->update([
            'status' => 'completed',
            'end_odometer_km' => (int) $data['end_odometer_km'],
            'final_inspection_notes' => $data['final_inspection_notes'],
            'final_inspection_at' => now(),
            'completed_at' => now(),
            'updated_by' => $request->user()->id,
        ]);
        $audit->record($request, 'engine_break_in.completed', $program, ['end_odometer_km' => (int) $data['end_odometer_km'], 'final_inspection_notes' => $data['final_inspection_notes']]);

        return back()->with('status', 'Break-in completed and final inspection recorded.');
    }

    public function receipt(EngineBreakInProgram $program, EngineBreakInFuelExpense $expense): StreamedResponse
    {
        abort_unless($expense->engine_break_in_program_id === $program->id, 404);

        return Storage::disk('local')->download($expense->receipt_path);
    }

    private function notifyCustomer(EngineBreakInProgram $program, NotificationService $notifications, string $title, string $body): void
    {
        $customer = $program->booking()->with('customer')->firstOrFail()->customer;
        if ($customer) {
            $notifications->send($customer, 'engine_break_in_budget', $title, $body, route('portal.bookings.show', $program->booking_id), ['booking_id' => $program->booking_id, 'program_id' => $program->id]);
        }
    }
}
