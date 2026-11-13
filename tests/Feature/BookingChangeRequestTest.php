<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\BusinessSetting;
use App\Models\MechanicAssignment;
use App\Models\MechanicProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_customer_can_request_cancellation_and_admin_review_records_the_status_change(): void
    {
        $customer = $this->userWithRole('customer', 'Cancel rider');
        $otherCustomer = $this->userWithRole('customer', 'Other rider');
        $admin = $this->userWithRole('admin', 'Workshop admin');
        $booking = $this->booking($customer, 'confirmed');

        $this->actingAs($otherCustomer)->post(route('portal.bookings.change-requests.store', $booking->id), [
            'request_type' => 'cancel',
            'customer_note' => 'Not my booking',
        ])->assertNotFound();
        $this->assertDatabaseCount('booking_change_requests', 0);

        $this->actingAs($customer)->post(route('portal.bookings.change-requests.store', $booking->id), [
            'request_type' => 'cancel',
            'customer_note' => 'Plans changed for that day.',
        ])->assertSessionHasNoErrors();
        $changeRequest = BookingChangeRequest::query()->firstOrFail();
        $this->assertSame('pending', $changeRequest->status);

        $this->actingAs($admin)->patch(route('admin.booking-change-requests.review', $changeRequest), [
            'decision' => 'accepted',
            'admin_note' => 'Cancellation approved; no work has started.',
        ])->assertRedirect();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('booking_change_requests', ['id' => $changeRequest->id, 'status' => 'accepted', 'reviewed_by' => $admin->id]);
        $this->assertDatabaseHas('booking_status_histories', ['booking_id' => $booking->id, 'from_status' => 'confirmed', 'to_status' => 'cancelled', 'changed_by' => $admin->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $customer->id, 'type' => 'booking_change_request_accepted']);
    }

    public function test_accepted_reschedule_rechecks_capacity_and_moves_the_existing_appointment(): void
    {
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '12:00', 'workdays' => range(1, 7), 'breaks' => [], 'bay_capacity' => 1]);
        $customer = $this->userWithRole('customer', 'Reschedule rider');
        $admin = $this->userWithRole('admin', 'Workshop admin');
        $mechanicUser = $this->userWithRole('mechanic', 'Available mechanic');
        $mechanic = MechanicProfile::create(['user_id' => $mechanicUser->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $booking = $this->booking($customer, 'confirmed', 60);
        $scheduled = now()->addDays(3)->startOfDay()->setTime(10, 0);
        $requested = $scheduled->copy()->setTime(11, 0);
        $appointment = Appointment::create([
            'booking_id' => $booking->id,
            'scheduled_start_at' => $scheduled,
            'scheduled_end_at' => $scheduled->copy()->addMinutes(60),
            'status' => 'confirmed',
        ]);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $mechanic->id, 'assigned_minutes' => 60]);
        $this->actingAs($customer)->post(route('portal.bookings.change-requests.store', $booking->id), [
            'request_type' => 'reschedule',
            'requested_start_at' => $requested->format('Y-m-d H:i:s'),
            'customer_note' => 'Could I arrive later?',
        ])->assertSessionHasNoErrors();
        $changeRequest = BookingChangeRequest::query()->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.booking-change-requests.review', $changeRequest), [
            'decision' => 'accepted',
            'admin_note' => 'Moved to the requested arrival time.',
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_change_requests', ['id' => $changeRequest->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('appointments', ['booking_id' => $booking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('appointments', ['booking_id' => $booking->id, 'scheduled_start_at' => $requested->format('Y-m-d H:i:s'), 'status' => 'confirmed']);
    }

    public function test_admin_can_switch_global_mode_and_manually_override_booking_status_with_audited_reason(): void
    {
        $admin = $this->userWithRole('admin', 'Status admin');
        $customer = $this->userWithRole('customer', 'Status rider');
        $booking = $this->booking($customer, 'confirmed');
        $this->actingAs($admin);

        $this->patch(route('admin.workshop-hours.update'), [
            'open' => '09:00',
            'close' => '21:00',
            'bay_capacity' => 2,
            'workdays' => range(1, 6),
            'breaks' => [],
            'approval_deadline_hours' => 24,
            'status_mode' => 'manual',
        ])->assertRedirect();
        $this->assertSame('manual', BusinessSetting::value('booking_status_mode')['mode']);

        $this->patch(route('admin.bookings.status.update', $booking), [
            'status' => 'in_progress',
            'status_control_mode' => 'manual',
            'status_note' => 'Work started after an in-person check-in.',
        ])->assertRedirect();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'in_progress', 'status_control_mode' => 'manual']);
        $this->assertDatabaseHas('booking_status_histories', ['booking_id' => $booking->id, 'from_status' => 'confirmed', 'to_status' => 'in_progress', 'changed_by' => $admin->id, 'note' => 'Work started after an in-person check-in.']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'event_type' => 'booking.status_manually_updated', 'subject_id' => $booking->id]);
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function booking(User $customer, string $status, int $duration = 60): Booking
    {
        return Booking::create([
            'booking_number' => 'MX-'.strtoupper(Str::random(10)),
            'reference' => Str::random(40),
            'user_id' => $customer->id,
            'customer_type' => 'member',
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => '+62 811 2222 3333',
            'vehicle_snapshot' => ['brand' => 'Yamaha', 'model' => 'FreeGo 125', 'year' => 2018, 'variant' => 'S ABS'],
            'status' => $status,
            'payment_status' => 'paid',
            'payment_percentage' => 100,
            'approved_total_rupiah' => 100000,
            'estimated_duration_minutes' => $duration,
            'submitted_at' => now(),
        ]);
    }
}
