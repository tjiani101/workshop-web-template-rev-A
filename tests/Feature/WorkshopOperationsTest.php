<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\InventoryLocation;
use App\Models\MechanicAssignment;
use App\Models\MechanicProfile;
use App\Models\Part;
use App\Models\PartBrand;
use App\Models\PartCategory;
use App\Models\PartVariant;
use App\Models\PaymentRecord;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Models\WorkOrder;
use App\Models\WorkRecommendation;
use App\Services\InventoryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkshopOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mechanic;

    private User $customer;

    private Service $service;

    private ServiceCategory $serviceCategory;

    private PartVariant $variant;

    private MechanicProfile $mechanicProfile;

    private VehicleModel $vehicleModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->userWithRole('owner', 'MINEX Owner');
        $this->mechanic = $this->userWithRole('mechanic', 'Assigned Mechanic');
        $this->customer = $this->userWithRole('customer', 'Rider One');

        $vehicleCategory = VehicleCategory::create(['name' => 'Automatic scooter', 'slug' => 'automatic-scooter', 'is_active' => true]);
        $brand = VehicleBrand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => true]);
        $vehicleModel = VehicleModel::create(['brand_id' => $brand->id, 'category_id' => $vehicleCategory->id, 'name' => 'Test scooter', 'slug' => 'test-scooter', 'is_active' => true]);
        $this->serviceCategory = ServiceCategory::create(['name' => 'Routine maintenance', 'slug' => 'routine-maintenance', 'is_active' => true]);
        $this->service = Service::create(['category_id' => $this->serviceCategory->id, 'name' => 'Routine check', 'slug' => 'routine-check', 'summary' => 'Workshop check.', 'description' => 'A routine workshop check.', 'labor_price_rupiah' => 100000, 'duration_minutes' => 60, 'is_active' => true, 'is_public' => true]);

        $partCategory = PartCategory::create(['name' => 'Brake parts', 'slug' => 'brake-parts', 'is_active' => true]);
        $partBrand = PartBrand::create(['name' => 'Demo Parts', 'slug' => 'demo-parts', 'is_active' => true]);
        $part = Part::create(['category_id' => $partCategory->id, 'brand_id' => $partBrand->id, 'name' => 'Brake pad', 'slug' => 'brake-pad', 'sku' => 'BP-01', 'part_type' => 'replacement', 'selling_price_rupiah' => 30000, 'is_active' => true, 'is_public' => true]);
        $this->variant = PartVariant::create(['part_id' => $part->id, 'name' => 'Standard', 'sku' => 'BP-01-ST', 'selling_price_rupiah' => 30000, 'is_active' => true]);
        $this->mechanicProfile = MechanicProfile::create(['user_id' => $this->mechanic->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $this->mechanicProfile->specializations()->attach($this->serviceCategory->id);
        $this->vehicleModel = $vehicleModel;
    }

    public function test_verified_member_deposit_confirms_booking_and_reserves_selected_parts(): void
    {
        Storage::fake('local');
        $booking = $this->makeAwaitingPaymentBooking();
        $this->actingAs($this->customer)->post(route('bookings.payments.submit', $booking->reference), [
            'amount_rupiah' => 40000,
            'method' => 'bank_transfer',
            'customer_reference' => 'BANK-REF-123',
            'proof' => UploadedFile::fake()->image('transfer.png'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = PaymentRecord::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame('submitted', $payment->status);
        $this->assertSame('verification_pending', $booking->fresh()->payment_status);
        $this->assertNotNull($payment->proof_path);
        $this->actingAs($this->admin)->get(route('admin.payments.proof', $payment))->assertOk();

        $this->post(route('admin.payments.verify', $payment), ['decision' => 'verified', 'note' => 'Matched bank statement.'])->assertSessionHasNoErrors()->assertRedirect();
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertSame(40000, $booking->amount_paid_rupiah);
        $this->assertSame('confirmed', $booking->appointments()->firstOrFail()->status);
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'physical_quantity' => 5, 'reserved_quantity' => 2]);
        $workOrder = WorkOrder::query()->where('booking_id', $booking->id)->with('items')->firstOrFail();
        $this->assertSame('scheduled', $workOrder->status);
        $partLine = $workOrder->items->firstWhere('part_variant_id', $this->variant->id);
        $this->assertNotNull($partLine?->stock_reservation_id);
        $this->assertDatabaseHas('payment_verifications', ['payment_id' => $payment->id, 'decision' => 'verified']);

        $this->actingAs($this->mechanic)->patch(route('mechanic.work-orders.items.update', [$workOrder, $partLine]), ['status' => 'completed', 'actual_minutes' => 20])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'physical_quantity' => 3, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['id' => $partLine->stock_reservation_id, 'status' => 'consumed']);
    }

    public function test_mechanic_inspection_media_is_private_and_customer_can_approve_a_stocked_recommendation(): void
    {
        Storage::fake('local');
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'checked_in']);
        $inspection = $workOrder->inspections()->create(['mechanic_user_id' => $this->mechanic->id, 'status' => 'draft']);
        $finding = $inspection->findings()->create(['category' => 'brakes', 'severity' => 'recommended', 'title' => 'Brake pad wear', 'description' => 'The brake pad is close to its wear limit.', 'technical_finding' => 'Remaining friction material 1.2 mm.', 'created_by' => $this->mechanic->id]);
        $mediaPath = UploadedFile::fake()->image('brake-pad.jpg')->store('minex/inspection-media', 'local');
        $media = $finding->media()->create(['storage_disk' => 'local', 'storage_path' => $mediaPath, 'original_name' => 'brake-pad.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'uploaded_by' => $this->mechanic->id]);
        $booking->update(['status' => 'confirmed', 'approved_total_rupiah' => 100000, 'payment_status' => 'partial']);
        $workOrder->update(['status' => 'waiting_customer_approval']);
        $workOrder->appointment->update(['status' => 'waiting_customer_approval']);
        $recommendation = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'inspection_finding_id' => $finding->id, 'part_id' => $this->variant->part_id, 'part_variant_id' => $this->variant->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Replace worn brake pad', 'reason' => 'The pad is near the safe wear limit.', 'quantity' => 2, 'labor_price_rupiah' => 10000, 'part_price_rupiah' => 30000, 'customer_decision' => 'pending']);
        $this->actingAs($this->mechanic)->get(route('mechanic.work-orders.show', $workOrder))->assertOk()->assertSee($booking->booking_number);
        $this->get(route('mechanic.inspection-media', $media))->assertOk();
        $this->actingAs($this->customer)->get(route('bookings.inspection-media', [$booking->reference, $media->id]))->assertOk();
        $otherCustomer = $this->userWithRole('customer', 'Rider Two');
        $this->actingAs($otherCustomer)->get(route('bookings.inspection-media', [$booking->reference, $media->id]))->assertNotFound();

        $this->actingAs($this->customer)->from(route('bookings.received', $booking->reference))->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'accepted'])->assertSessionHasErrors('decision');
        $this->assertDatabaseMissing('stock_reservations', ['work_recommendation_id' => $recommendation->id]);
        $recommendation->update(['fitment_status' => 'confirmed', 'fitment_note' => 'Matched the pad size to the removed component.', 'fitment_confirmed_by' => $this->mechanic->id, 'fitment_confirmed_at' => now()]);
        $this->actingAs($this->customer)->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'accepted'])->assertRedirect();
        $this->assertSame('accepted', $recommendation->fresh()->customer_decision);
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame('in_progress', $workOrder->appointment()->firstOrFail()->status);
        $this->assertSame(80000, $booking->fresh()->additional_work_rupiah);
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'physical_quantity' => 5, 'reserved_quantity' => 2]);
        $this->assertDatabaseHas('work_order_items', ['recommendation_id' => $recommendation->id, 'stock_reservation_id' => $recommendation->fresh()->workOrder->items()->where('recommendation_id', $recommendation->id)->value('stock_reservation_id')]);
    }

    public function test_customer_can_change_an_unstarted_recommendation_and_stock_and_price_follow_the_final_decision(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'waiting_customer_approval']);
        $recommendation = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'part_id' => $this->variant->part_id, 'part_variant_id' => $this->variant->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Replace brake pad', 'reason' => 'The pad is worn.', 'quantity' => 2, 'labor_price_rupiah' => 10000, 'part_price_rupiah' => 30000, 'customer_decision' => 'pending', 'fitment_status' => 'confirmed']);

        $this->actingAs($this->customer)->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'declined'])->assertSessionHasNoErrors();
        $this->assertSame(0, $booking->fresh()->additional_work_rupiah);
        $this->assertDatabaseCount('stock_reservations', 0);

        $this->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertSame(80000, $booking->fresh()->additional_work_rupiah);
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'reserved_quantity' => 2]);
        $workItem = $workOrder->items()->where('recommendation_id', $recommendation->id)->firstOrFail();

        $this->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'declined'])->assertSessionHasNoErrors();
        $this->assertSame(0, $booking->fresh()->additional_work_rupiah);
        $this->assertDatabaseHas('work_order_items', ['id' => $workItem->id, 'status' => 'cancelled_by_customer']);
        $this->assertDatabaseHas('stock_reservations', ['id' => $workItem->stock_reservation_id, 'status' => 'released']);
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('recommendation_decision_histories', 3);
    }

    public function test_customer_can_accept_all_pending_recommendations_as_one_atomic_decision(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'waiting_customer_approval']);
        $first = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'service_id' => $this->service->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Adjust brake cable', 'reason' => 'Free play is above specification.', 'quantity' => 1, 'labor_price_rupiah' => 12000, 'part_price_rupiah' => 0, 'customer_decision' => 'pending']);
        $second = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'service_id' => $this->service->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Replace spark plug', 'reason' => 'The electrode is worn.', 'quantity' => 1, 'labor_price_rupiah' => 18000, 'part_price_rupiah' => 0, 'customer_decision' => 'pending']);

        $this->actingAs($this->customer)->post(route('bookings.recommendations.decide-all', $booking->reference), ['decision' => 'accepted'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('accepted', $first->fresh()->customer_decision);
        $this->assertSame('accepted', $second->fresh()->customer_decision);
        $this->assertSame(30000, $booking->fresh()->additional_work_rupiah);
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertDatabaseCount('recommendation_decision_histories', 2);
        $this->assertDatabaseCount('work_order_items', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_customer_cannot_change_started_additional_work_but_admin_override_is_audited(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'waiting_customer_approval']);
        $recommendation = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'part_id' => $this->variant->part_id, 'part_variant_id' => $this->variant->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Replace brake pad', 'reason' => 'The pad is worn.', 'quantity' => 1, 'labor_price_rupiah' => 10000, 'part_price_rupiah' => 30000, 'customer_decision' => 'pending', 'fitment_status' => 'confirmed']);

        $this->actingAs($this->customer)->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $workItem = $workOrder->items()->where('recommendation_id', $recommendation->id)->firstOrFail();
        $this->actingAs($this->mechanic)->patch(route('mechanic.work-orders.items.update', [$workOrder, $workItem]), ['status' => 'in_progress'])->assertSessionHasNoErrors();

        $this->actingAs($this->customer)->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'declined'])->assertSessionHasErrors('decision');
        $this->actingAs($this->admin)->post(route('admin.recommendations.decide', $recommendation), ['decision' => 'declined', 'decision_note' => 'Rider called before completion; stop the uncompleted item.'])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('declined', $recommendation->fresh()->customer_decision);
        $this->assertSame(0, $booking->fresh()->additional_work_rupiah);
        $this->assertDatabaseHas('work_order_items', ['id' => $workItem->id, 'status' => 'cancelled_by_admin']);
        $this->assertDatabaseHas('recommendation_decision_histories', ['work_recommendation_id' => $recommendation->id, 'actor_type' => 'admin', 'to_decision' => 'declined']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'event_type' => 'recommendation.admin_override', 'subject_id' => $recommendation->id]);
    }

    public function test_work_order_cannot_be_completed_with_pending_customer_recommendations(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'in_progress']);
        WorkRecommendation::create(['work_order_id' => $workOrder->id, 'mechanic_user_id' => $this->mechanic->id, 'title' => 'Unapproved repair', 'reason' => 'Needs rider approval.', 'quantity' => 1, 'labor_price_rupiah' => 0, 'part_price_rupiah' => 0, 'customer_decision' => 'pending']);
        $item = $workOrder->items()->create(['label_snapshot' => 'Routine check', 'quantity' => 1, 'status' => 'completed']);
        $checklist = $workOrder->checklists()->create(['label' => 'Condition recorded', 'is_complete' => true]);

        $this->actingAs($this->mechanic)->from(route('mechanic.work-orders.show', $workOrder))->post(route('mechanic.work-orders.status', $workOrder), ['status' => 'completed', 'actual_work_minutes' => 45])->assertSessionHasErrors('status');
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertDatabaseHas('work_order_items', ['id' => $item->id, 'status' => 'completed']);
        $this->assertDatabaseHas('work_order_checklists', ['id' => $checklist->id, 'is_complete' => 1]);
    }

    public function test_mechanic_must_confirm_unverified_part_fitment_before_sending_a_recommendation(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $booking->appointments()->firstOrFail()->id, 'status' => 'inspection']);

        $payload = ['part_variant_id' => $this->variant->id, 'title' => 'Replace brake pad', 'reason' => 'The current pad is worn.', 'quantity' => 1, 'urgency' => 'recommended'];
        $this->actingAs($this->mechanic)->from(route('mechanic.work-orders.show', $workOrder))->post(route('mechanic.work-orders.recommendations.store', $workOrder), $payload)->assertSessionHasErrors('fitment_confirmed');
        $this->assertDatabaseCount('work_recommendations', 0);

        $this->actingAs($this->mechanic)->post(route('mechanic.work-orders.recommendations.store', $workOrder), $payload + ['fitment_confirmed' => 1, 'fitment_note' => 'Matched the component to the removed OEM part.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('work_recommendations', ['work_order_id' => $workOrder->id, 'part_variant_id' => $this->variant->id, 'fitment_status' => 'confirmed', 'fitment_confirmed_by' => $this->mechanic->id]);
    }

    public function test_expired_unpaid_booking_is_cancelled_and_verified_partial_payment_refunded(): void
    {
        $booking = $this->makeAwaitingPaymentBooking();
        PaymentRecord::create(['booking_id' => $booking->id, 'payment_reference' => 'PAY-'.Str::upper(Str::random(10)), 'payment_kind' => 'deposit', 'method' => 'bank_transfer', 'amount_rupiah' => 40000, 'status' => 'verified', 'submitted_by' => $this->customer->id, 'submitted_at' => now()]);
        $reservation = app(InventoryService::class)->reserve($this->variant, 2, $this->admin, $booking);
        $this->travel(25)->hours();

        $this->artisan('minex:expire-unpaid-bookings')->expectsOutput('Expired 1 booking(s).')->assertSuccessful();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled', 'payment_status' => 'refund_pending', 'amount_paid_rupiah' => 40000]);
        $this->assertDatabaseHas('appointments', ['booking_id' => $booking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('refunds', ['booking_id' => $booking->id, 'amount_rupiah' => 40000, 'status' => 'pending']);
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id, 'status' => 'released']);
        $this->assertDatabaseHas('stock_levels', ['part_variant_id' => $this->variant->id, 'physical_quantity' => 5, 'reserved_quantity' => 0]);
    }

    private function makeAwaitingPaymentBooking(): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'MX-'.strtoupper(Str::random(10)),
            'reference' => Str::random(40),
            'user_id' => $this->customer->id,
            'customer_type' => 'member',
            'customer_name' => $this->customer->name,
            'customer_email' => $this->customer->email,
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_snapshot' => ['vehicle_model_id' => $this->vehicleModel->id, 'brand' => 'Honda', 'model' => 'Test scooter'],
            'status' => 'awaiting_payment',
            'payment_status' => 'due',
            'payment_percentage' => 25,
            'subtotal_rupiah' => 160000,
            'discount_rupiah' => 0,
            'approved_total_rupiah' => 160000,
            'additional_work_rupiah' => 0,
            'amount_paid_rupiah' => 0,
            'payment_method' => 'bank_transfer',
            'payment_deadline_at' => now()->addHours(24),
            'estimated_duration_minutes' => 60,
            'submitted_at' => now(),
        ]);
        BookingItem::create(['booking_id' => $booking->id, 'service_id' => $this->service->id, 'item_type' => 'service', 'label_snapshot' => $this->service->name, 'quantity' => 1, 'labor_price_rupiah' => 100000, 'line_total_rupiah' => 100000, 'duration_minutes' => 60, 'catalog_snapshot' => ['service' => ['category_id' => $this->serviceCategory->id]]]);
        BookingItem::create(['booking_id' => $booking->id, 'part_id' => $this->variant->part_id, 'part_variant_id' => $this->variant->id, 'item_type' => 'part', 'label_snapshot' => 'Brake pad / Standard', 'quantity' => 2, 'unit_price_rupiah' => 30000, 'line_total_rupiah' => 60000, 'catalog_snapshot' => ['variant' => ['id' => $this->variant->id]]]);
        $appointment = Appointment::create(['booking_id' => $booking->id, 'scheduled_start_at' => now()->addDays(2)->setTime(10, 0), 'scheduled_end_at' => now()->addDays(2)->setTime(11, 0), 'status' => 'reserved', 'reserved_until' => now()->addHours(24), 'created_by' => $this->admin->id]);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $this->mechanicProfile->id, 'assigned_minutes' => 60, 'assigned_by' => $this->admin->id]);
        $location = InventoryLocation::create(['name' => 'Main workshop stock', 'code' => 'MAIN']);
        StockLevel::create(['inventory_location_id' => $location->id, 'part_variant_id' => $this->variant->id, 'physical_quantity' => 5, 'reserved_quantity' => 0, 'reorder_level' => 1]);

        return $booking;
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
