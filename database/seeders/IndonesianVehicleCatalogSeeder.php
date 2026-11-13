<?php

namespace Database\Seeders;

use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Models\VehicleModelYear;
use App\Models\VehicleVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class IndonesianVehicleCatalogSeeder extends Seeder
{
    private const YAMAHA_FREEGO_SOURCE = 'https://www.yamaha-motor.co.id/archives/news/corporate/2018/10/freego-the-amazing-matic/';

    public function run(): void
    {
        $categories = collect([
            'automatic-scooter' => 'Automatic scooter',
            'underbone' => 'Underbone',
            'manual-motorcycle' => 'Manual / sport',
        ])->mapWithKeys(fn ($name, $slug) => [$slug => VehicleCategory::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true, 'is_demo' => false])]);

        $families = [
            'Honda' => [
                ['BeAT', 'automatic-scooter'], ['BeAT Street', 'automatic-scooter'], ['Scoopy', 'automatic-scooter'], ['Genio', 'automatic-scooter'],
                ['Vario 110', 'automatic-scooter'], ['Vario 125', 'automatic-scooter'], ['Vario 150', 'automatic-scooter'], ['Vario 160', 'automatic-scooter'],
                ['PCX 150', 'automatic-scooter'], ['PCX 160', 'automatic-scooter'], ['ADV 150', 'automatic-scooter'], ['ADV 160', 'automatic-scooter'],
                ['Stylo 160', 'automatic-scooter'], ['Supra X 125', 'underbone'], ['Revo', 'underbone'], ['Supra GTR 150', 'underbone'], ['CB150R', 'manual-motorcycle'], ['CBR150R', 'manual-motorcycle'],
            ],
            'Yamaha' => [
                ['Mio M3', 'automatic-scooter'], ['Mio J', 'automatic-scooter'], ['Mio Soul GT', 'automatic-scooter'], ['Fino 125', 'automatic-scooter'],
                ['X-Ride 125', 'automatic-scooter'], ['Gear 125', 'automatic-scooter'], ['Gear Ultima', 'automatic-scooter'], ['FreeGo 125', 'automatic-scooter'],
                ['Fazzio', 'automatic-scooter'], ['Grand Filano', 'automatic-scooter'], ['Lexi 125', 'automatic-scooter'], ['Lexi LX 155', 'automatic-scooter'],
                ['Aerox 155', 'automatic-scooter'], ['Aerox Alpha', 'automatic-scooter'], ['NMAX 155', 'automatic-scooter'], ['XMAX 250', 'automatic-scooter'],
                ['Jupiter Z1', 'underbone'], ['Vega Force', 'underbone'], ['MX King 150', 'underbone'], ['R15', 'manual-motorcycle'],
            ],
            'Suzuki' => [
                ['NEX II', 'automatic-scooter'], ['NEX Crossover', 'automatic-scooter'], ['NEX FI', 'automatic-scooter'], ['Address FI', 'automatic-scooter'],
                ['Address Playful', 'automatic-scooter'], ['Burgman Street 125EX', 'automatic-scooter'], ['Access 125', 'automatic-scooter'], ['Avenis 125', 'automatic-scooter'],
                ['Skydrive', 'automatic-scooter'], ['Skywave', 'automatic-scooter'], ['Hayate', 'automatic-scooter'], ['Spin 125', 'automatic-scooter'],
                ['Satria F150', 'underbone'], ['Smash', 'underbone'], ['Shogun 125', 'underbone'], ['GSX-R150', 'manual-motorcycle'],
            ],
            'Vespa' => [
                ['LX', 'automatic-scooter'], ['S', 'automatic-scooter'], ['Primavera', 'automatic-scooter'], ['Sprint', 'automatic-scooter'], ['GTS', 'automatic-scooter'], ['GTV', 'automatic-scooter'],
            ],
        ];

        $brands = [];
        foreach ($families as $brandName => $models) {
            $brand = VehicleBrand::updateOrCreate(['slug' => Str::slug($brandName)], ['name' => $brandName, 'is_active' => true]);
            $brands[$brandName] = $brand;
            foreach ($models as [$modelName, $categorySlug]) {
                $model = VehicleModel::updateOrCreate(
                    ['brand_id' => $brand->id, 'slug' => Str::slug($modelName)],
                    [
                        'category_id' => $categories[$categorySlug]->id,
                        'name' => $modelName,
                        'year_from' => null,
                        'year_to' => null,
                        'is_active' => true,
                        'is_demo' => false,
                        'verification_status' => 'needs_verification',
                        'data_source' => $this->sourceFor($brandName),
                        'notes' => 'Model family listed for customer requests. Add verified generations, model years, and trims before enabling customer registration.',
                    ],
                );

                if ($brandName === 'Yamaha' && $modelName === 'FreeGo 125') {
                    $this->seedFreeGo($model);
                }
            }
        }

        // Keep Vespa factory entries within the automatic-scooter category; no conversions or electric models are seeded here.
    }

    private function seedFreeGo(VehicleModel $model): void
    {
        $generation = VehicleGeneration::updateOrCreate(
            ['vehicle_model_id' => $model->id, 'name' => '2018 launch generation'],
            ['year_from' => 2018, 'year_to' => 2018, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => self::YAMAHA_FREEGO_SOURCE, 'notes' => 'Yamaha Indonesia launch announcement dated 31 October 2018. Launch-year trims and listed specifications verified from source.'],
        );
        $year = VehicleModelYear::updateOrCreate(
            ['vehicle_generation_id' => $generation->id, 'model_year' => 2018],
            ['vehicle_model_id' => $model->id, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => self::YAMAHA_FREEGO_SOURCE],
        );
        $model->update(['verification_status' => 'verified', 'data_source' => self::YAMAHA_FREEGO_SOURCE, 'notes' => '2018 launch variants are represented separately. Later model years need year-specific verification.']);

        foreach ([
            ['Standard', false, false],
            ['S', false, true],
            ['S ABS', true, true],
        ] as [$name, $abs, $keyless]) {
            $variant = VehicleVariant::updateOrCreate(
                ['vehicle_generation_id' => $generation->id, 'name' => $name],
                ['vehicle_model_id' => $model->id, 'is_active' => true, 'verification_status' => 'verified', 'data_source' => self::YAMAHA_FREEGO_SOURCE],
            );
            $variant->years()->syncWithoutDetaching([$year->id]);
            $attributes = [
                'ABS' => $abs ? 'Yes' : 'No',
                'CBS' => 'Not specified in the cited launch page',
                'Keyless / Smart Key' => $keyless ? 'Yes' : 'No',
                'Engine displacement' => '124.96 cc',
                'Fuel system' => 'Fuel injection',
                'Transmission' => 'Full automatic',
            ];
            foreach ($attributes as $attribute => $value) {
                $variant->attributes()->updateOrCreate(['name' => $attribute], ['value' => $value]);
            }
        }
    }

    private function sourceFor(string $brand): string
    {
        return match ($brand) {
            'Honda' => 'https://www.astra-honda.com/product',
            'Yamaha' => 'https://www.yamaha-motor.co.id/product/',
            'Suzuki' => 'https://www.suzuki.co.id/motorcycle',
            'Vespa' => 'https://www.vespa.com/id_ID/',
        };
    }
}
