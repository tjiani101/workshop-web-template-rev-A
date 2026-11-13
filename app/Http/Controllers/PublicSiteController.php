<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\PackageVehiclePrice;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\VehicleVariant;
use App\Models\WebsiteContent;
use App\Services\LaborPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function home(): View
    {
        $content = WebsiteContent::publishedContent();
        $galleryPaths = $content['home.gallery']['images'] ?? [];

        return view('pages.home', [
            'content' => $content,
            'galleryImages' => array_map(fn ($path) => $path ? Storage::disk('public')->url($path) : null, $galleryPaths),
            'categories' => ServiceCategory::active()->where('show_on_home', true)->orderBy('sort_order')->get(),
            'services' => Service::public()->where('is_featured', true)->with('category')->orderBy('sort_order')->limit(6)->get(),
            'packages' => ServicePackage::public()->where('is_featured', true)->with('items')->orderBy('sort_order')->limit(3)->get(),
        ]);
    }

    public function services(): View
    {
        return view('pages.services', [
            'categories' => ServiceCategory::active()->where('show_on_services', true)->orderBy('sort_order')->get(),
            'services' => Service::public()->with('category')->orderBy('category_id')->orderBy('sort_order')->get(),
        ]);
    }

    public function service(Service $service): View
    {
        abort_unless(Service::public()->whereKey($service->id)->exists(), 404);
        $service->load('category');

        return view('pages.service-detail', compact('service'));
    }

    public function packages(): View
    {
        return view('pages.packages', [
            'packages' => ServicePackage::public()->with(['category', 'items'])->orderBy('sort_order')->get(),
        ]);
    }

    public function package(ServicePackage $package): View
    {
        abort_unless(ServicePackage::public()->whereKey($package->id)->exists(), 404);
        $package->load(['category', 'items']);

        return view('pages.package-detail', compact('package'));
    }

    public function pricing(): View
    {
        $services = Service::public()->with('category')->orderBy('sort_order')->get();
        $packages = ServicePackage::public()->with(['category', 'items'])->orderBy('sort_order')->get();

        return view('pages.pricing', [
            'services' => $services,
            'packages' => $packages,
            'pricingGroups' => collect([
                'Routine maintenance' => ['routine', 'maintenance', 'brake', 'cvt'],
                'Engine performance' => ['bore-up', 'engine-performance'],
                'Oil cooling' => ['oil-cooler', 'oil-cooling'],
                'Electrical systems' => ['electrical', 'rewir', 'underglow', 'relay', 'lighting'],
                'Special services' => ['break-in', 'touring', 'custom', 'installation'],
            ])->map(fn (array $keywords, string $heading) => [
                'heading' => $heading,
                'services' => $services->filter(fn (Service $service): bool => $this->matchesPricingGroup($service->name.' '.$service->category?->name.' '.$service->slug, $keywords)),
                'packages' => $packages->filter(fn (ServicePackage $package): bool => $this->matchesPricingGroup($package->name.' '.$package->category?->name.' '.$package->slug, $keywords)),
            ])->values(),
            'laborRates' => app(LaborPricingService::class)->rates(),
        ]);
    }

    public function vehiclePackages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_variant_id' => ['required', 'integer', 'exists:vehicle_variants,id'],
            'model_year' => ['required', 'integer', 'between:1950,'.(now()->year + 1)],
        ]);
        $variant = VehicleVariant::query()->with(['model.brand', 'generation'])->whereKey($validated['vehicle_variant_id'])->where('is_active', true)->firstOrFail();
        $year = (int) $validated['model_year'];
        abort_unless($variant->years()->where('model_year', $year)->where('is_active', true)->exists(), 422, 'Choose a model year supported by this exact factory variant.');
        $prices = PackageVehiclePrice::query()->with('package.items')->where('vehicle_model_id', $variant->vehicle_model_id)
            ->where('status', 'published')
            ->whereHas('package', fn ($query) => $query->public()->whereHas('compatibleModels', fn ($models) => $models->where('vehicle_models.id', $variant->vehicle_model_id)->where('package_vehicle_model.compatibility_status', 'compatible')))
            ->where(fn ($query) => $query->whereNull('vehicle_variant_id')->orWhere('vehicle_variant_id', $variant->id))
            ->where(fn ($query) => $query->whereNull('vehicle_generation_id')->orWhere('vehicle_generation_id', $variant->vehicle_generation_id))
            ->where(fn ($query) => $query->whereNull('year_from')->orWhere('year_from', '<=', $year))
            ->where(fn ($query) => $query->whereNull('year_to')->orWhere('year_to', '>=', $year))
            ->get()->sortByDesc(fn (PackageVehiclePrice $price): int => (int) ($price->vehicle_variant_id !== null) * 4 + (int) ($price->vehicle_generation_id !== null) * 2 + (int) ($price->year_from !== null || $price->year_to !== null))
            ->unique('package_id')->values();

        return response()->json([
            'vehicle' => ['name' => $variant->model->brand->name.' '.$variant->model->name.' '.$variant->generation->name.' '.$year.' '.$variant->name],
            'packages' => $prices->map(fn (PackageVehiclePrice $price): array => [
                'name' => $price->package->name,
                'slug' => $price->package->slug,
                'url' => route('packages.show', $price->package),
                'total_rupiah' => $price->totalRupiah(),
                'base_rupiah' => $price->parts_selling_rupiah + $price->consumables_rupiah + $price->additional_costs_rupiah,
                'parts_rupiah' => $price->parts_selling_rupiah,
                'labor_rupiah' => $price->fixed_labor_rupiah,
                'consumables_rupiah' => $price->consumables_rupiah,
                'discount_rupiah' => $price->discount_rupiah,
                'source_updated_at' => $price->updated_at->toDateString(),
                'inclusions' => $price->package->items->where('is_required', true)->take(4)->pluck('label')->values(),
            ])->all(),
        ]);
    }

    /** @param list<string> $keywords */
    private function matchesPricingGroup(string $text, array $keywords): bool
    {
        $text = strtolower($text);

        return collect($keywords)->contains(fn (string $keyword): bool => str_contains($text, $keyword));
    }

    public function about(): View
    {
        return view('pages.about', ['content' => WebsiteContent::publishedContent()]);
    }

    public function contact(): View
    {
        return view('pages.contact', ['content' => WebsiteContent::publishedContent()]);
    }

    public function sendMessage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'subject' => ['nullable', 'string', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        ContactMessage::create($validated);

        return back()->with('status', 'Thank you. Your message has been received.');
    }
}
