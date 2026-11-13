<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminFinanceAndCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['name' => 'MINEX Owner']);
        $this->admin->roles()->attach(Role::query()->where('name', 'owner')->firstOrFail());
    }

    public function test_admin_can_record_view_and_void_expenses_without_erasing_the_ledger(): void
    {
        Storage::fake('local');
        $this->actingAs($this->admin)->get(route('admin.expenses.index'))->assertOk();
        $this->post(route('admin.expenses.store'), [
            'category' => 'Workshop supplies',
            'description' => 'Cleaning materials',
            'amount_rupiah' => 275000,
            'expense_date' => today()->toDateString(),
            'payment_method' => 'bank_transfer',
            'reference' => 'SUP-001',
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('expenses', ['description' => 'Cleaning materials', 'amount_rupiah' => 275000, 'status' => 'recorded', 'entered_by' => $this->admin->id]);
        $expense = Expense::query()->firstOrFail();
        $this->get(route('admin.expenses.receipt', $expense))->assertOk();

        $this->patch(route('admin.expenses.void', $expense), ['void_reason' => 'Duplicate entry from bank statement.'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'voided', 'voided_by' => $this->admin->id, 'void_reason' => 'Duplicate entry from bank statement.']);
        $this->assertDatabaseCount('expenses', 1);
    }

    public function test_admin_overview_and_customer_directory_render_and_are_role_protected(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Operations at a glance')->assertSee('assigned workload');
        $this->get(route('admin.customers.index'))->assertOk()->assertSee('Customer records.');

        $customer = User::factory()->create();
        $customer->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $this->actingAs($customer)->get(route('admin.customers.index'))->assertForbidden();
    }

    public function test_admin_can_maintain_customer_contact_and_membership_access_with_an_audit_record(): void
    {
        $customer = User::factory()->create(['name' => 'Rider One', 'phone' => null]);
        $customer->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());

        $this->actingAs($this->admin)->patch(route('admin.customers.update', $customer), [
            'name' => 'Rider One Updated',
            'email' => $customer->email,
            'phone' => '+62 812 3456 7890',
            'customer_status' => 'suspended',
            'customer_admin_notes' => 'Contact details checked with the rider.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'name' => 'Rider One Updated',
            'phone' => '+62 812 3456 7890',
            'customer_status' => 'suspended',
            'customer_admin_notes' => 'Contact details checked with the rider.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'event_type' => 'customer.profile_updated',
            'subject_id' => $customer->id,
        ]);

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($customer->fresh())->get(route('portal.home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_customers_can_update_only_their_own_contact_details(): void
    {
        $customer = User::factory()->create(['name' => 'Rider One', 'phone' => null]);
        $customer->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());
        $other = User::factory()->create(['name' => 'Rider Two']);
        $other->roles()->attach(Role::query()->where('name', 'customer')->firstOrFail());

        $this->actingAs($customer)->patch(route('portal.profile.update'), [
            'name' => 'Rider One Updated',
            'email' => $customer->email,
            'phone' => '+62 811 2222 3333',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Rider One Updated', 'phone' => '+62 811 2222 3333']);
        $this->assertDatabaseMissing('users', ['id' => $other->id, 'name' => 'Rider One Updated']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $customer->id, 'event_type' => 'customer.profile_updated_self']);
    }
}
