<?php

namespace Database\Seeders;

use App\Models\BusinessSetting;
use Illuminate\Database\Seeder;

class LaborRatesSeeder extends Seeder
{
    public function run(): void
    {
        BusinessSetting::query()->firstOrCreate(['setting_key' => 'labor_rates'], ['setting_value' => [
            'cvt' => ['amount_rupiah' => 15000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'frame' => ['amount_rupiah' => 10000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'engine' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'electrical_diagnosis' => ['amount_rupiah' => 25000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => false, 'provisional' => true],
            'electrical_installation' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => false, 'provisional' => true],
        ]]);
    }
}
