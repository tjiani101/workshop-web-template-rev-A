<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\MechanicAssignment;
use App\Models\MechanicProfile;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WorkshopClosure;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $category;

    private User $mechanicUser;

    private MechanicProfile $mechanic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->category = ServiceCategory::create(['name' => 'Routine maintenance', 'slug' => 'routine-maintenance', 'is_active' => true]);
        $this->mechanicUser = User::factory()->create(['name' => 'Availability Mechanic']);
        $this->mechanicUser->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $this->mechanic = MechanicProfile::create(['user_id' => $this->mechanicUser->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $this->mechanic->specializations()->attach($this->category->id);
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '12:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [['name' => 'Workshop break', 'start' => '10:00', 'end' => '11:00']], 'bay_capacity' => 1]);
    }

    public function test_availability_uses_selected_work_duration_hours_breaks_and_mechanic_specialization(): void
    {
        $service = $this->service($this->category);
        $date = today()->addDay()->toDateString();

        $this->getJson(route('bookings.availability', ['date' => $date, 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('duration_minutes', 60)->assertJsonCount(6, 'slots')
            ->assertJsonPath('slots.0.start_at', $date.'T09:00')->assertJsonPath('slots.0.status', 'limited')
            ->assertJsonPath('slots.1.status', 'break')->assertJsonPath('slots.4.start_at', $date.'T11:00')
            ->assertJsonPath('slots.5.status', 'closed');

        $otherCategory = ServiceCategory::create(['name' => 'Electrical service', 'slug' => 'electrical-service', 'is_active' => true]);
        $otherService = $this->service($otherCategory, 'Electrical diagnosis');
        $this->getJson(route('bookings.availability', ['date' => $date, 'service_ids' => [$otherService->id]]))
            ->assertOk()->assertJsonCount(6, 'slots')->assertJsonPath('slots.0.status', 'full');
    }

    public function test_shared_operation_time_is_counted_once_for_grouped_services(): void
    {
        $first = $this->service($this->category, 'CVT roller replacement');
        $first->update(['duration_minutes' => 45, 'labor_group' => 'cvt-cover', 'shared_operation_minutes' => 20]);
        $second = $this->service($this->category, 'CVT belt replacement');
        $second->update(['duration_minutes' => 40, 'labor_group' => 'cvt-cover', 'shared_operation_minutes' => 20]);

        $this->getJson(route('bookings.availability', [
            'date' => today()->addDay()->toDateString(),
            'service_ids' => [$first->id, $second->id],
        ]))->assertOk()->assertJsonPath('duration_minutes', 65);
    }

    public function test_availability_removes_existing_mechanic_and_bay_conflicts(): void
    {
        $service = $this->service($this->category);
        $date = today()->addDay();
        $this->travelTo($date->copy()->subDay()->setTime(8, 0));
        $booking = Booking::create([
            'booking_number' => 'MX-'.strtoupper(Str::random(10)),
            'reference' => Str::random(40),
            'customer_type' => 'guest',
            'customer_name' => 'Current rider',
            'customer_email' => 'current@example.test',
            'customer_phone' => '+62 811 2222 3333',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Test scooter'],
            'status' => 'awaiting_payment',
            'payment_status' => 'due',
            'payment_percentage' => 100,
            'approved_total_rupiah' => 100000,
            'payment_deadline_at' => now()->addDay(),
            'estimated_duration_minutes' => 60,
            'submitted_at' => now(),
        ]);
        $appointment = Appointment::create([
            'booking_id' => $booking->id,
            'scheduled_start_at' => Carbon::parse($date)->setTime(11, 0),
            'scheduled_end_at' => Carbon::parse($date)->setTime(12, 0),
            'status' => 'reserved',
        ]);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $this->mechanic->id, 'assigned_minutes' => 60]);

        $this->getJson(route('bookings.availability', ['date' => $date->toDateString(), 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('slots.0.status', 'limited')->assertJsonPath('slots.4.status', 'temporarily_held');
    }

    public function test_availability_can_use_a_team_when_specializations_are_split_between_mechanics(): void
    {
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '13:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [], 'bay_capacity' => 1]);
        $otherCategory = ServiceCategory::create(['name' => 'Electrical service', 'slug' => 'electrical-service', 'is_active' => true]);
        $otherUser = User::factory()->create(['name' => 'Electrical Mechanic']);
        $otherUser->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $otherProfile = MechanicProfile::create(['user_id' => $otherUser->id, 'daily_capacity_minutes' => 60, 'is_active' => true]);
        $otherProfile->specializations()->attach($otherCategory->id);
        $service = $this->service($this->category, 'Routine maintenance');
        $electrical = $this->service($otherCategory, 'Electrical diagnosis');
        $date = today()->addDay()->toDateString();

        $this->getJson(route('bookings.availability', ['date' => $date, 'service_ids' => [$service->id, $electrical->id]]))
            ->assertOk()->assertJsonPath('duration_minutes', 120)->assertJsonCount(8, 'slots')
            ->assertJsonPath('slots.0.status', 'limited')->assertJsonPath('slots.4.start_at', $date.'T11:00')
            ->assertJsonPath('slots.5.status', 'closed');
    }

    public function test_automatic_mode_recommends_a_slot_for_the_selected_duration(): void
    {
        $service = $this->service($this->category);

        $this->getJson(route('bookings.availability', ['mode' => 'automatic', 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('duration_minutes', 60)
            ->assertJsonPath('recommended.duration_minutes', 60)
            ->assertJsonPath('slots.0.status', 'recommended');
    }

    public function test_availability_uses_available_state_when_more_than_one_bay_and_mechanic_are_open(): void
    {
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '12:00', 'workdays' => range(1, 7), 'breaks' => [], 'bay_capacity' => 2]);
        $secondUser = User::factory()->create(['name' => 'Second mechanic']);
        $secondUser->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $secondMechanic = MechanicProfile::create(['user_id' => $secondUser->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $secondMechanic->specializations()->attach($this->category->id);
        $service = $this->service($this->category);
        $date = today()->addDay()->toDateString();

        $this->getJson(route('bookings.availability', ['date' => $date, 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('slots.0.status', 'available');
    }

    public function test_special_closure_blocks_availability_and_special_hours_can_open_a_closed_weekday(): void
    {
        $service = $this->service($this->category);
        $closedDate = today()->addDays(2);
        WorkshopClosure::create(['closure_date' => $closedDate->toDateString(), 'is_closed' => true, 'note' => 'Public holiday']);

        $this->getJson(route('bookings.availability', ['date' => $closedDate->toDateString(), 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('day_status', 'closed')->assertJsonPath('slots', []);

        $specialDate = today()->addDays(3);
        $workdays = array_values(array_filter(range(1, 7), fn (int $day): bool => $day !== $specialDate->isoWeekday()));
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '12:00', 'workdays' => $workdays, 'breaks' => [], 'bay_capacity' => 1]);
        WorkshopClosure::create(['closure_date' => $specialDate->toDateString(), 'is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '12:00', 'note' => 'Special opening']);

        $this->getJson(route('bookings.availability', ['date' => $specialDate->toDateString(), 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('day_status', 'open')->assertJsonPath('slots.0.start_at', $specialDate->toDateString().'T10:00')
            ->assertJsonPath('slots.0.status', 'limited')->assertJsonPath('slots.3.status', 'closed');
    }

    public function test_expired_temporary_hold_does_not_block_a_slot(): void
    {
        $service = $this->service($this->category);
        $date = today()->addDay();
        $booking = Booking::create([
            'booking_number' => 'MX-'.strtoupper(Str::random(10)),
            'reference' => Str::random(40),
            'customer_type' => 'guest',
            'customer_name' => 'Expired hold rider',
            'customer_email' => 'expired@example.test',
            'customer_phone' => '+62 811 2222 3333',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Test scooter'],
            'status' => 'awaiting_payment',
            'payment_status' => 'due',
            'payment_percentage' => 100,
            'approved_total_rupiah' => 100000,
            'payment_deadline_at' => now()->subMinute(),
            'estimated_duration_minutes' => 60,
            'submitted_at' => now()->subDay(),
        ]);
        $appointment = Appointment::create([
            'booking_id' => $booking->id,
            'scheduled_start_at' => Carbon::parse($date)->setTime(9, 0),
            'scheduled_end_at' => Carbon::parse($date)->setTime(10, 0),
            'reserved_until' => now()->subMinute(),
            'status' => 'reserved',
        ]);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $this->mechanic->id, 'assigned_minutes' => 60]);

        $this->getJson(route('bookings.availability', ['date' => $date->toDateString(), 'service_ids' => [$service->id]]))
            ->assertOk()->assertJsonPath('slots.0.start_at', $date->toDateString().'T09:00')
            ->assertJsonPath('slots.0.status', 'limited');
    }

    private function service(ServiceCategory $category, string $name = 'Routine service'): Service
    {
        return Service::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'summary' => 'Configured maintenance work.',
            'description' => 'A test service.',
            'labor_price_rupiah' => 100000,
            'duration_minutes' => 60,
            'is_active' => true,
            'is_public' => true,
        ]);
    }
}
