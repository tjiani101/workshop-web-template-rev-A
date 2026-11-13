<?php

namespace Database\Seeders;

use App\Models\Part;
use App\Models\PartBrand;
use App\Models\PartCategory;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $automatic = VehicleCategory::updateOrCreate(['slug' => 'automatic-scooter'], ['name' => 'Automatic scooter', 'is_active' => true, 'is_demo' => true]);
        $underbone = VehicleCategory::updateOrCreate(['slug' => 'underbone'], ['name' => 'Underbone motorcycle', 'is_active' => true, 'is_demo' => true]);
        $manual = VehicleCategory::updateOrCreate(['slug' => 'manual-motorcycle'], ['name' => 'Manual motorcycle', 'is_active' => true, 'is_demo' => true]);

        $models = [];
        foreach ([
            ['Honda', 'honda', $automatic, 'BeAT', 'beat'],
            ['Honda', 'honda', $automatic, 'Vario 160', 'vario-160'],
            ['Yamaha', 'yamaha', $automatic, 'NMAX', 'nmax'],
            ['Yamaha', 'yamaha', $automatic, 'Mio', 'mio'],
            ['Suzuki', 'suzuki', $underbone, 'Satria F150', 'satria-f150'],
            ['Honda', 'honda', $manual, 'CB150', 'cb150'],
        ] as [$brandName, $brandSlug, $category, $modelName, $modelSlug]) {
            $brand = VehicleBrand::updateOrCreate(['slug' => $brandSlug], ['name' => $brandName, 'is_active' => true]);
            $models[] = VehicleModel::updateOrCreate([
                'brand_id' => $brand->id,
                'slug' => $modelSlug,
            ], [
                'category_id' => $category->id,
                'name' => $modelName,
                'year_from' => null,
                'year_to' => null,
                'is_active' => true,
                'is_demo' => true,
            ]);
        }

        $brand = PartBrand::updateOrCreate(['slug' => 'roadline-demo'], ['name' => 'Roadline Demo', 'is_active' => true]);
        $categories = [];
        foreach (['Rollers', 'Center spring', 'Drive belt', 'Engine oil'] as $name) {
            $slug = Str::slug($name);
            $categories[$slug] = PartCategory::updateOrCreate(['slug' => $slug], ['name' => $name, 'is_active' => true]);
        }

        $this->seedPart($categories['rollers'], $brand, 'CVT roller set — demo catalog', 'MINEX-DEMO-ROLLER', 'aftermarket', 135000, [
            ['10 gram demo set', 'ROLL-10G', 125000, [['Weight', '10', 'g'], ['Quantity per set', '6', 'pcs']]],
            ['12 gram demo set', 'ROLL-12G', 135000, [['Weight', '12', 'g'], ['Quantity per set', '6', 'pcs']]],
        ], $models);

        $this->seedPart($categories['center-spring'], $brand, 'Center spring — demo catalog', 'MINEX-DEMO-SPRING', 'aftermarket', null, [
            ['Demo series A', 'SPR-A', null, [['Product series', 'Demo A', null], ['Spring rating', 'Demo value; confirm system', null]]],
            ['Demo series B', 'SPR-B', null, [['Product series', 'Demo B', null], ['Spring rating', 'Demo value; confirm system', null]]],
        ], $models);

        $this->seedPart($categories['drive-belt'], $brand, 'Drive belt — demo catalog', 'MINEX-DEMO-BELT', 'aftermarket', null, [
            ['Model-specific belt option', 'BELT-DEMO', null, [['Specification', 'Demo only; confirm exact belt', null]]],
        ], $models);

        $this->seedPart($categories['engine-oil'], null, 'Engine oil — demo catalog', 'MINEX-DEMO-OIL', 'oem', null, [
            ['Sample 0.8 L container', 'OIL-08', null, [['Volume', '0.8', 'L'], ['Viscosity', 'Demo value; confirm manual', null]]],
        ], $models);
    }

    private function seedPart(PartCategory $category, ?PartBrand $brand, string $name, string $sku, string $type, ?int $price, array $variants, array $models): void
    {
        $part = Part::updateOrCreate(['sku' => $sku], [
            'category_id' => $category->id,
            'brand_id' => $brand?->id,
            'name' => $name,
            'slug' => Str::slug($sku),
            'part_type' => $type,
            'description' => 'Demonstration catalog data. Confirm the exact specification and fitment with a mechanic before ordering.',
            'purchase_cost_rupiah' => null,
            'selling_price_rupiah' => $price,
            'image_path' => 'images/service-transmission.jpg',
            'is_active' => true,
            'is_public' => true,
            'is_demo' => true,
        ]);

        foreach ($variants as [$variantName, $variantCode, $variantPrice, $attributes]) {
            $variant = $part->variants()->updateOrCreate(['variant_code' => $variantCode], [
                'name' => $variantName,
                'sku' => $sku.'-'.$variantCode,
                'selling_price_rupiah' => $variantPrice,
                'is_active' => true,
                'is_demo' => true,
            ]);

            $attributeIds = [];
            foreach ($attributes as [$attributeName, $valueText, $unit]) {
                $attributeSlug = Str::slug($attributeName) ?: Str::lower(Str::random(8));
                $attribute = ProductAttribute::updateOrCreate(['slug' => $attributeSlug], ['name' => $attributeName, 'unit' => $unit]);
                $value = ProductAttributeValue::firstOrCreate(['attribute_id' => $attribute->id, 'value' => $valueText]);
                $attributeIds[] = $value->id;
            }
            $variant->attributeValues()->sync($attributeIds);

            foreach (array_slice($models, 0, 4) as $model) {
                $variant->compatibleModels()->syncWithoutDetaching([
                    $model->id => ['compatibility_status' => 'unverified'],
                ]);
            }
        }
    }
}
