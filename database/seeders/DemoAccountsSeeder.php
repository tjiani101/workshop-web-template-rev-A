<?php

namespace Database\Seeders;

use App\Models\MechanicProfile;
use App\Models\Role;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $password = (string) env('MINEX_DEMO_PASSWORD');

        if ($password === '') {
            return;
        }

        foreach ([
            ['name' => 'MINEX Owner', 'email' => 'owner@minex.test', 'role' => 'owner'],
            ['name' => 'Workshop Mechanic', 'email' => 'mechanic@minex.test', 'role' => 'mechanic'],
            ['name' => 'Workshop Mechanic Two', 'email' => 'mechanic2@minex.test', 'role' => 'mechanic'],
            ['name' => 'Demo Rider', 'email' => 'rider@minex.test', 'role' => 'customer'],
        ] as $account) {
            $user = User::updateOrCreate(['email' => $account['email']], [
                'name' => $account['name'],
                'password' => Hash::make($password),
            ]);

            $user->roles()->sync([Role::query()->where('name', $account['role'])->value('id')]);
        }

        $general = ServiceCategory::query()->whereIn('slug', ['routine-maintenance', 'engine-service'])->orderByRaw("FIELD(slug, 'routine-maintenance', 'engine-service')")->first();
        $cvt = ServiceCategory::query()->where('slug', 'cvt-transmission')->first();
        $electrical = ServiceCategory::query()->where('slug', 'electrical-diagnostics')->first();
        $mechanicOne = User::query()->where('email', 'mechanic@minex.test')->first();
        $mechanicTwo = User::query()->where('email', 'mechanic2@minex.test')->first();
        if ($mechanicOne && $mechanicTwo) {
            $viki = MechanicProfile::updateOrCreate(['user_id' => $mechanicOne->id], ['daily_capacity_minutes' => 600, 'is_active' => true, 'is_demo' => true]);
            $tjiani = MechanicProfile::updateOrCreate(['user_id' => $mechanicTwo->id], ['daily_capacity_minutes' => 300, 'is_active' => true, 'is_demo' => true]);
            $viki->specializations()->sync(array_values(array_filter([$general?->id, $cvt?->id])));
            $tjiani->specializations()->sync(array_values(array_filter([$general?->id, $cvt?->id, $electrical?->id])));
        }
    }
}
