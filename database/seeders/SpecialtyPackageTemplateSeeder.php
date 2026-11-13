<?php

namespace Database\Seeders;

use App\Models\PackageItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpecialtyPackageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $engineCategory = ServiceCategory::firstOrCreate(
            ['slug' => 'engine-performance-bore-up'],
            ['name' => 'Engine Performance & Bore-Up', 'description' => 'Model-specific engine performance work configured and verified by MINEX.', 'sort_order' => 20, 'is_active' => true, 'is_demo' => false],
        );
        $oilCategory = ServiceCategory::firstOrCreate(
            ['slug' => 'oil-cooling-lubrication-upgrade'],
            ['name' => 'Oil Cooling & Lubrication Upgrade', 'description' => 'Model-specific oil cooling and lubrication work configured and verified by MINEX.', 'sort_order' => 21, 'is_active' => true, 'is_demo' => false],
        );
        $breakInCategory = ServiceCategory::firstOrCreate(
            ['slug' => 'engine-break-in'],
            ['name' => 'Engine Break-In', 'description' => 'A supervised, full-day break-in service available only with a bore-up package.', 'sort_order' => 22, 'is_active' => true, 'is_demo' => false],
        );

        $this->seedPackages($engineCategory, [
            ['Bore Up Standard', 'bore-up-standard', 'A configurable standard bore-up template. Exact block, piston, injector, supporting parts, labor, and fitment must be set for the selected motorcycle.', [
                ['Cylinder block kit', 'Select a block with verified bore and model fitment.', true],
                ['Piston kit', 'Select a piston matched to the block and verified engine configuration.', true],
                ['Fuel injector', 'Confirm injector flow and electrical compatibility for this motorcycle.', true],
                ['Required installation components', 'Configure model-specific gaskets, seals, fasteners, and other required components.', true],
                ['Installation labor', 'Set fixed labor for the approved installation scope.', true],
                ['Post-installation inspection', 'Record initial leak, lubrication, and operating checks.', true],
            ]],
            ['Bore Up Semi-Standard', 'bore-up-semi-standard', 'A configurable semi-standard bore-up template. Fuel delivery, ECU requirements, and supporting components require motorcycle-specific assessment.', [
                ['Bore-up block and piston', 'Configure a matched block and piston with verified specifications.', true],
                ['Fuel injector', 'Confirm injector selection and compatibility.', true],
                ['Fuel delivery assessment', 'Record the required fuel-system checks and any mandatory upgrade.', true],
                ['ECU configuration or tuning', 'Set only the verified ECU work required for this motorcycle.', true],
                ['Supporting components', 'Add required parts after the engine build is reviewed.', true],
                ['Installation and inspection labor', 'Set fixed labor for the configured scope.', true],
            ]],
            ['Bore Up Performance', 'bore-up-performance', 'A configurable performance template. Do not assume bore, displacement, injector, ECU, camshaft, valve spring, head, intake, or fuel-system compatibility.', [
                ['Performance block and piston', 'Configure verified product variants and measured specifications.', true],
                ['Injector and fuel delivery upgrade', 'Configure verified flow information and required supporting parts.', true],
                ['ECU or programmable ECU', 'Configure model-specific ECU hardware and tuning scope.', true],
                ['Camshaft and valve springs', 'Optional; include only after engine compatibility review.', false],
                ['Cylinder head work', 'Optional; scope and price require workshop confirmation.', false],
                ['Intake or throttle body modification', 'Optional; verify fitment and fuel calibration requirements.', false],
                ['Supporting components and labor', 'Add all mandatory components and a fixed labor quote.', true],
            ]],
            ['Bore Up Touring / Street', 'bore-up-touring-street', 'A configurable everyday-use template. The intended setup is conservative, but all products and specifications still require model-specific verification.', [
                ['Touring/street block and piston', 'Configure measured, verified model-specific variants.', true],
                ['Fuel delivery and ECU configuration', 'Record the compatible injector and required calibration.', true],
                ['Lubrication and cooling options', 'Optional; require engine-specific assessment.', false],
                ['Supporting parts and inspection', 'Configure mandatory parts and post-installation checks.', true],
                ['Installation labor', 'Set a fixed price for the defined work scope.', true],
            ]],
        ]);

        foreach ([
            ['Oil Cooler 5 Row', 'oil-cooler-5-row', '5-row oil cooler template. Confirm cooler dimensions, hose/fittings, pump, oil flow, and motorcycle fitment before activation.'],
            ['Oil Cooler 6 Row', 'oil-cooler-6-row', '6-row oil cooler template. Confirm cooler dimensions, hose/fittings, pump, oil flow, and motorcycle fitment before activation.'],
            ['Oil Cooler 8 Row', 'oil-cooler-8-row', '8-row oil cooler template. Confirm cooler dimensions, hose/fittings, pump, oil flow, and motorcycle fitment before activation.'],
            ['OEM Suzuki Satria FU Oil Cooler 3 Row', 'oem-suzuki-satria-fu-oil-cooler-3-row', 'Satria FU 3-row OEM cooler template. The oil pump and all fittings must be verified specifically for the selected Satria FU year and variant.'],
        ] as [$name, $slug, $description]) {
            $this->seedPackage($oilCategory, $name, $slug, $description, [
                ['Oil cooler unit', 'Configure the exact brand, series, row count, and dimensions for this package.', true],
                ['Compatible oil pump upgrade', 'Required. Verify pump compatibility with the selected motorcycle and cooler.', true],
                ['Oil hose and fittings', 'Configure hose specification, fitting types, and adapters.', true],
                ['Mounting and sealing hardware', 'Configure bracket, gasket, seal, and fastener variants.', true],
                ['Installation, leak, and flow checks', 'Set fixed labor and record lubrication flow verification.', true],
                ['Additional oil', 'Optional; configure the oil type and quantity if needed.', false],
            ]);
        }

        Service::firstOrCreate(
            ['slug' => 'minex-engine-break-in-program'],
            [
                'category_id' => $breakInCategory->id,
                'name' => 'MINEX Engine Break-In Program',
                'summary' => 'A supervised full-day break-in service for an approved bore-up build.',
                'description' => 'The mechanic records the approved engine-specific procedure, fuel receipts, operating logs, and final inspection. The customer contributes 80% of documented fuel expense and MINEX covers 20%. Actual fuel above the approved budget requires customer approval before further operation. No universal RPM, speed, route, or distance procedure is assumed.',
                'included_items' => ['Full workshop-day capacity reservation', 'Documented engine-specific procedure', 'Post-break-in inspection'],
                'excluded_items' => ['Fuel expense is itemized separately from labor'],
                'labor_price_rupiah' => null,
                'duration_minutes' => 720,
                'labor_category' => 'engine',
                'labor_pricing_mode' => 'fixed',
                'requires_full_day' => true,
                'requires_operation_consent' => true,
                'requires_bore_up_package' => true,
                'sort_order' => 1,
                'is_active' => true,
                'is_public' => true,
                'is_demo' => false,
            ],
        );
    }

    /** @param list<array{0:string,1:string,2:string,3:list<array{0:string,1:string,2:bool}>}> $packages */
    private function seedPackages(ServiceCategory $category, array $packages): void
    {
        foreach ($packages as [$name, $slug, $summary, $items]) {
            $this->seedPackage($category, $name, $slug, $summary, $items);
        }
    }

    /** @param list<array{0:string,1:string,2:bool}> $items */
    private function seedPackage(ServiceCategory $category, string $name, string $slug, string $description, array $items): void
    {
        $package = ServicePackage::firstOrCreate(
            ['slug' => $slug],
            [
                'category_id' => $category->id,
                'name' => $name,
                'summary' => Str::limit($description, 280),
                'description' => $description.' Template only: no product fitment, technical specification, or price is assumed. Configure compatible product variants and fixed labor before accepting a final quote.',
                'base_price_rupiah' => null,
                'labor_price_rupiah' => null,
                'discount_rupiah' => null,
                'duration_minutes' => null,
                'sort_order' => 1,
                'is_active' => true,
                'is_public' => true,
                'is_demo' => false,
                'requires_fitment_confirmation' => true,
            ],
        );

        if ($package->wasRecentlyCreated) {
            foreach ($items as $index => [$label, $itemDescription, $isRequired]) {
                PackageItem::create([
                    'package_id' => $package->id,
                    'component_type' => 'task',
                    'label' => $label,
                    'description' => $itemDescription,
                    'quantity' => 1,
                    'extra_price_rupiah' => null,
                    'is_required' => $isRequired,
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }
}
