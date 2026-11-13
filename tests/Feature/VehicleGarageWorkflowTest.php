<?php

namespace Tests\Feature;

use App\Models\CustomerVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Database\Seeders\IndonesianVehicleCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleGarageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_rider_can_save_edit_and_validate_a_2018_freego_trim(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(IndonesianVehicleCatalogSeeder::class);

        $this->post(route('register.store'), [
            'name' => 'FreeGo rider',
            'email' => 'freego-rider@example.test',
            'phone' => '+62 812 0000 1234',
            'password' => 'RiderPassword2026',
            'password_confirmation' => 'RiderPassword2026',
        ])->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs(User::query()->where('email', 'freego-rider@example.test')->firstOrFail());
        $this->get(route('portal.home'))->assertOk();

        $freego = VehicleModel::query()->where('slug', 'freego-125')->firstOrFail();
        $sAbs = $freego->variants()->where('name', 'S ABS')->firstOrFail();
        $standard = $freego->variants()->where('name', 'Standard')->firstOrFail();
        $this->getJson(route('vehicle-catalog.index'))->assertOk()->assertJsonFragment(['name' => 'S ABS'])->assertJsonFragment(['name' => 'ABS', 'value' => 'Yes']);
        $this->assertSame('Yes', $sAbs->attributes()->where('name', 'ABS')->value('value'));
        $this->assertSame('Yes', $sAbs->attributes()->where('name', 'Keyless / Smart Key')->value('value'));

        $this->post(route('portal.vehicles.store'), [
            'variant_id' => $sAbs->id,
            'model_year' => 2018,
            'nickname' => 'Daily FreeGo',
        ])->assertRedirect();
        $vehicle = CustomerVehicle::query()->firstOrFail();
        $this->assertSame($sAbs->id, $vehicle->vehicle_variant_id);
        $this->assertDatabaseHas('customer_vehicles', ['id' => $vehicle->id, 'model_year' => 2018, 'is_default' => 1]);
        $this->get(route('portal.home'))->assertOk()->assertSee('S ABS')->assertSee('ABS: Yes')->assertSee('Keyless / Smart Key: Yes');

        $this->patch(route('portal.vehicles.update', $vehicle->id), [
            'variant_id' => $standard->id,
            'model_year' => 2018,
            'nickname' => 'Updated FreeGo',
        ])->assertRedirect();
        $this->assertDatabaseHas('customer_vehicles', ['id' => $vehicle->id, 'vehicle_variant_id' => $standard->id, 'nickname' => 'Updated FreeGo']);

        $this->from(route('portal.home'))->patch(route('portal.vehicles.update', $vehicle->id), [
            'variant_id' => $sAbs->id,
            'model_year' => 2019,
            'nickname' => 'Invalid year',
        ])->assertSessionHasErrors('variant_id');
        $this->assertDatabaseCount('customer_vehicles', 1);
    }
}
