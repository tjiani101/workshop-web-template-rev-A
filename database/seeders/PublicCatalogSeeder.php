<?php

namespace Database\Seeders;

use App\Models\PackageItem;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\WebsiteContent;
use Illuminate\Database\Seeder;

class PublicCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $catalog = [
            ['routine-maintenance', 'Routine Maintenance', 'Scheduled care for the parts your motorcycle relies on every day.', 'images/hero-workshop.jpg', true],
            ['cvt-transmission', 'CVT Service', 'Inspection and cleaning for automatic motorcycle transmission systems.', 'images/service-transmission.jpg', true],
            ['engine-service', 'Engine Service', 'Oil service and engine checks, selected for the motorcycle after inspection.', 'images/service-mechanic.jpg', true],
            ['brake-system', 'Brake System', 'A measured inspection of pads, discs, fluid, and control feel.', 'images/service-mechanic.jpg', false],
            ['electrical-diagnostics', 'Electrical Diagnostics', 'A careful check of charging, starting, lighting, and related circuits.', 'images/service-transmission.jpg', false],
            ['suspension', 'Suspension', 'Inspection of ride quality, leaks, fasteners, and suspension components.', 'images/hero-workshop.jpg', false],
        ];

        foreach ($catalog as $index => [$slug, $name, $description, $image, $showOnHome]) {
            $category = ServiceCategory::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'description' => $description,
                'image_path' => $image,
                'sort_order' => $index + 1,
                'is_active' => true,
                'is_featured' => $showOnHome,
                'show_on_home' => $showOnHome,
                'show_on_services' => true,
                'is_demo' => true,
            ]);

            $service = Service::updateOrCreate(['slug' => $slug.'-inspection'], [
                'category_id' => $category->id,
                'name' => $this->serviceName($name),
                'summary' => $description,
                'description' => $description.' The technician confirms the inspection scope and any suitable parts with you before work begins.',
                'included_items' => ['Initial visual and functional inspection', 'Findings explained before additional work'],
                'excluded_items' => ['Replacement parts and work outside the confirmed inspection scope'],
                'labor_price_rupiah' => null,
                'duration_minutes' => 30,
                'labor_category' => match ($slug) {
                    'cvt-transmission' => 'cvt',
                    'engine-service', 'routine-maintenance' => 'engine',
                    'brake-system', 'suspension' => 'frame',
                    'electrical-diagnostics' => 'electrical_diagnosis',
                    default => null,
                },
                'labor_pricing_mode' => 'time_based',
                'labor_group' => match ($slug) {
                    'cvt-transmission' => 'cvt-cover-opening',
                    'engine-service', 'routine-maintenance' => 'engine-service-check',
                    'brake-system', 'suspension' => 'frame-check',
                    'electrical-diagnostics' => 'electrical-diagnosis',
                    default => null,
                },
                'image_path' => $image,
                'sort_order' => $index + 1,
                'is_active' => true,
                'is_public' => true,
                'is_featured' => $showOnHome,
                'is_demo' => true,
            ]);

            if ($index === 0) {
                $this->createPackage($category, 'Full Maintenance', 'A configurable multi-point maintenance plan. Final scope and parts are confirmed for the motorcycle before booking.', [
                    'Engine oil replacement', 'Gear oil replacement', 'Brake inspection', 'CVT inspection and cleaning', 'General vehicle inspection',
                ], 'full-maintenance', 1);
                $this->createPackage($category, 'Semi Maintenance', 'A focused maintenance plan with the selected tasks confirmed by the workshop.', [
                    'Engine oil replacement', 'Gear oil replacement', 'Clutch inspection', 'CVT cleaning',
                ], 'semi-maintenance', 2);
            }
        }

        foreach ([
            'home.hero' => [
                'eyebrow' => 'MINEX MOTORCYCLE WORKSHOP',
                'title' => 'Engineered for everyday riding.',
                'body' => 'Clear service, careful inspection, and work approved by you.',
                'image' => 'images/hero-workshop.jpg',
            ],
            'home.intro' => [
                'eyebrow' => 'MAINTENANCE, MADE CLEAR',
                'title' => 'Know what your motorcycle needs.',
                'body' => 'MINEX brings precise checks and straightforward recommendations together, so every decision stays in your hands.',
            ],
            'home.principles' => [
                'eyebrow' => 'THE MINEX STANDARD',
                'title' => 'Mechanical work with a human check.',
                'body' => 'Technology helps us keep the details together. Your technician brings the judgment that makes a service personal to your motorcycle.',
                'items' => [
                    ['title' => 'Look before replacing', 'body' => 'We inspect first and record what we find before recommending parts or extra work.'],
                    ['title' => 'Show the options', 'body' => 'When alternatives are available, the choice and price are made clear before approval.'],
                    ['title' => 'Keep the history', 'body' => 'Your service record keeps the original scope, findings, and decisions together.'],
                ],
            ],
            'home.process' => [
                'eyebrow' => 'A BETTER SERVICE VISIT',
                'title' => 'Three steps. One less thing to guess.',
                'steps' => [
                    ['title' => 'Tell us about the ride', 'body' => 'Share your motorcycle and what you want checked.'],
                    ['title' => 'We inspect with care', 'body' => 'Your technician records the work and any findings.'],
                    ['title' => 'You approve the next step', 'body' => 'Additional work begins only after you decide.'],
                ],
            ],
            'home.gallery' => [
                'eyebrow' => 'IN THE WORKSHOP',
                'title' => 'Focused on the details.',
                'body' => 'Good work is often quiet. It lives in careful checks, clean parts, and a clear handover.',
                'captions' => ['Inspection, before recommendation', 'Everyday motorcycles, properly cared for', 'Work explained along the way'],
            ],
            'home.cta' => [
                'eyebrow' => 'READY WHEN YOU ARE',
                'title' => "Let's get your ride back to feeling right.",
                'button' => 'Ask about your ride',
            ],
            'about' => [
                'title' => 'Good maintenance starts with a clear conversation.',
                'body' => 'We explain what we find, show the work we recommend, and ask before we add anything to your service.',
                'values' => [
                    ['title' => 'Look closely', 'body' => 'Start with the motorcycle in front of us, using inspection to understand the need.'],
                    ['title' => 'Speak plainly', 'body' => 'Explain findings and choices in language a rider can use to make a decision.'],
                    ['title' => 'Respect the yes', 'body' => 'Record the scope you approve and ask again when the recommendation changes.'],
                ],
            ],
            'contact' => [
                'email' => null,
                'phone' => null,
                'whatsapp' => null,
                'address' => null,
                'hours' => null,
            ],
        ] as $key => $content) {
            WebsiteContent::updateOrCreate(['content_key' => $key], ['content' => $content, 'is_published' => true]);
        }
    }

    private function createPackage(ServiceCategory $category, string $name, string $description, array $items, string $slug, int $order): void
    {
        $package = ServicePackage::updateOrCreate(['slug' => $slug], [
            'category_id' => $category->id,
            'name' => $name,
            'summary' => $description,
            'description' => $description.' Components shown here are demonstration data and do not assert motorcycle compatibility.',
            'base_price_rupiah' => null,
            'labor_price_rupiah' => null,
            'discount_rupiah' => null,
            'duration_minutes' => null,
            'image_path' => $category->image_path,
            'sort_order' => $order,
            'is_active' => true,
            'is_public' => true,
            'is_featured' => true,
            'is_demo' => true,
        ]);

        $package->items()->delete();

        foreach ($items as $index => $label) {
            PackageItem::create([
                'package_id' => $package->id,
                'component_type' => 'service',
                'label' => $label,
                'description' => null,
                'quantity' => 1,
                'extra_price_rupiah' => null,
                'is_required' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function serviceName(string $category): string
    {
        return match ($category) {
            'Routine Maintenance' => 'Periodic service assessment',
            'CVT Service' => 'CVT inspection and cleaning',
            'Engine Service' => 'Engine oil service assessment',
            'Brake System' => 'Brake system inspection',
            'Electrical Diagnostics' => 'Electrical system diagnostics',
            default => 'Suspension inspection',
        };
    }
}
