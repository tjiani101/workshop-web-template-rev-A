<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BusinessSetting;
use App\Models\MechanicProfile;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkshopCapacityService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkshopCapacityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $category;

    private User $mechanicUser;

    private MechanicProfile $mechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail());
        $this->category = ServiceCategory::create(['name' => 'General maintenance', 'slug' => 'general-maintenance', 'is_active' => true]);
        $this->mechanicUser = User::factory()->create(['name' => 'Mechanic One']);
        $this->mechanicUser->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $this->mechanic = MechanicProfile::create(['user_id' => $this->mechanicUser->id, 'daily_capacity_minutes' => 120, 'is_active' => true]);
        $this->mechanic->specializations()->attach($this->category->id);
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [['name' => 'Break', 'start' => '12:00', 'end' => '12:30']]]);
    }

    public function test_admin_approval_saves_server_minimum_and_reserves_capacity(): void
    {
        $booking = $this->booking();
        $this->actingAs($this->admin)->post(route('admin.bookings.approve', $booking), [
            'approved_total_rupiah' => 125000,
            'estimated_duration_minutes' => 60,
            'scheduled_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'mechanic_profile_ids' => [$this->mechanic->id],
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $booking->refresh();
        $this->assertSame('awaiting_payment', $booking->status);
        $this->assertSame('due', $booking->payment_status);
        $this->assertSame(125000, $booking->approved_total_rupiah);
        $this->assertNotNull($booking->payment_deadline_at);
        $this->assertDatabaseHas('appointments', ['booking_id' => $booking->id, 'status' => 'reserved']);
        $this->assertDatabaseHas('mechanic_assignments', ['mechanic_profile_id' => $this->mechanic->id, 'assigned_minutes' => 60]);
    }

    public function test_approval_rejects_total_below_server_computed_booking_amount(): void
    {
        $booking = $this->booking();
        $this->actingAs($this->admin)->from(route('admin.bookings.show', $booking))->post(route('admin.bookings.approve', $booking), [
            'approved_total_rupiah' => 99000,
            'estimated_duration_minutes' => 60,
            'scheduled_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'mechanic_profile_ids' => [$this->mechanic->id],
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasErrors('approved_total_rupiah');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'pending_approval']);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_booking_with_unverified_parts_requires_fitment_review_before_approval(): void
    {
        $booking = $this->booking(requiresFitmentConfirmation: true);
        $payload = [
            'approved_total_rupiah' => 100000,
            'estimated_duration_minutes' => 60,
            'scheduled_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'mechanic_profile_ids' => [$this->mechanic->id],
            'payment_method' => 'bank_transfer',
        ];
        $this->actingAs($this->admin)->from(route('admin.bookings.show', $booking))->post(route('admin.bookings.approve', $booking), $payload + ['fitment_review_note' => 'Checked size and mounting points against the motorcycle.'])->assertSessionHasErrors('fitment_review_confirmed');
        $this->assertSame('pending_approval', $booking->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.bookings.approve', $booking), $payload + ['fitment_review_confirmed' => 1, 'fitment_review_note' => 'Checked size and mounting points against the motorcycle.'])->assertSessionHasNoErrors()->assertRedirect();
        $history = $booking->statusHistory()->latest('id')->firstOrFail();
        $this->assertTrue($history->metadata['fitment_review']['confirmed']);
        $this->assertSame('Checked size and mounting points against the motorcycle.', $history->metadata['fitment_review']['note']);
    }

    public function test_capacity_blocks_overlapping_appointments_and_break_periods(): void
    {
        $service = app(WorkshopCapacityService::class);
        $first = $this->booking();
        $service->reserve($first, Carbon::parse(now()->addDays(2)->setTime(10, 0)), [$this->mechanic->id], 60, false, null, $this->admin->id);

        try {
            $service->reserve($this->booking(), Carbon::parse(now()->addDays(2)->setTime(10, 30)), [$this->mechanic->id], 30, false, null, $this->admin->id);
            $this->fail('An overlapping appointment should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_start_at', $exception->errors());
        }

        try {
            $service->reserve($this->booking(), Carbon::parse(now()->addDays(2)->setTime(12, 0)), [$this->mechanic->id], 45, false, null, $this->admin->id);
            $this->fail('A break overlap should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_start_at', $exception->errors());
        }
        $this->assertSame(1, Appointment::query()->where('status', 'reserved')->count());
    }

    public function test_configured_bay_capacity_blocks_parallel_appointments_for_other_mechanics(): void
    {
        $hours = BusinessSetting::value('operating_hours', []);
        $hours['bay_capacity'] = 1;
        BusinessSetting::setValue('operating_hours', $hours);
        $otherUser = User::factory()->create(['name' => 'Mechanic Two']);
        $otherUser->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $otherMechanic = MechanicProfile::create(['user_id' => $otherUser->id, 'daily_capacity_minutes' => 120, 'is_active' => true]);
        $otherMechanic->specializations()->attach($this->category->id);
        $service = app(WorkshopCapacityService::class);
        $start = Carbon::parse(now()->addDays(2)->setTime(10, 0));
        $service->reserve($this->booking(), $start, [$this->mechanic->id], 60, false, null, $this->admin->id);

        try {
            $service->reserve($this->booking(), $start, [$otherMechanic->id], 60, false, null, $this->admin->id);
            $this->fail('A full workshop bay capacity should reject another appointment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scheduled_start_at', $exception->errors());
        }
    }

    public function test_mechanic_without_required_specialization_needs_reasoned_override(): void
    {
        $otherCategory = ServiceCategory::create(['name' => 'Electrical service', 'slug' => 'electrical-service', 'is_active' => true]);
        $booking = $this->booking($otherCategory->id);

        try {
            app(WorkshopCapacityService::class)->reserve($booking, Carbon::parse(now()->addDays(2)->setTime(10, 0)), [$this->mechanic->id], 30, false, null, $this->admin->id);
            $this->fail('A specialization mismatch should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mechanic_profile_ids', $exception->errors());
        }

        $appointment = app(WorkshopCapacityService::class)->reserve($booking, Carbon::parse(now()->addDays(3)->setTime(10, 0)), [$this->mechanic->id], 30, true, 'Cross-trained for supervised diagnostic work.', $this->admin->id);
        $this->assertTrue((bool) $appointment->mechanicAssignments()->firstOrFail()->specialization_override);
    }

    public function test_rescheduling_a_paid_booking_keeps_confirmation_and_updates_its_work_order(): void
    {
        $booking = $this->booking();
        $booking->update(['status' => 'confirmed', 'approved_total_rupiah' => 100000]);
        $oldAppointment = app(WorkshopCapacityService::class)->reserve($booking, Carbon::parse(now()->addDays(2)->setTime(10, 0)), [$this->mechanic->id], 60, false, null, $this->admin->id);
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $oldAppointment->id, 'status' => 'scheduled']);

        $this->actingAs($this->admin)->patch(route('admin.bookings.reschedule', $booking), [
            'scheduled_start_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'estimated_duration_minutes' => 60,
            'mechanic_profile_ids' => [$this->mechanic->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $newAppointment = Appointment::query()->where('booking_id', $booking->id)->latest('id')->firstOrFail();
        $this->assertSame('cancelled', $oldAppointment->fresh()->status);
        $this->assertSame('confirmed', $newAppointment->status);
        $this->assertNull($newAppointment->reserved_until);
        $this->assertSame($newAppointment->id, $workOrder->fresh()->appointment_id);
    }

    private function booking(?int $categoryId = null, bool $requiresFitmentConfirmation = false): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'MX-'.strtoupper(Str::random(10)),
            'reference' => Str::random(40),
            'customer_type' => 'guest',
            'customer_name' => 'Test Rider',
            'customer_email' => 'rider@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Test'],
            'status' => 'pending_approval',
            'payment_status' => 'not_due',
            'payment_percentage' => 100,
            'subtotal_rupiah' => 100000,
            'discount_rupiah' => 0,
            'estimated_duration_minutes' => 60,
            'requires_fitment_confirmation' => $requiresFitmentConfirmation,
            'submitted_at' => now(),
        ]);
        BookingItem::create(['booking_id' => $booking->id, 'item_type' => 'service', 'label_snapshot' => 'Test service', 'quantity' => 1, 'line_total_rupiah' => 100000, 'duration_minutes' => 60, 'catalog_snapshot' => ['service' => ['category_id' => $categoryId ?? $this->category->id]]]);

        return $booking;
    }
}
