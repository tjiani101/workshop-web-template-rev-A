<?php

namespace Tests\Feature;

use App\Models\PackageItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinexFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_public_pages_render_from_the_database_backed_catalog(): void
    {
        $category = ServiceCategory::create([
            'name' => 'Brake System',
            'slug' => 'brake-system',
            'description' => 'A careful brake inspection.',
            'is_active' => true,
            'show_on_home' => true,
            'show_on_services' => true,
        ]);

        Service::create([
            'category_id' => $category->id,
            'name' => 'Brake inspection',
            'slug' => 'brake-inspection',
            'summary' => 'Inspect braking components.',
            'description' => 'Findings are explained before any additional work.',
            'included_items' => ['Visual inspection'],
            'excluded_items' => ['Replacement parts'],
            'is_active' => true,
            'is_public' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Full Maintenance',
            'slug' => 'full-maintenance',
            'summary' => 'A configurable maintenance plan.',
            'description' => 'The technician confirms the final scope first.',
            'is_active' => true,
            'is_public' => true,
        ]);
        PackageItem::create([
            'package_id' => $package->id,
            'component_type' => 'service',
            'label' => 'CVT inspection',
            'quantity' => 1,
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $this->get('/')->assertOk()->assertSee('Brake System');
        $this->get('/services')->assertOk()->assertSee('Brake inspection');
        $this->get('/services/brake-inspection')->assertOk()->assertSee('Findings are explained');
        $this->get('/packages')->assertOk()->assertSee('Full Maintenance');
        $this->get('/packages/full-maintenance')->assertOk()->assertSee('CVT inspection');
        $this->get('/pricing')->assertOk()->assertSee('Price to be confirmed');
        $this->get('/about')->assertOk();
        $this->get('/contact')->assertOk();
    }

    public function test_disabled_categories_and_services_are_not_shown_publicly(): void
    {
        $disabledCategory = ServiceCategory::create([
            'name' => 'Hidden service area',
            'slug' => 'hidden-service-area',
            'is_active' => false,
            'show_on_home' => true,
            'show_on_services' => true,
        ]);

        Service::create([
            'category_id' => $disabledCategory->id,
            'name' => 'Hidden category service',
            'slug' => 'hidden-category-service',
            'summary' => 'Not bookable while the category is disabled.',
            'description' => 'Historical service description.',
            'is_active' => true,
            'is_public' => true,
        ]);

        $activeCategory = ServiceCategory::create([
            'name' => 'Active category',
            'slug' => 'active-category',
            'is_active' => true,
            'show_on_home' => true,
            'show_on_services' => true,
        ]);

        Service::create([
            'category_id' => $activeCategory->id,
            'name' => 'Unpublished service',
            'slug' => 'unpublished-service',
            'summary' => 'Not publicly offered.',
            'description' => 'Private catalog entry.',
            'is_active' => false,
            'is_public' => true,
        ]);

        $this->get('/services')->assertOk()
            ->assertDontSee('Hidden service area')
            ->assertDontSee('Hidden category service')
            ->assertDontSee('Unpublished service');
        $this->get('/services/hidden-category-service')->assertNotFound();
    }

    public function test_registration_always_assigns_customer_role_and_cannot_escalate_access(): void
    {
        $response = $this->post('/register', [
            'name' => 'Rider Example',
            'email' => 'rider@example.test',
            'password' => 'StrongRiderPassword9',
            'password_confirmation' => 'StrongRiderPassword9',
            'role' => 'owner',
        ]);

        $response->assertRedirect(route('portal.home'));
        $user = User::query()->where('email', 'rider@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('owner'));
        $this->get('/admin')->assertForbidden();
    }

    public function test_customer_can_sign_in_and_sign_out(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.test']);
        $customer->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());

        $this->post('/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($customer);

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_mechanics_cannot_open_the_admin_workspace(): void
    {
        $mechanic = User::factory()->create();
        $mechanic->roles()->attach(Role::query()->where('name', 'mechanic')->firstOrFail());

        $this->actingAs($mechanic)->get('/admin')->assertForbidden();
        $this->actingAs($mechanic)->get('/workshop')->assertOk();
    }

    public function test_contact_form_stores_a_valid_message(): void
    {
        $this->from('/contact')->post('/contact', [
            'name' => 'Rider Example',
            'email' => 'rider@example.test',
            'subject' => 'Service question',
            'message' => 'I would like to ask about a routine service.',
        ])->assertRedirect('/contact')->assertSessionHas('status');

        $this->assertDatabaseHas('contact_messages', [
            'email' => 'rider@example.test',
            'subject' => 'Service question',
            'status' => 'new',
        ]);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'missing@example.test', 'password' => 'wrong-password'])->assertTooManyRequests();
    }
}
