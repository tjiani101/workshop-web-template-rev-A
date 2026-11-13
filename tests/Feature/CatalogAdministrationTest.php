<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
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
use App\Models\VehicleModel;
use App\Models\WebsiteContent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->signInAsAdmin();
    }

    public function test_admin_can_create_and_publish_service_categories(): void
    {
        $this->post(route('admin.categories.store'), [
            'name' => 'Electrical service',
            'slug' => 'electrical-service',
            'description' => 'Checks for starting and charging circuits.',
            'sort_order' => 4,
            'is_active' => 1,
            'is_featured' => 1,
            'show_on_services' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('service_categories', [
            'slug' => 'electrical-service',
            'is_active' => 1,
            'show_on_home' => 0,
            'show_on_services' => 1,
        ]);
    }

    public function test_package_builder_persists_linked_service_and_required_state(): void
    {
        $category = ServiceCategory::create(['name' => 'Routine care', 'slug' => 'routine-care', 'is_active' => true]);
        $service = Service::create([
            'category_id' => $category->id,
            'name' => 'Engine oil service',
            'slug' => 'engine-oil-service',
            'summary' => 'Oil service for daily riding.',
            'description' => 'Oil and drain plug check.',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->post(route('admin.packages.store'), [
            'name' => 'Daily rider package',
            'summary' => 'A focused maintenance package.',
            'description' => 'The service scope is confirmed at the workshop.',
            'category_id' => $category->id,
            'requires_fitment_confirmation' => 0,
            'is_active' => 1,
            'is_public' => 1,
            'items' => [[
                'component_type' => 'service',
                'label' => 'Engine oil service',
                'service_id' => $service->id,
                'quantity' => 1,
                'is_required' => 1,
            ]],
        ])->assertSessionHasNoErrors();

        $package = ServicePackage::query()->where('slug', 'daily-rider-package')->firstOrFail();
        $this->assertDatabaseHas('package_items', [
            'package_id' => $package->id,
            'service_id' => $service->id,
            'component_type' => 'service',
            'is_required' => 1,
        ]);
    }

    public function test_package_builder_requires_linked_catalog_records_for_linked_types(): void
    {
        $this->from(route('admin.packages.index'))->post(route('admin.packages.store'), [
            'name' => 'Incomplete package',
            'summary' => 'A package used to check validation.',
            'description' => 'This component has no linked service.',
            'items' => [[
                'component_type' => 'service',
                'label' => 'Missing service link',
                'quantity' => 1,
            ]],
        ])->assertSessionHasErrors('items.0.service_id');

        $this->assertDatabaseMissing('packages', ['slug' => 'incomplete-package']);
    }

    public function test_part_variants_save_normalized_attributes_and_can_be_updated(): void
    {
        $this->post(route('admin.parts.store'), [
            'name' => 'Variator roller set',
            'sku' => 'MINEX-ROLLER-01',
            'part_type' => 'aftermarket',
            'category_name' => 'Rollers',
            'brand_name' => 'Roadline',
            'selling_price_rupiah' => 135000,
            'is_active' => 1,
            'variants' => [[
                'name' => '12 gram set',
                'sku' => 'MINEX-ROLLER-12G',
                'variant_code' => 'R12',
                'selling_price_rupiah' => 135000,
                'attributes' => [['name' => 'Weight', 'value' => '12', 'unit' => 'g']],
            ]],
        ])->assertSessionHasNoErrors();

        $part = Part::query()->where('sku', 'MINEX-ROLLER-01')->firstOrFail();
        $variant = PartVariant::query()->where('sku', 'MINEX-ROLLER-12G')->firstOrFail();
        $this->assertDatabaseHas('product_attributes', ['slug' => 'weight', 'unit' => 'g']);
        $this->assertDatabaseHas('product_attribute_values', ['attribute_id' => $variant->attributeValues()->firstOrFail()->attribute_id, 'value' => '12']);

        $this->patch(route('admin.parts.update', $part), [
            'name' => $part->name,
            'sku' => $part->sku,
            'part_type' => $part->part_type,
            'category_name' => $part->category->name,
            'brand_name' => $part->brand->name,
            'selling_price_rupiah' => 140000,
            'is_active' => 1,
            'variants' => [[
                'id' => $variant->id,
                'name' => '12 gram set',
                'sku' => 'MINEX-ROLLER-12G',
                'variant_code' => 'R12',
                'selling_price_rupiah' => 140000,
                'is_active' => 1,
                'attributes' => [['name' => 'Weight', 'value' => '12', 'unit' => 'g']],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('part_variants', ['id' => $variant->id, 'selling_price_rupiah' => 140000]);
    }

    public function test_admin_can_edit_website_content_and_handle_contact_messages(): void
    {
        Storage::fake('public');
        $this->patch(route('admin.site-content.update'), [
            'content' => [
                'home_hero' => ['eyebrow' => 'MINEX WORKSHOP', 'title' => 'Care for every ride.', 'body' => 'Clear service for daily motorcycles.'],
                'home_intro' => ['eyebrow' => 'OUR APPROACH', 'title' => 'Understand first.', 'body' => 'We explain every recommendation.'],
                'home_principles' => ['eyebrow' => 'OUR STANDARD', 'title' => 'Clear work.', 'body' => 'Choices are explained.', 'item_one_title' => 'Inspect', 'item_one_body' => 'Look first.', 'item_two_title' => 'Explain', 'item_two_body' => 'Show options.', 'item_three_title' => 'Approve', 'item_three_body' => 'Rider decides.'],
                'home_process' => ['eyebrow' => 'OUR PROCESS', 'title' => 'Three clear steps.', 'step_one_title' => 'Request', 'step_one_body' => 'Tell us.', 'step_two_title' => 'Inspect', 'step_two_body' => 'We check.', 'step_three_title' => 'Approve', 'step_three_body' => 'You choose.'],
                'home_gallery' => ['eyebrow' => 'IN THE WORKSHOP', 'title' => 'Careful details.', 'body' => 'Motorcycle work.', 'caption_one' => 'Inspection', 'caption_two' => 'Maintenance', 'caption_three' => 'Handover'],
                'home_announcement' => ['eyebrow' => 'MINEX UPDATE', 'title' => 'Workshop update', 'body' => 'Hours are changing next week.'],
                'home_cta' => ['eyebrow' => 'READY WHEN YOU ARE', 'title' => 'Talk to the workshop.', 'button' => 'Contact MINEX'],
                'about' => ['title' => 'A careful workshop.', 'body' => 'Clear choices and thoughtful work.'],
                'contact' => ['email' => 'service@example.test', 'phone' => '', 'whatsapp' => '', 'address' => '', 'hours' => 'By appointment'],
            ],
            'published' => ['home_hero' => 1, 'home_intro' => 1, 'home_principles' => 1, 'home_process' => 1, 'home_gallery' => 1, 'home_announcement' => 1, 'home_cta' => 1, 'about' => 1, 'contact' => 1],
            'gallery_images' => [0 => UploadedFile::fake()->image('workshop.jpg')],
        ])->assertSessionHasNoErrors();

        $homeHero = WebsiteContent::query()->where('content_key', 'home.hero')->firstOrFail();
        $this->assertSame('Care for every ride.', $homeHero->content['title']);
        $gallery = WebsiteContent::query()->where('content_key', 'home.gallery')->firstOrFail();
        Storage::disk('public')->assertExists($gallery->content['images'][0]);
        $this->get(route('home'))->assertOk()->assertSee('Workshop update')->assertSee('Inspect');

        $message = ContactMessage::query()->create([
            'name' => 'Rider One',
            'email' => 'rider@example.test',
            'subject' => 'Service enquiry',
            'message' => 'Please tell me about your inspection process.',
        ]);

        $this->patch(route('admin.messages.update', $message), ['status' => 'replied'])->assertRedirect();
        $this->assertDatabaseHas('contact_messages', ['id' => $message->id, 'status' => 'replied']);
    }

    public function test_catalog_management_screens_render_for_an_administrator(): void
    {
        $this->get(route('admin.catalog.index'))->assertOk();
        $this->get(route('admin.categories.index'))->assertOk();
        $this->get(route('admin.services.index'))->assertOk();
        $this->get(route('admin.packages.index'))->assertOk();
        $this->get(route('admin.parts.index'))->assertOk();
        $this->get(route('admin.site-content.index'))->assertOk();
        $this->get(route('admin.messages.index'))->assertOk();
        $this->get(route('admin.vehicles.index'))->assertOk();
        $this->get(route('admin.compatibility.index'))->assertOk();
        $this->get(route('admin.calendar.index'))->assertOk();
    }

    public function test_public_standalone_parts_storefront_and_purchase_routes_are_unavailable(): void
    {
        $this->get('/parts')->assertNotFound();
        $this->get('/parts/roller-weights')->assertNotFound();
        $this->post('/parts/roller-weights/selection', [])->assertNotFound();
        $this->delete('/parts/selection/1')->assertNotFound();
        $this->get(route('admin.parts.index'))->assertOk();
    }

    public function test_fitment_requires_a_source_before_marking_a_variant_compatible(): void
    {
        [$part, $variant, $vehicle] = $this->makeCatalogOption();

        $this->from(route('admin.compatibility.index'))->post(route('admin.compatibility.update', $variant), [
            'vehicle_model_id' => $vehicle->id,
            'compatibility_status' => 'compatible',
            'source_type' => 'unverified',
        ])->assertSessionHasErrors(['source_name', 'source_reference']);

        $this->post(route('admin.compatibility.update', $variant), [
            'vehicle_model_id' => $vehicle->id,
            'compatibility_status' => 'compatible',
            'source_type' => 'aftermarket_catalog',
            'source_name' => 'Roadline application catalog',
            'source_reference' => 'Manufacturer parts catalog, reference 42',
            'part_number' => 'RL-ROLLER-12G',
            'compatible_year_from' => 2020,
            'compatible_year_to' => 2024,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('part_variant_vehicle_model', [
            'part_variant_id' => $variant->id,
            'vehicle_model_id' => $vehicle->id,
            'compatibility_status' => 'compatible',
            'source_type' => 'aftermarket_catalog',
            'source_name' => 'Roadline application catalog',
            'part_number' => 'RL-ROLLER-12G',
            'compatible_year_from' => 2020,
            'compatible_year_to' => 2024,
            'verified_by' => auth()->id(),
        ]);
    }

    public function test_service_options_save_component_quantity_duration_and_required_state(): void
    {
        [$part, $variant] = $this->makeCatalogOption();
        $category = ServiceCategory::create(['name' => 'CVT service', 'slug' => 'cvt-service', 'is_active' => true]);

        $this->post(route('admin.services.store'), [
            'category_id' => $category->id,
            'name' => 'CVT roller replacement',
            'summary' => 'Replace rollers as workshop work.',
            'description' => 'Remove and inspect the CVT before fitting the selected roller variant.',
            'labor_price_rupiah' => 15000,
            'duration_minutes' => 30,
            'shared_operation_minutes' => 15,
            'labor_category' => 'cvt',
            'labor_pricing_mode' => 'time_based',
            'labor_group' => 'cvt-cover-opening',
            'requires_full_day' => 0,
            'requires_operation_consent' => 0,
            'requires_bore_up_package' => 0,
            'is_active' => 1,
            'is_public' => 1,
            'part_variant_ids' => [$variant->id],
            'service_parts' => [$variant->id => ['component_label' => 'Roller set', 'quantity' => 6, 'duration_minutes' => 20, 'is_required' => 1]],
        ])->assertSessionHasNoErrors();

        $service = Service::query()->where('slug', 'cvt-roller-replacement')->firstOrFail();
        $this->assertSame(15, $service->shared_operation_minutes);
        $this->assertDatabaseHas('service_part_variants', [
            'service_id' => $service->id,
            'part_variant_id' => $variant->id,
            'component_label' => 'Roller set',
            'quantity' => 6,
            'duration_minutes' => 20,
            'is_required' => 1,
        ]);
    }

    /** @return array{Part, PartVariant, VehicleModel} */
    private function makeCatalogOption(): array
    {
        $partCategory = PartCategory::create(['name' => 'Transmission', 'slug' => 'transmission', 'is_active' => true]);
        $partBrand = PartBrand::create(['name' => 'Roadline', 'slug' => 'roadline', 'is_active' => true]);
        $part = Part::create([
            'category_id' => $partCategory->id,
            'brand_id' => $partBrand->id,
            'name' => 'Roller weights',
            'slug' => 'roller-weights',
            'sku' => 'TEST-ROLLER',
            'part_type' => 'aftermarket',
            'selling_price_rupiah' => 125000,
            'is_active' => true,
            'is_public' => true,
        ]);
        $variant = $part->variants()->create([
            'name' => '12 gram',
            'sku' => 'TEST-ROLLER-12G',
            'selling_price_rupiah' => 120000,
            'is_active' => true,
        ]);
        $vehicleCategory = VehicleCategory::create(['name' => 'Automatic scooter', 'slug' => 'automatic-scooter', 'is_active' => true]);
        $vehicleBrand = VehicleBrand::create(['name' => 'Honda', 'slug' => 'honda', 'is_active' => true]);
        $vehicle = VehicleModel::create([
            'brand_id' => $vehicleBrand->id,
            'category_id' => $vehicleCategory->id,
            'name' => 'Demo scooter 125',
            'slug' => 'demo-scooter-125',
            'is_active' => true,
        ]);

        return [$part, $variant, $vehicle];
    }

    private function signInAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->actingAs($admin);

        return $admin;
    }
}
