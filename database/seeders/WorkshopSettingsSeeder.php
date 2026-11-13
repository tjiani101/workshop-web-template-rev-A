<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use Illuminate\Database\Seeder;

class WorkshopSettingsSeeder extends Seeder
{
    public function run(): void
    {
        BusinessSetting::query()->firstOrCreate(['setting_key' => 'operating_hours'], ['setting_value' => [
            'open' => '09:00',
            'close' => '21:00',
            'bay_capacity' => 1,
            'workdays' => [1, 2, 3, 4, 5, 6],
            'breaks' => [
                ['name' => 'Midday break', 'start' => '12:00', 'end' => '12:30'],
                ['name' => 'Afternoon break', 'start' => '15:30', 'end' => '16:00'],
                ['name' => 'Evening break', 'start' => '18:30', 'end' => '19:00'],
            ],
        ]]);
        BusinessSetting::query()->firstOrCreate(['setting_key' => 'payment_policy'], ['setting_value' => ['approval_deadline_hours' => 24]]);
    }
}
