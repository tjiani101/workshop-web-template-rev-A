<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\MechanicProfile;
use App\Models\PaymentRecord;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function admin(): View
    {
        $today = today();
        $mechanicWorkload = MechanicProfile::query()->with('user')->withSum([
            'assignments as assigned_today_minutes' => fn ($query) => $query->whereHas('appointment', fn ($appointments) => $appointments->whereDate('scheduled_start_at', $today)->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])),
        ], 'assigned_minutes')->where('is_active', true)->orderBy('id')->get();

        return view('dashboards.admin', [
            'customerCount' => User::query()->whereHas('roles', fn ($query) => $query->where('name', 'customer'))->count(),
            'serviceCount' => Service::public()->count(),
            'categoryCount' => ServiceCategory::active()->count(),
            'packageCount' => ServicePackage::public()->count(),
            'todayBookingCount' => Appointment::query()->whereDate('scheduled_start_at', $today)->whereNotIn('status', ['cancelled', 'no_show'])->count(),
            'pendingApprovalCount' => Booking::query()->where('status', 'pending_approval')->count(),
            'activeWorkCount' => WorkOrder::query()->whereIn('status', ['checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight'])->count(),
            'completedTodayCount' => WorkOrder::query()->whereDate('completed_at', $today)->count(),
            'todayRevenue' => (int) PaymentRecord::query()->where('status', 'verified')->whereDate('submitted_at', $today)->sum('amount_rupiah'),
            'todayExpenses' => (int) Expense::query()->where('status', 'recorded')->whereDate('expense_date', $today)->sum('amount_rupiah'),
            'pendingPaymentCount' => PaymentRecord::query()->where('status', 'submitted')->count(),
            'lowStockCount' => StockLevel::query()->whereRaw('physical_quantity - reserved_quantity <= reorder_level')->count(),
            'mechanicWorkload' => $mechanicWorkload,
        ]);
    }

    public function mechanic(): View
    {
        $appointments = Appointment::query()->with(['booking.items', 'booking.workOrder', 'mechanicAssignments.mechanic'])
            ->whereIn('status', ['reserved', 'confirmed', 'scheduled', 'checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed'])
            ->whereHas('mechanicAssignments.mechanic', fn ($query) => $query->where('user_id', auth()->id()))
            ->orderBy('scheduled_start_at')->limit(30)->get();

        return view('dashboards.mechanic', compact('appointments'));
    }

    public function customer(Request $request): View
    {
        return view('dashboards.customer', [
            'bookings' => $request->user()->bookings()->with('items')->latest()->limit(40)->get(),
            'vehicles' => $request->user()->customerVehicles()->with('model.brand', 'model.category', 'variant.generation', 'variant.attributes')->orderByDesc('is_default')->latest()->get(),
            'vehicleCatalogUrl' => route('vehicle-catalog.index'),
        ]);
    }

    public function customerBooking(Request $request, int $bookingId): View
    {
        $booking = $request->user()->bookings()->with(['items', 'statusHistory', 'payments', 'engineBreakInProgram.fuelExpenses', 'engineBreakInProgram.fuelPayments', 'engineBuildConfiguration', 'appointments.mechanicAssignments.mechanic.user', 'changeRequests.reviewer', 'workOrder.inspections.findings.media', 'workOrder.recommendations.mechanicUser', 'workOrder.recommendations.workOrderItems.stockReservation', 'workOrder.recommendations.decisionHistory.actor'])->findOrFail($bookingId);

        return view('bookings.detail', compact('booking'));
    }
}
