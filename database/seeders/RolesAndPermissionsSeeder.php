<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = collect([
            'owner' => 'Owner',
            'admin' => 'Administrator',
            'mechanic' => 'Mechanic',
            'customer' => 'Customer',
        ])->map(fn (string $label, string $name) => Role::updateOrCreate(['name' => $name], ['label' => $label]));

        $permissionNames = [
            'catalog.manage', 'packages.manage', 'bookings.manage', 'mechanics.assign',
            'work_orders.manage', 'inspections.view', 'inventory.manage', 'payments.verify', 'settings.manage',
        ];

        $permissions = collect($permissionNames)->mapWithKeys(function (string $name): array {
            return [$name => Permission::firstOrCreate(['name' => $name], ['description' => 'Capability: '.$name])];
        });

        $roles['owner']->permissions()->sync($permissions->pluck('id'));
        $roles['admin']->permissions()->sync($permissions->pluck('id'));
        $roles['mechanic']->permissions()->sync($permissions->only(['work_orders.manage', 'inspections.view'])->pluck('id'));
        $roles['customer']->permissions()->sync([]);
    }
}
