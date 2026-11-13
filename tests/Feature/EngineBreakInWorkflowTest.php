<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BusinessSetting;
use App\Models\EngineBreakInProgram;
use App\Models\MechanicAssignment;
use App\Models\MechanicProfile;
use App\Models\PackageItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Models\VehicleModelYear;
use App\Models\VehicleVariant;
use App\Models\WorkOrder;
use App\Services\WorkshopAvailabilityService;
use App\Services\WorkshopCapacityService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EngineBreakInWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mechanic;

    private MechanicProfile $mechanicProfile;

    private ServiceCategory $breakInCategory;

    private ServiceCategory $boreUpCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->userWithRole('owner', 'MINEX owner');
        $this->mechanic = $this->userWithRole('mechanic', 'Authorized mechanic');
        $this->breakInCategory = ServiceCategory::create(['name' => 'Engine Break-In', 'slug' => 'engine-break-in', 'is_active' => true]);
        $this->boreUpCategory = ServiceCategory::create(['name' => 'Engine Performance & Bore-Up', 'slug' => 'engine-performance-bore-up', 'is_active' => true]);
        $this->mechanicProfile = MechanicProfile::create(['user_id' => $this->mechanic->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $this->mechanicProfile->specializations()->attach([$this->breakInCategory->id, $this->boreUpCategory->id]);
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [['start' => '12:00', 'end' => '13:00']], 'bay_capacity' => 1]);
    }

    public function test_full_day_break_in_reserves_open_to_close_and_uses_all_mechanic_capacity(): void
    {
        $booking = $this->makeBookingWithBreakIn();
        $startsAt = now()->addDays(3)->startOfDay()->setTime(9, 0);
        $appointment = app(WorkshopCapacityService::class)->reserve($booking, $startsAt, [$this->mechanicProfile->id], 720, false, null, $this->admin->id);

        $this->assertSame('09:00', $appointment->scheduled_start_at->format('H:i'));
        $this->assertSame('21:00', $appointment->scheduled_end_at->format('H:i'));
        $this->assertSame(600, $appointment->mechanicAssignments()->firstOrFail()->assigned_minutes);
        $this->assertSame([], app(WorkshopAvailabilityService::class)->availableFullDaySlots($startsAt->copy()->startOfDay(), [$this->breakInCategory->id, $this->boreUpCategory->id]));
    }

    public function test_customer_break_in_booking_requires_consent_and_reserves_a_full_day_after_review(): void
    {
        $vehicleCategory = VehicleCategory::create(['name' => 'Automatic scooter', 'slug' => 'automatic-scooter', 'is_active' => true]);
        $vehicleBrand = VehicleBrand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => true]);
        $vehicleModel = VehicleModel::create(['brand_id' => $vehicleBrand->id, 'category_id' => $vehicleCategory->id, 'name' => 'Test scooter', 'slug' => 'test-scooter', 'is_active' => true]);
        $generation = VehicleGeneration::create(['vehicle_model_id' => $vehicleModel->id, 'name' => 'Test generation', 'year_from' => 2021, 'year_to' => 2021, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $year = VehicleModelYear::create(['vehicle_model_id' => $vehicleModel->id, 'vehicle_generation_id' => $generation->id, 'model_year' => 2021, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $variant = VehicleVariant::create(['vehicle_model_id' => $vehicleModel->id, 'vehicle_generation_id' => $generation->id, 'name' => 'Standard', 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $variant->years()->attach($year->id);

        $service = Service::create([
            'category_id' => $this->breakInCategory->id,
            'name' => 'MINEX Engine Break-In Program',
            'slug' => 'minex-engine-break-in-program',
            'summary' => 'A documented full-day break-in service.',
            'description' => 'An authorized mechanic follows the engine-specific SOP.',
            'labor_price_rupiah' => 50000,
            'duration_minutes' => 720,
            'labor_pricing_mode' => 'fixed',
            'requires_full_day' => true,
            'requires_operation_consent' => true,
            'requires_bore_up_package' => true,
            'is_active' => true,
            'is_public' => true,
        ]);
        $package = ServicePackage::create([
            'category_id' => $this->boreUpCategory->id,
            'name' => 'Bore Up Standard',
            'slug' => 'bore-up-standard',
            'summary' => 'A motorcycle-specific Bore-Up build.',
            'description' => 'Configure components and compatibility before approval.',
            'base_price_rupiah' => 100000,
            'labor_price_rupiah' => 75000,
            'duration_minutes' => 60,
            'requires_fitment_confirmation' => true,
            'is_active' => true,
            'is_public' => true,
        ]);
        PackageItem::create(['package_id' => $package->id, 'component_type' => 'task', 'label' => 'Engine build components', 'quantity' => 1, 'is_required' => true, 'sort_order' => 1]);

        $visit = now()->addDays(3)->startOfDay()->setTime(9, 0);
        $this->get(route('bookings.create'))->assertOk();
        $token = session('booking_submission_token');
        $bookingData = [
            'submission_token' => $token,
            'customer_name' => 'Guest Rider',
            'customer_email' => 'break-in@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $variant->id,
            'model_year' => 2021,
            'preferred_start_at' => $visit->format('Y-m-d H:i:s'),
            'service_ids' => [$service->id],
            'package_ids' => [$package->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ];

        $this->from(route('bookings.create'))->post(route('bookings.store'), $bookingData)->assertSessionHasErrors('operation_consent');
        $this->post(route('bookings.store'), $bookingData + ['operation_consent' => 1])->assertRedirect();

        $booking = Booking::query()->where('submission_token', $token)->with('engineBreakInProgram', 'engineBuildConfiguration')->firstOrFail();
        $this->assertNotNull($booking->operation_consent_at);
        $this->assertSame('awaiting_budget', $booking->engineBreakInProgram->status);
        $this->assertSame('unverified', $booking->engineBuildConfiguration->compatibility_status);

        $this->actingAs($this->admin)->post(route('admin.bookings.approve', $booking->id), [
            'approved_total_rupiah' => 225000,
            'estimated_duration_minutes' => 720,
            'scheduled_start_at' => $visit->format('Y-m-d H:i:s'),
            'mechanic_profile_ids' => [$this->mechanicProfile->id],
            'payment_method' => 'bank_transfer',
            'fitment_review_confirmed' => 1,
            'fitment_review_note' => 'Initial fitment review recorded.',
        ])->assertSessionHasErrors('engine_build');
        $this->assertSame('pending_approval', $booking->fresh()->status);

        $appointment = app(WorkshopCapacityService::class)->reserve($booking, $visit, [$this->mechanicProfile->id], 720, false, null, $this->admin->id);
        $this->assertSame('reserved', $appointment->status);
        $this->assertSame('09:00', $appointment->scheduled_start_at->format('H:i'));
        $this->assertSame('21:00', $appointment->scheduled_end_at->format('H:i'));
        $this->assertSame(600, $appointment->mechanicAssignments()->firstOrFail()->assigned_minutes);
    }

    public function test_customer_approves_initial_and_increased_fuel_budget_and_expenses_split_80_20(): void
    {
        Storage::fake('local');
        $booking = $this->makeBookingWithBreakIn();
        $appointment = Appointment::create(['booking_id' => $booking->id, 'scheduled_start_at' => now()->addDays(3)->setTime(9, 0), 'scheduled_end_at' => now()->addDays(3)->setTime(21, 0), 'status' => 'confirmed', 'created_by' => $this->admin->id]);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $this->mechanicProfile->id, 'assigned_minutes' => 600, 'assigned_by' => $this->admin->id]);
        WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $appointment->id, 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        $program = EngineBreakInProgram::create(['booking_id' => $booking->id, 'status' => 'booked', 'customer_consent_at' => now(), 'customer_consent_version' => 'minex-engine-break-in-v1']);

        $this->actingAs($this->admin)->patch(route('admin.engine-break-ins.budget.update', $program), ['estimated_fuel_budget_rupiah' => 150000, 'budget_note' => 'Estimated fuel based on this approved build.'])->assertSessionHasNoErrors();
        $this->post(route('bookings.break-in-budget.approve', $booking->reference))->assertSessionHasNoErrors();
        $this->assertSame('ready', $program->fresh()->status);
        $this->assertSame(150000, $program->fresh()->approved_fuel_budget_rupiah);

        $this->actingAs($this->admin)->post(route('admin.engine-break-ins.start', $program), ['mechanic_profile_id' => $this->mechanicProfile->id, 'sop_reference' => 'MINEX-ENGINE-SOP-2026-01', 'start_odometer_km' => 10000, 'procedure_notes' => 'Followed the engine-specific approved procedure.'])->assertSessionHasNoErrors();
        $program->refresh();
        $this->assertSame('in_progress', $program->status);
        $this->assertSame('MINEX-ENGINE-SOP-2026-01', $program->sop_reference);

        $this->post(route('admin.engine-break-ins.fuel-expenses.store', $program), ['purchased_at' => now()->format('Y-m-d H:i:s'), 'quantity_liters' => 3.2, 'expense_rupiah' => 100000, 'receipt' => UploadedFile::fake()->image('fuel.png'), 'note' => 'Documented fuel purchase.'])->assertSessionHasNoErrors();
        $firstExpense = $program->fuelExpenses()->firstOrFail();
        $this->assertSame(80000, $firstExpense->customer_contribution_rupiah);
        $this->assertSame(20000, $firstExpense->minex_subsidy_rupiah);
        Storage::disk('local')->assertExists($firstExpense->receipt_path);

        $this->from(route('admin.engine-break-ins.index'))->post(route('admin.engine-break-ins.fuel-expenses.store', $program), ['purchased_at' => now()->format('Y-m-d H:i:s'), 'expense_rupiah' => 60000, 'receipt' => UploadedFile::fake()->image('over-budget.png')])->assertSessionHasErrors('expense_rupiah');
        $this->assertSame(1, $program->fuelExpenses()->count());

        $this->actingAs($this->admin)->patch(route('admin.engine-break-ins.budget-increase', $program), ['pending_fuel_budget_rupiah' => 200000, 'pending_budget_note' => 'The engine-specific procedure requires additional operation time and fuel.'])->assertSessionHasNoErrors();
        $this->assertSame('budget_pending', $program->fresh()->status);
        $this->post(route('bookings.break-in-budget.approve', $booking->reference))->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $program->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.engine-break-ins.fuel-expenses.store', $program), ['purchased_at' => now()->format('Y-m-d H:i:s'), 'quantity_liters' => 1.5, 'expense_rupiah' => 50000, 'receipt' => UploadedFile::fake()->image('fuel-two.png')])->assertSessionHasNoErrors();
        $this->assertSame(120000, (int) $program->fuelExpenses()->sum('customer_contribution_rupiah'));
        $this->assertSame(30000, (int) $program->fuelExpenses()->sum('minex_subsidy_rupiah'));

        auth()->logout();
        $this->post(route('bookings.break-in-fuel-payments.store', $booking->reference), ['amount_rupiah' => 120000, 'method' => 'bank_transfer', 'customer_reference' => 'FUEL-TRANSFER-01'])->assertRedirect()->assertSessionHasNoErrors();
        $fuelPayment = $booking->payments()->where('payment_kind', 'engine_break_in_fuel')->firstOrFail();
        $this->actingAs($this->admin)->post(route('admin.payments.verify', $fuelPayment), ['decision' => 'verified', 'note' => 'Matched the separate fuel contribution payment.'])->assertSessionHasNoErrors();
        $this->assertSame(120000, (int) $program->fuelPayments()->where('status', 'verified')->sum('amount_rupiah'));
        $this->assertSame(0, (int) $booking->fresh()->amount_paid_rupiah);

        $this->post(route('admin.engine-break-ins.complete', $program), ['end_odometer_km' => 10125, 'final_inspection_notes' => 'Final inspection completed and recorded.'])->assertSessionHasNoErrors();
        $program->refresh();
        $this->assertSame('completed', $program->status);
        $this->assertSame(10125, $program->end_odometer_km);
        $this->assertNotNull($program->final_inspection_at);
        $this->get(route('bookings.break-in-fuel.receipt', [$booking->reference, $firstExpense->id]))->assertOk();
    }

    private function makeBookingWithBreakIn(): Booking
    {
        $booking = Booking::factory()->create(['status' => 'pending_approval', 'operation_consent_at' => now()]);
        BookingItem::create([
            'booking_id' => $booking->id,
            'item_type' => 'service',
            'label_snapshot' => 'MINEX Engine Break-In Program',
            'duration_minutes' => 720,
            'catalog_snapshot' => ['service' => ['requires_full_day' => true, 'category_id' => $this->breakInCategory->id]],
        ]);
        BookingItem::create([
            'booking_id' => $booking->id,
            'item_type' => 'package',
            'label_snapshot' => 'Bore Up Standard',
            'catalog_snapshot' => ['package' => ['slug' => 'bore-up-standard', 'category_id' => $this->boreUpCategory->id]],
        ]);

        return $booking;
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => Str::lower(Str::random(12)).'@example.test']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
