<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExpenseAdminController extends Controller
{
    public function index(): View
    {
        return view('admin.expenses', [
            'expenses' => Expense::query()->with(['enteredBy', 'voidedBy'])->latest('expense_date')->latest('id')->paginate(30),
            'monthTotal' => (int) Expense::query()->where('status', 'recorded')->whereBetween('expense_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount_rupiah'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:180'],
            'amount_rupiah' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'in:cash,bank_transfer,qris,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);
        $path = $request->file('receipt')?->store('minex/expense-receipts', 'local');
        $expense = Expense::create([
            'expense_number' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'category' => $data['category'],
            'description' => $data['description'],
            'amount_rupiah' => (int) $data['amount_rupiah'],
            'expense_date' => $data['expense_date'],
            'payment_method' => $data['payment_method'],
            'reference' => $data['reference'] ?? null,
            'receipt_disk' => $path ? 'local' : null,
            'receipt_path' => $path,
            'entered_by' => $request->user()->id,
        ]);
        $audit->record($request, 'expense.recorded', $expense, ['amount_rupiah' => $expense->amount_rupiah, 'category' => $expense->category]);

        return back()->with('status', 'Expense recorded in the financial ledger.');
    }

    public function void(Request $request, Expense $expense, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($expense, $request, $data): void {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            abort_unless($locked->status === 'recorded', 409);
            $locked->update(['status' => 'voided', 'voided_by' => $request->user()->id, 'voided_at' => now(), 'void_reason' => $data['void_reason']]);
        });
        $audit->record($request, 'expense.voided', $expense, ['reason' => $data['void_reason']]);

        return back()->with('status', 'Expense voided. Its original ledger entry is preserved.');
    }

    public function receipt(Expense $expense)
    {
        abort_unless($expense->receipt_disk && $expense->receipt_path && Storage::disk($expense->receipt_disk)->exists($expense->receipt_path), 404);

        return Storage::disk($expense->receipt_disk)->download($expense->receipt_path, basename($expense->receipt_path));
    }
}
