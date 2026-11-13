<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PublicCatalogSeeder::class,
            SpecialtyPackageTemplateSeeder::class,
            ProductCatalogSeeder::class,
            IndonesianVehicleCatalogSeeder::class,
            LaborRatesSeeder::class,
            DemoAccountsSeeder::class,
            WorkshopSettingsSeeder::class,
        ]);
    }
}
