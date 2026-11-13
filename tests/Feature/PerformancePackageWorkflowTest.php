<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BusinessSetting;
use App\Models\MechanicAssignment;
use App\Models\MechanicProfile;
use App\Models\PackageItem;
use App\Models\PackageVehiclePrice;
use App\Models\Part;
use App\Models\PartBrand;
use App\Models\PartCategory;
use App\Models\PartVariant;
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
use App\Models\WorkRecommendation;
use App\Services\LaborPricingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SpecialtyPackageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformancePackageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        BusinessSetting::setValue('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6, 7], 'breaks' => [], 'bay_capacity' => 2]);
    }

    public function test_specialty_templates_cover_bore_up_and_oil_cooler_with_fitment_gates(): void
    {
        $this->seed(SpecialtyPackageTemplateSeeder::class);

        $boreUpPackages = ServicePackage::query()->where('slug', 'like', 'bore-up-%')->get();
        $oilCoolerPackages = ServicePackage::query()->where('slug', 'like', '%oil-cooler-%')->get();
        $this->assertCount(4, $boreUpPackages);
        $this->assertCount(4, $oilCoolerPackages);
        $this->assertTrue($boreUpPackages->every(fn (ServicePackage $package): bool => $package->requires_fitment_confirmation && $package->base_price_rupiah === null && $package->labor_price_rupiah === null));
        $this->assertTrue($oilCoolerPackages->every(fn (ServicePackage $package): bool => $package->requires_fitment_confirmation && $package->items()->where('label', 'Compatible oil pump upgrade')->where('is_required', true)->exists()));
        $this->assertDatabaseHas('packages', ['slug' => 'oem-suzuki-satria-fu-oil-cooler-3-row']);
    }

    public function test_customer_quote_snapshots_bore_up_components_and_configured_oil_pump_by_row_count(): void
    {
        $engineCategory = ServiceCategory::create(['name' => 'Engine Performance & Bore-Up', 'slug' => 'engine-performance-bore-up', 'is_active' => true]);
        $coolingCategory = ServiceCategory::create(['name' => 'Oil Cooling & Lubrication Upgrade', 'slug' => 'oil-cooling-lubrication-upgrade', 'is_active' => true]);
        $vehicleVariant = $this->makeVehicle($engineCategory, $coolingCategory);
        $model = $vehicleVariant->model;
        $brand = PartBrand::create(['name' => 'MINEX Configured Brand', 'slug' => 'minex-configured-brand', 'is_active' => true]);
        $partCategory = PartCategory::create(['name' => 'Engine components', 'slug' => 'engine-components', 'is_active' => true]);
        $block = $this->makePartVariant($partCategory, $brand, 'Bore-up block', 'Verified block variant', 70000, $model);
        $piston = $this->makePartVariant($partCategory, $brand, 'Matched piston kit', 'Matched piston variant', 40000, $model);
        $standard = $this->makePackage($engineCategory, 'Bore Up Standard', 'bore-up-standard', 110000, 45000, true);
        $this->addPackagePart($standard, 'Cylinder block kit', $block);
        $this->addPackagePart($standard, 'Piston kit', $piston);
        PackageItem::create(['package_id' => $standard->id, 'component_type' => 'task', 'label' => 'Installation labor scope', 'quantity' => 1, 'is_required' => true, 'sort_order' => 3]);
        $standard->compatibleModels()->attach($model->id, ['compatibility_status' => 'compatible', 'source_reference' => 'MINEX verification record 001']);

        $cooler = $this->makePartVariant($partCategory, $brand, 'Oil cooler', '6 row model', 120000, $model);
        $pump = $this->makePartVariant($partCategory, $brand, 'Oil pump', 'Verified upgrade', 65000, $model);
        $sixRow = $this->makePackage($coolingCategory, 'Oil Cooler 6 Row', 'oil-cooler-6-row', 185000, 40000, true);
        $this->addPackagePart($sixRow, 'Oil cooler unit / 6 row', $cooler);
        $this->addPackagePart($sixRow, 'Compatible oil pump upgrade', $pump);
        $sixRow->compatibleModels()->attach($model->id, ['compatibility_status' => 'compatible', 'source_reference' => 'MINEX verification record 002']);

        $visit = now()->addDays(3)->setTime(10, 0);
        foreach ([[$standard, $visit], [$sixRow, $visit->copy()->addHour()]] as $iteration => [$package, $preferredAt]) {
            $this->get(route('bookings.create'))->assertOk();
            $token = session('booking_submission_token');
            $response = $this->post(route('bookings.store'), [
                'submission_token' => $token,
                'customer_name' => 'Guest Rider',
                'customer_email' => 'guest@example.test',
                'customer_phone' => '+62 812 0000 0000',
                'vehicle_variant_id' => $vehicleVariant->id,
                'model_year' => 2021,
                'preferred_start_at' => $preferredAt->format('Y-m-d H:i:s'),
                'package_ids' => [$package->id],
                'payment_percentage' => 100,
                'terms_accepted' => 1,
            ])->assertSessionHasNoErrors();
            $response->assertRedirect();

            $this->assertTrue(
                Booking::query()->where('submission_token', $token)->exists(),
                'Booking submission token was not persisted for package '.$package->slug.' on iteration '.$iteration.'.',
            );

            $booking = Booking::query()->where('submission_token', $token)->with('items')->firstOrFail();
            $packageSnapshot = $booking->items->firstOrFail()->catalog_snapshot;
            $this->assertSame($package->slug === 'bore-up-standard' ? null : $package->base_price_rupiah, $packageSnapshot['base_price_rupiah']);
            $this->assertSame($package->slug === 'bore-up-standard' ? null : $package->labor_price_rupiah, $packageSnapshot['labor_price_rupiah']);
            $this->assertSame('MINEX Configured Brand', $packageSnapshot['items'][0]['part']['brand']);
            $this->assertSame($package->slug === 'bore-up-standard' ? 'Bore Up Standard' : 'Oil Cooler 6 Row', $booking->items->first()->label_snapshot);
            $this->assertTrue($booking->requires_fitment_confirmation);
            $receipt = $this->get(route('bookings.received', $booking->reference))->assertOk()->assertSee('MINEX Configured Brand');
            if ($package->slug === 'bore-up-standard') {
                $receipt->assertSee('Package base to be confirmed');
            } else {
                $receipt->assertSee('Package base Rp '.number_format($package->base_price_rupiah, 0, ',', '.'))->assertSee('fixed labor Rp '.number_format($package->labor_price_rupiah, 0, ',', '.'));
            }
            if ($package->slug === 'oil-cooler-6-row') {
                $this->assertSame('Compatible oil pump upgrade', $packageSnapshot['items'][1]['label']);
                $this->assertSame(65000, $packageSnapshot['items'][1]['variant']['selling_price_rupiah']);
            }
        }
    }

    public function test_actual_130_minute_work_does_not_change_the_approved_45000_labor_price(): void
    {
        $category = ServiceCategory::create(['name' => 'CVT / Transmission', 'slug' => 'cvt-transmission', 'is_active' => true]);
        $mechanic = $this->userWithRole('mechanic', 'Assigned mechanic');
        $profile = MechanicProfile::create(['user_id' => $mechanic->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $profile->specializations()->attach($category->id);
        $service = Service::create(['category_id' => $category->id, 'name' => 'CVT service', 'slug' => 'cvt-service', 'summary' => 'CVT work.', 'description' => 'Fixed price CVT work.', 'labor_category' => 'cvt', 'labor_pricing_mode' => 'time_based', 'duration_minutes' => 90, 'is_active' => true, 'is_public' => true]);
        $booking = Booking::factory()->create(['status' => 'in_progress', 'approved_total_rupiah' => 45000, 'additional_work_rupiah' => 0, 'estimated_duration_minutes' => 90]);
        BookingItem::create(['booking_id' => $booking->id, 'service_id' => $service->id, 'item_type' => 'service', 'label_snapshot' => $service->name, 'labor_price_rupiah' => 45000, 'line_total_rupiah' => 45000, 'duration_minutes' => 90, 'catalog_snapshot' => ['service' => ['category_id' => $category->id, 'duration_minutes' => 90]]]);
        $appointment = Appointment::create(['booking_id' => $booking->id, 'scheduled_start_at' => now()->addDays(2)->setTime(9, 0), 'scheduled_end_at' => now()->addDays(2)->setTime(10, 30), 'status' => 'in_progress']);
        MechanicAssignment::create(['appointment_id' => $appointment->id, 'mechanic_profile_id' => $profile->id, 'assigned_minutes' => 90]);
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'appointment_id' => $appointment->id, 'status' => 'in_progress']);

        $this->actingAs($mechanic)->post(route('mechanic.work-orders.status', $workOrder), ['status' => 'completed', 'actual_work_minutes' => 130])->assertSessionHasNoErrors();

        $this->assertSame(130, $workOrder->fresh()->actual_work_minutes);
        $this->assertSame(45000, $booking->fresh()->approved_total_rupiah);
        $this->assertSame(0, $booking->fresh()->additional_work_rupiah);
    }

    public function test_accepting_a_roller_recommendation_changes_only_additional_work(): void
    {
        $customer = $this->userWithRole('customer', 'Rider');
        $mechanic = $this->userWithRole('mechanic', 'Mechanic');
        $booking = Booking::factory()->create(['user_id' => $customer->id, 'status' => 'waiting_customer_approval', 'approved_total_rupiah' => 45000, 'additional_work_rupiah' => 0]);
        $workOrder = WorkOrder::create(['booking_id' => $booking->id, 'status' => 'waiting_customer_approval']);
        $recommendation = WorkRecommendation::create(['work_order_id' => $workOrder->id, 'mechanic_user_id' => $mechanic->id, 'title' => 'Replace CVT roller set', 'reason' => 'The roller set is worn.', 'quantity' => 1, 'labor_price_rupiah' => 10000, 'part_price_rupiah' => 35000, 'customer_decision' => 'pending']);

        $this->actingAs($customer)->post(route('bookings.recommendations.decide', [$booking->reference, $recommendation->id]), ['decision' => 'accepted'])->assertSessionHasNoErrors();

        $this->assertSame(45000, $booking->fresh()->approved_total_rupiah);
        $this->assertSame(45000, $booking->fresh()->additional_work_rupiah);
    }

    public function test_electrical_rate_remains_unavailable_until_admin_confirms_it(): void
    {
        $category = ServiceCategory::create(['name' => 'Electrical', 'slug' => 'electrical', 'is_active' => true]);
        $service = Service::create(['category_id' => $category->id, 'name' => 'Electrical diagnosis', 'slug' => 'electrical-diagnosis', 'summary' => 'Diagnosis.', 'description' => 'Electrical diagnosis.', 'labor_category' => 'electrical_diagnosis', 'labor_pricing_mode' => 'time_based', 'duration_minutes' => 30, 'is_active' => true, 'is_public' => true]);
        $pricing = app(LaborPricingService::class);
        $this->assertNull($pricing->price($service));
        $pricingPage = $this->get(route('pricing'))->assertOk()->assertSee('Electrical diagnosis · proposed')->assertSee('Pending owner confirmation')->assertSee('Electrical diagnosis');
        $this->assertStringNotContainsString('From Rp 25.000 / 30 min standard unit', $pricingPage->getContent());
        $admin = $this->userWithRole('owner', 'MINEX owner');
        $rates = [
            'cvt' => ['amount_rupiah' => 15000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => 1, 'provisional' => 0],
            'frame' => ['amount_rupiah' => 10000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => 1, 'provisional' => 0],
            'engine' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => 1, 'provisional' => 0],
            'electrical_diagnosis' => ['amount_rupiah' => 25000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => 1, 'provisional' => 1],
            'electrical_installation' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => 0, 'provisional' => 1],
        ];
        $this->actingAs($admin)->patch(route('admin.labor-pricing.update'), ['rates' => $rates])->assertSessionHasErrors('rates.electrical_diagnosis.provisional');

        $rates['electrical_diagnosis']['provisional'] = 0;
        $this->patch(route('admin.labor-pricing.update'), ['rates' => $rates])->assertSessionHasNoErrors();
        $this->assertSame(25000, app(LaborPricingService::class)->price($service));
        $activePricingPage = $this->get(route('pricing'))->assertOk();
        $activePricingPage->assertSee('From Rp 25.000 / 30 min standard unit');
    }

    public function test_package_price_edits_do_not_change_a_booking_quote_snapshot(): void
    {
        $package = ServicePackage::create(['name' => 'Bore Up Standard', 'slug' => 'bore-up-standard', 'summary' => 'Configurable package.', 'description' => 'Verified package.', 'base_price_rupiah' => 70000, 'labor_price_rupiah' => 30000, 'is_active' => true, 'is_public' => true]);
        $booking = Booking::factory()->create(['status' => 'awaiting_payment', 'approved_total_rupiah' => 100000]);
        $item = BookingItem::create(['booking_id' => $booking->id, 'package_id' => $package->id, 'item_type' => 'package', 'label_snapshot' => $package->name, 'unit_price_rupiah' => 70000, 'labor_price_rupiah' => 30000, 'line_total_rupiah' => 100000, 'catalog_snapshot' => ['package' => ['slug' => $package->slug], 'base_price_rupiah' => 70000, 'labor_price_rupiah' => 30000, 'items' => []]]);
        $package->update(['base_price_rupiah' => 95000, 'labor_price_rupiah' => 40000]);

        $this->assertSame(100000, $booking->fresh()->approved_total_rupiah);
        $this->assertSame(70000, $item->fresh()->catalog_snapshot['base_price_rupiah']);
        $this->assertSame(30000, $item->fresh()->catalog_snapshot['labor_price_rupiah']);
    }

    public function test_model_specific_package_prices_are_private_until_published_and_match_the_vehicle_year(): void
    {
        $engineCategory = ServiceCategory::create(['name' => 'Engine Performance & Bore-Up', 'slug' => 'engine-performance-bore-up', 'is_active' => true]);
        $coolingCategory = ServiceCategory::create(['name' => 'Oil Cooling & Lubrication Upgrade', 'slug' => 'oil-cooling-lubrication-upgrade', 'is_active' => true]);
        $vehicleVariant = $this->makeVehicle($engineCategory, $coolingCategory);
        $model = $vehicleVariant->model;
        $package = $this->makePackage($engineCategory, 'Bore Up Standard', 'bore-up-standard', 0, 0, true);
        $package->compatibleModels()->attach($model->id, ['compatibility_status' => 'compatible', 'source_reference' => 'MINEX model price fitment']);
        $admin = $this->userWithRole('owner', 'Pricing admin');

        $this->actingAs($admin)->post(route('admin.packages.vehicle-prices.store', $package), [
            'vehicle_model_id' => $model->id,
            'vehicle_generation_id' => $vehicleVariant->vehicle_generation_id,
            'vehicle_variant_id' => $vehicleVariant->id,
            'year_from' => 2021,
            'year_to' => 2021,
            'parts_cost_rupiah' => 900000,
            'parts_selling_rupiah' => 1300000,
            'fixed_labor_rupiah' => 450000,
            'consumables_rupiah' => 50000,
            'additional_costs_rupiah' => 0,
            'discount_rupiah' => 100000,
            'price_source' => 'Draft MINEX supplier assessment',
            'status' => 'draft',
        ])->assertSessionHasNoErrors();
        $price = PackageVehiclePrice::query()->firstOrFail();
        $this->assertSame(900000, $price->parts_cost_rupiah);
        $this->getJson(route('pricing.vehicle-packages', ['vehicle_variant_id' => $vehicleVariant->id, 'model_year' => 2021]))
            ->assertOk()->assertJsonCount(0, 'packages');

        $this->patch(route('admin.packages.vehicle-prices.update', [$package, $price]), [
            'vehicle_model_id' => $model->id,
            'vehicle_generation_id' => $vehicleVariant->vehicle_generation_id,
            'vehicle_variant_id' => $vehicleVariant->id,
            'year_from' => 2021,
            'year_to' => 2021,
            'parts_cost_rupiah' => 950000,
            'parts_selling_rupiah' => 1300000,
            'fixed_labor_rupiah' => 450000,
            'consumables_rupiah' => 50000,
            'additional_costs_rupiah' => 0,
            'discount_rupiah' => 100000,
            'price_source' => 'Verified MINEX supplier configuration 001',
            'status' => 'published',
        ])->assertSessionHasNoErrors();

        $this->getJson(route('pricing.vehicle-packages', ['vehicle_variant_id' => $vehicleVariant->id, 'model_year' => 2021]))
            ->assertOk()->assertJsonPath('packages.0.total_rupiah', 1700000)->assertJsonPath('packages.0.parts_rupiah', 1300000);
        $this->getJson(route('pricing.vehicle-packages', ['vehicle_variant_id' => $vehicleVariant->id, 'model_year' => 2022]))
            ->assertUnprocessable()->assertJsonPath('message', 'Choose a model year supported by this exact factory variant.');
    }

    private function userWithRole(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => Str::lower(Str::random(12)).'@example.test']);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function makeVehicle(ServiceCategory $engineCategory, ServiceCategory $coolingCategory): VehicleVariant
    {
        $vehicleCategory = VehicleCategory::create(['name' => 'Automatic scooter', 'slug' => 'automatic-scooter', 'is_active' => true]);
        $brand = VehicleBrand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => true]);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'category_id' => $vehicleCategory->id, 'name' => 'Test scooter', 'slug' => 'test-scooter', 'is_active' => true]);
        $generation = VehicleGeneration::create(['vehicle_model_id' => $model->id, 'name' => 'Test generation', 'year_from' => 2021, 'year_to' => 2021, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $year = VehicleModelYear::create(['vehicle_model_id' => $model->id, 'vehicle_generation_id' => $generation->id, 'model_year' => 2021, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $variant = VehicleVariant::create(['vehicle_model_id' => $model->id, 'vehicle_generation_id' => $generation->id, 'name' => 'Standard', 'is_active' => true, 'verification_status' => 'verified', 'data_source' => 'https://example.test/model']);
        $variant->years()->attach($year->id);
        $mechanic = $this->userWithRole('mechanic', 'Qualified mechanic');
        $profile = MechanicProfile::create(['user_id' => $mechanic->id, 'daily_capacity_minutes' => 600, 'is_active' => true]);
        $profile->specializations()->attach([$engineCategory->id, $coolingCategory->id]);

        return $variant;
    }

    private function makePartVariant(PartCategory $category, PartBrand $brand, string $partName, string $variantName, int $price, VehicleModel $model): PartVariant
    {
        $part = Part::create(['category_id' => $category->id, 'brand_id' => $brand->id, 'name' => $partName, 'slug' => Str::slug($partName.'-'.Str::random(4)), 'part_type' => 'replacement', 'selling_price_rupiah' => $price, 'is_active' => true, 'is_public' => true]);
        $variant = PartVariant::create(['part_id' => $part->id, 'name' => $variantName, 'selling_price_rupiah' => $price, 'is_active' => true]);
        $variant->compatibleModels()->attach($model->id, ['compatibility_status' => 'compatible', 'source_name' => 'MINEX workshop', 'source_type' => 'workshop', 'source_reference' => 'MINEX verified fitment']);

        return $variant;
    }

    private function addPackagePart(ServicePackage $package, string $label, PartVariant $variant): void
    {
        PackageItem::create(['package_id' => $package->id, 'part_id' => $variant->part_id, 'part_variant_id' => $variant->id, 'component_type' => 'part', 'label' => $label, 'quantity' => 1, 'is_required' => true, 'sort_order' => $package->items()->count() + 1]);
    }

    private function makePackage(ServiceCategory $category, string $name, string $slug, int $basePrice, int $laborPrice, bool $requiresFitment): ServicePackage
    {
        return ServicePackage::create(['category_id' => $category->id, 'name' => $name, 'slug' => $slug, 'summary' => 'Configured motorcycle-specific package.', 'description' => 'Configured components and fixed labor.', 'base_price_rupiah' => $basePrice, 'labor_price_rupiah' => $laborPrice, 'duration_minutes' => 90, 'requires_fitment_confirmation' => $requiresFitment, 'is_active' => true, 'is_public' => true]);
    }
}
