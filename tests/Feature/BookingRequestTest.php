<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\CustomerVehicle;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\WorkshopSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(WorkshopSettingsSeeder::class);
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [], 'bay_capacity' => 2]);
    }

    public function test_guest_booking_uses_server_prices_and_requires_full_payment_after_approval(): void
    {
        [$model, $service] = $this->makeVehicleAndService(450000);
        $this->get(route('bookings.create'))->assertOk();
        $token = session('booking_submission_token');

        $this->post(route('bookings.store'), [
            'submission_token' => $token,
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'service_ids' => [$service->id],
            'payment_percentage' => 100,
            'subtotal_rupiah' => 1,
            'terms_accepted' => 1,
        ])->assertRedirect();

        $booking = Booking::query()->where('submission_token', $token)->with('items', 'statusHistory')->firstOrFail();
        $this->assertSame('guest', $booking->customer_type);
        $this->assertSame('pending_approval', $booking->status);
        $this->assertSame('not_due', $booking->payment_status);
        $this->assertSame(100, $booking->payment_percentage);
        $this->assertSame('10:00', $booking->preferred_start_at->format('H:i'));
        $this->assertSame(450000, $booking->subtotal_rupiah);
        $this->assertSame('Honda', $booking->vehicle_snapshot['brand']);
        $this->assertSame(450000, $booking->items->first()->labor_price_rupiah);
        $this->assertSame('pending_approval', $booking->statusHistory->first()->to_status);
        $this->get(route('bookings.received', $booking->reference))->assertOk()->assertSee($booking->booking_number)->assertSee('Nothing is charged yet.');
    }

    public function test_booking_submission_rejects_a_time_that_is_not_in_current_availability(): void
    {
        [$model, $service] = $this->makeVehicleAndService(100000);
        $this->get(route('bookings.create'));

        $this->from(route('bookings.create'))->post(route('bookings.store'), [
            'submission_token' => session('booking_submission_token'),
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(8, 0)->format('Y-m-d H:i:s'),
            'service_ids' => [$service->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertSessionHasErrors('preferred_start_at');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_guest_booking_rejects_a_trim_not_available_for_the_selected_year(): void
    {
        [$model, $service] = $this->makeVehicleAndService(100000);
        $variant = $model->variants()->firstOrFail();
        $this->get(route('bookings.create'));

        $this->from(route('bookings.create'))->post(route('bookings.store'), [
            'submission_token' => session('booking_submission_token'),
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $variant->id,
            'model_year' => 2022,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'service_ids' => [$service->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertSessionHasErrors('vehicle_variant_id');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_rejects_standalone_part_only_requests(): void
    {
        [$model] = $this->makeVehicleAndService(100000);
        $this->get(route('bookings.create'));

        $this->from(route('bookings.create'))->post(route('bookings.store'), [
            'submission_token' => session('booking_submission_token'),
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'part_id' => 123,
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertSessionHasErrors('service_ids');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_member_booking_keeps_customer_identity_and_selected_payment_percentage(): void
    {
        [$model, $service] = $this->makeVehicleAndService(310000);
        $member = User::factory()->create(['name' => 'Registered Rider', 'email' => 'registered@example.test']);
        $member->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $vehicle = CustomerVehicle::create([
            'user_id' => $member->id,
            'model_id' => $model->id,
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'registration_number' => 'B 1234 DEMO',
            'model_year' => 2021,
            'nickname' => 'Commuter',
        ]);
        $this->actingAs($member);
        $this->get(route('bookings.create'))->assertOk();
        $token = session('booking_submission_token');

        $this->post(route('bookings.store'), [
            'submission_token' => $token,
            'customer_name' => 'Impersonated Name',
            'customer_email' => 'attacker@example.test',
            'customer_phone' => '+62 813 0000 0000',
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'customer_vehicle_id' => $vehicle->id,
            'service_ids' => [$service->id],
            'payment_percentage' => 25,
            'terms_accepted' => 1,
        ])->assertRedirect();

        $booking = Booking::query()->where('submission_token', $token)->firstOrFail();
        $this->assertSame($member->id, $booking->user_id);
        $this->assertSame($member->name, $booking->customer_name);
        $this->assertSame($member->email, $booking->customer_email);
        $this->assertSame($vehicle->id, $booking->customer_vehicle_id);
        $this->assertSame(25, $booking->payment_percentage);
        $this->assertSame(45, $booking->estimated_duration_minutes);
        $this->assertSame('+62 813 0000 0000', $member->fresh()->phone);
        $this->get(route('portal.home'))->assertOk()->assertSee($booking->booking_number);
        $this->get(route('portal.bookings.show', $booking->id))->assertOk()->assertSee($booking->booking_number);

        $anotherMember = User::factory()->create();
        $anotherMember->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $this->actingAs($anotherMember)->get(route('portal.bookings.show', $booking->id))->assertNotFound();
    }

    public function test_member_cannot_attach_another_customers_motorcycle_to_a_booking(): void
    {
        [$model, $service] = $this->makeVehicleAndService(100000);
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $vehicle = CustomerVehicle::create(['user_id' => $owner->id, 'model_id' => $model->id, 'vehicle_variant_id' => $model->variants()->firstOrFail()->id, 'model_year' => 2021, 'nickname' => 'Private bike']);
        $this->actingAs($other);
        $this->get(route('bookings.create'));

        $this->from(route('bookings.create'))->post(route('bookings.store'), [
            'submission_token' => session('booking_submission_token'),
            'customer_phone' => '+62 811 0000 0000',
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'customer_vehicle_id' => $vehicle->id,
            'service_ids' => [$service->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertSessionHasErrors('customer_vehicle_id');
    }

    public function test_package_option_quantity_and_discount_are_calculated_with_integer_rupiah(): void
    {
        [$model] = $this->makeVehicleAndService(0);
        $package = ServicePackage::create([
            'name' => 'Maintenance bundle',
            'slug' => 'maintenance-bundle',
            'summary' => 'Configured maintenance tasks.',
            'description' => 'Configured for the test.',
            'base_price_rupiah' => 400000,
            'labor_price_rupiah' => 100000,
            'discount_rupiah' => 25000,
            'is_active' => true,
            'is_public' => true,
        ]);
        $optionalItem = PackageItem::create([
            'package_id' => $package->id,
            'component_type' => 'cleaning',
            'label' => 'Additional cleaning task',
            'quantity' => 1.5,
            'extra_price_rupiah' => 10000,
            'is_required' => false,
            'sort_order' => 1,
        ]);
        $this->get(route('bookings.create'));
        $token = session('booking_submission_token');

        $this->post(route('bookings.store'), [
            'submission_token' => $token,
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'package_ids' => [$package->id],
            'package_options' => [$package->id => [$optionalItem->id]],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertSessionHasNoErrors();

        $booking = Booking::query()->where('submission_token', $token)->with('items')->firstOrFail();
        $this->assertSame(515000, $booking->subtotal_rupiah);
        $this->assertSame(25000, $booking->discount_rupiah);
        $this->assertSame(490000, $booking->items->first()->line_total_rupiah);
        $this->assertTrue($booking->items->first()->catalog_snapshot['items'][0]['selected']);
    }

    public function test_grouped_cvt_services_charge_and_schedule_shared_opening_once(): void
    {
        [$model, $first] = $this->makeVehicleAndService(0);
        $first->update([
            'name' => 'CVT roller replacement',
            'slug' => 'cvt-roller-replacement',
            'duration_minutes' => 45,
            'labor_category' => 'cvt',
            'labor_pricing_mode' => 'time_based',
            'labor_group' => 'cvt-cover-opening',
            'shared_operation_minutes' => 20,
        ]);
        $second = Service::create([
            'category_id' => $first->category_id,
            'name' => 'CVT belt replacement',
            'slug' => 'cvt-belt-replacement',
            'summary' => 'Replace the drive belt.',
            'description' => 'Includes removal and refitting of the CVT cover.',
            'duration_minutes' => 40,
            'labor_category' => 'cvt',
            'labor_pricing_mode' => 'time_based',
            'labor_group' => 'cvt-cover-opening',
            'shared_operation_minutes' => 20,
            'is_active' => true,
            'is_public' => true,
        ]);
        BusinessSetting::setValue('labor_rates', ['cvt' => ['amount_rupiah' => 15000, 'interval_minutes' => 30, 'minimum_minutes' => 30]]);
        $this->get(route('bookings.create'));
        $token = session('booking_submission_token');

        $this->post(route('bookings.store'), [
            'submission_token' => $token,
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'service_ids' => [$first->id, $second->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertRedirect();

        $booking = Booking::query()->where('submission_token', $token)->with('items')->firstOrFail();
        $this->assertSame(65, $booking->estimated_duration_minutes);
        $this->assertSame(45000, $booking->subtotal_rupiah);
        $this->assertSame(45000, (int) $booking->items->sum('labor_price_rupiah'));
        $this->assertSame('cvt-cover-opening', $booking->items->firstWhere('service_id', $first->id)->catalog_snapshot['service']['labor_group']);
        $this->assertSame(20, $booking->items->firstWhere('service_id', $first->id)->catalog_snapshot['service']['shared_operation_minutes']);
    }

    public function test_booking_form_rejects_an_expired_submission_token(): void
    {
        [$model, $service] = $this->makeVehicleAndService(100000);
        $this->get(route('bookings.create'));

        $this->post(route('bookings.store'), [
            'submission_token' => (string) Str::uuid(),
            'customer_name' => 'Guest Rider',
            'customer_email' => 'guest@example.test',
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_variant_id' => $model->variants()->firstOrFail()->id,
            'model_year' => 2021,
            'preferred_start_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'service_ids' => [$service->id],
            'payment_percentage' => 100,
            'terms_accepted' => 1,
        ])->assertStatus(419);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customers_can_manage_only_their_own_motorcycles(): void
    {
        [$model] = $this->makeVehicleAndService(100000);
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $variant = $model->variants()->firstOrFail();
        $vehicle = CustomerVehicle::create(['user_id' => $owner->id, 'model_id' => $model->id, 'vehicle_variant_id' => $variant->id, 'model_year' => 2021, 'nickname' => 'Commuter']);

        $other = User::factory()->create();
        $other->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $this->actingAs($other)->patch(route('portal.vehicles.update', $vehicle->id), [
            'model_id' => $model->id,
            'nickname' => 'Changed by another rider',
        ])->assertNotFound();

        $this->actingAs($owner)->patch(route('portal.vehicles.update', $vehicle->id), [
            'variant_id' => $variant->id,
            'model_year' => 2021,
            'nickname' => 'Daily commuter',
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_vehicles', ['id' => $vehicle->id, 'nickname' => 'Daily commuter']);
    }

    /** @return array{VehicleModel, Service} */
    private function makeVehicleAndService(int $laborPrice): array
    {
        $vehicleCategory = VehicleCategory::create(['name' => 'Automatic scooter', 'slug' => 'automatic-scooter', 'is_active' => true]);
        $brand = VehicleBrand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => true]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id,
            'category_id' => $vehicleCategory->id,
            'name' => 'Demo scooter',
            'slug' => 'demo-scooter',
            'is_active' => true,
        ]);
        $generation = VehicleGeneration::create([
            'vehicle_model_id' => $model->id,
            'name' => 'Test generation',
            'year_from' => 2021,
            'year_to' => 2021,
            'is_active' => true,
            'verification_status' => 'verified',
            'data_source' => 'https://example.test/model-data',
        ]);
        $year = VehicleModelYear::create([
            'vehicle_model_id' => $model->id,
            'vehicle_generation_id' => $generation->id,
            'model_year' => 2021,
            'is_active' => true,
            'verification_status' => 'verified',
            'data_source' => 'https://example.test/model-data',
        ]);
        $vehicleVariant = VehicleVariant::create([
            'vehicle_model_id' => $model->id,
            'vehicle_generation_id' => $generation->id,
            'name' => 'Standard',
            'is_active' => true,
            'verification_status' => 'verified',
            'data_source' => 'https://example.test/model-data',
        ]);
        $vehicleVariant->years()->attach($year->id);
        $serviceCategory = ServiceCategory::create(['name' => 'Routine maintenance', 'slug' => 'routine-maintenance', 'is_active' => true]);
        $service = Service::create([
            'category_id' => $serviceCategory->id,
            'name' => 'Routine inspection',
            'slug' => 'routine-inspection',
            'summary' => 'A clear service assessment.',
            'description' => 'The technician confirms the work.',
            'labor_price_rupiah' => $laborPrice,
            'duration_minutes' => 45,
            'is_active' => true,
            'is_public' => true,
        ]);
        $mechanic = User::factory()->create(['name' => 'Availability Mechanic']);
        $mechanic->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());
        $profile = MechanicProfile::create(['user_id' => $mechanic->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $profile->specializations()->attach($serviceCategory->id);

        return [$model, $service];
    }
}
