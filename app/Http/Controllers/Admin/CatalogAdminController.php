<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PackageItem;
use App\Models\PackageVehiclePrice;
use App\Models\Part;
use App\Models\PartBrand;
use App\Models\PartCategory;
use App\Models\PartVariant;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServicePackage;
use App\Models\VehicleModel;
use App\Services\AuditLogger;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogAdminController extends Controller
{
    public function index(): View
    {
        return view('admin.catalog.index', [
            'categories' => ServiceCategory::count(),
            'services' => Service::count(),
            'packages' => ServicePackage::count(),
            'parts' => Part::count(),
        ]);
    }

    public function categories(): View
    {
        return view('admin.catalog.categories', ['categories' => ServiceCategory::orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', 'unique:service_categories,slug'],
            'description' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $category = ServiceCategory::create(array_merge(collect($validated)->except('image')->all(), [
            'image_path' => $this->storeImage($request),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'is_featured' => $request->boolean('is_featured'),
            'show_on_home' => $request->boolean('show_on_home'),
            'show_on_services' => $request->boolean('show_on_services'),
        ]));

        return back()->with('status', "Category {$category->name} created.");
    }

    public function updateCategory(Request $request, ServiceCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:140', Rule::unique('service_categories', 'slug')->ignore($category->id)],
            'description' => ['nullable', 'string', 'max:3000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $category->update(array_merge(collect($validated)->except('image')->all(), [
            'image_path' => $this->storeImage($request, $category->image_path),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'is_featured' => $request->boolean('is_featured'),
            'show_on_home' => $request->boolean('show_on_home'),
            'show_on_services' => $request->boolean('show_on_services'),
        ]));

        return back()->with('status', "Category {$category->name} updated.");
    }

    public function services(): View
    {
        return view('admin.catalog.services', [
            'categories' => ServiceCategory::orderBy('name')->get(),
            'services' => Service::with(['category', 'partVariants.part.brand'])->orderBy('name')->get(),
            'partVariants' => PartVariant::query()->with(['part.brand', 'part.category'])->where('is_active', true)->whereHas('part', fn ($parts) => $parts->where('is_active', true))->orderBy('part_id')->orderBy('name')->get(),
        ]);
    }

    public function storeService(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateService($request);
        $service = Service::create(array_merge(collect($validated)->except(['image', 'slug', 'part_variant_ids', 'service_parts'])->all(), [
            'labor_pricing_mode' => $validated['labor_pricing_mode'] ?? 'fixed',
            'slug' => ($validated['slug'] ?? null) ?: $this->makeUniqueSlug(Service::class, $validated['name']),
            'image_path' => $this->storeImage($request),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
            'is_featured' => $request->boolean('is_featured'),
            'included_items' => $this->cleanLines($request->input('included_items', [])),
            'excluded_items' => $this->cleanLines($request->input('excluded_items', [])),
        ]));
        $this->syncServicePartVariants($service, $validated['part_variant_ids'] ?? [], $validated['service_parts'] ?? []);
        $audit->record($request, 'service.created', $service, ['after' => $service->only(['name', 'labor_pricing_mode', 'labor_category', 'labor_price_rupiah', 'duration_minutes', 'requires_full_day', 'requires_operation_consent', 'requires_bore_up_package'])]);

        return back()->with('status', "Service {$service->name} created.");
    }

    public function updateService(Request $request, Service $service, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateService($request, $service);
        $before = $service->only(['name', 'labor_pricing_mode', 'labor_category', 'labor_price_rupiah', 'duration_minutes', 'requires_full_day', 'requires_operation_consent', 'requires_bore_up_package']);
        $service->update(array_merge(collect($validated)->except(['image', 'slug', 'part_variant_ids', 'service_parts'])->all(), [
            'labor_pricing_mode' => $validated['labor_pricing_mode'] ?? 'fixed',
            'slug' => ($validated['slug'] ?? null) ?: $this->makeUniqueSlug(Service::class, $validated['name'], $service->id),
            'image_path' => $this->storeImage($request, $service->image_path),
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
            'is_featured' => $request->boolean('is_featured'),
            'included_items' => $this->cleanLines($request->input('included_items', [])),
            'excluded_items' => $this->cleanLines($request->input('excluded_items', [])),
        ]));
        $this->syncServicePartVariants($service, $validated['part_variant_ids'] ?? [], $validated['service_parts'] ?? []);
        $audit->record($request, 'service.updated', $service, ['before' => $before, 'after' => $service->only(['name', 'labor_pricing_mode', 'labor_category', 'labor_price_rupiah', 'duration_minutes', 'requires_full_day', 'requires_operation_consent', 'requires_bore_up_package'])]);

        return back()->with('status', "Service {$service->name} updated.");
    }

    public function packages(): View
    {
        return view('admin.catalog.packages', [
            'categories' => ServiceCategory::orderBy('name')->get(),
            'services' => Service::orderBy('name')->get(['id', 'name']),
            'parts' => Part::with('variants')->orderBy('name')->get(),
            'packages' => ServicePackage::with(['category', 'items', 'compatibleModels', 'vehiclePrices'])->orderBy('name')->get(),
            'vehicleModels' => VehicleModel::with(['brand', 'category', 'generations', 'variants'])->where('is_active', true)->orderBy('brand_id')->orderBy('name')->get(),
        ]);
    }

    public function storeVehiclePrice(Request $request, ServicePackage $package, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validateVehiclePrice($request);
        if ($data['status'] === 'published') {
            throw ValidationException::withMessages(['status' => 'Save the price as a draft or pending approval, then explicitly publish it after fitment review.']);
        }
        $this->assertPackageCompatibility($package, (int) $data['vehicle_model_id']);
        $price = $package->vehiclePrices()->create($data + ['updated_by' => $request->user()->id]);
        $audit->record($request, 'package_vehicle_price.created', $price, ['package_id' => $package->id, 'after' => $price->toArray()]);

        return back()->with('status', 'Motorcycle-specific package price saved. Only published prices appear publicly.');
    }

    public function updateVehiclePrice(Request $request, ServicePackage $package, PackageVehiclePrice $price, AuditLogger $audit): RedirectResponse
    {
        abort_unless((int) $price->package_id === (int) $package->id, 404);
        $data = $this->validateVehiclePrice($request);
        $this->assertPackageCompatibility($package, (int) $data['vehicle_model_id']);
        $before = $price->toArray();
        $publishedAt = $data['status'] === 'published' ? ($price->published_at ?? now()) : null;
        $price->update($data + [
            'updated_by' => $request->user()->id,
            'approved_by' => in_array($data['status'], ['published', 'inactive'], true) ? $request->user()->id : null,
            'approved_at' => in_array($data['status'], ['published', 'inactive'], true) ? now() : null,
            'published_at' => $publishedAt,
        ]);
        $audit->record($request, 'package_vehicle_price.updated', $price, ['package_id' => $package->id, 'before' => $before, 'after' => $price->toArray()]);

        return back()->with('status', 'Motorcycle-specific package price and publication status updated.');
    }

    /** @return array<string, mixed> */
    private function validateVehiclePrice(Request $request): array
    {
        $data = $request->validate([
            'vehicle_model_id' => ['required', 'integer', Rule::exists('vehicle_models', 'id')->where('is_active', true)],
            'vehicle_generation_id' => ['nullable', 'integer', Rule::exists('vehicle_generations', 'id')->where('is_active', true)],
            'vehicle_variant_id' => ['nullable', 'integer', Rule::exists('vehicle_variants', 'id')->where('is_active', true)],
            'year_from' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'year_to' => ['nullable', 'integer', 'between:1950,'.(now()->year + 1)],
            'parts_cost_rupiah' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'parts_selling_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'fixed_labor_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'consumables_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'additional_costs_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'discount_rupiah' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'price_source' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['draft', 'pending_approval', 'published', 'inactive'])],
        ]);
        if (($data['year_from'] ?? null) !== null && ($data['year_to'] ?? null) !== null && $data['year_from'] > $data['year_to']) {
            throw ValidationException::withMessages(['year_to' => 'End year must be the same as or later than start year.']);
        }
        if (isset($data['vehicle_generation_id']) && ! VehicleModel::query()->whereKey($data['vehicle_model_id'])->whereHas('generations', fn ($query) => $query->whereKey($data['vehicle_generation_id']))->exists()) {
            throw ValidationException::withMessages(['vehicle_generation_id' => 'Choose a generation from the selected motorcycle model.']);
        }
        if (isset($data['vehicle_variant_id']) && ! VehicleModel::query()->whereKey($data['vehicle_model_id'])->whereHas('variants', fn ($query) => $query->whereKey($data['vehicle_variant_id']))->exists()) {
            throw ValidationException::withMessages(['vehicle_variant_id' => 'Choose a variant from the selected motorcycle model.']);
        }

        return $data;
    }

    private function assertPackageCompatibility(ServicePackage $package, int $vehicleModelId): void
    {
        abort_unless($package->compatibleModels()->whereKey($vehicleModelId)->wherePivot('compatibility_status', 'compatible')->exists(), 422, 'Confirm compatible package fitment for this motorcycle before configuring or publishing its price.');
    }

    public function storePackage(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validatePackage($request);

        $package = DB::transaction(function () use ($validated, $request): ServicePackage {
            $package = ServicePackage::create(array_merge(collect($validated)->except(['items', 'image', 'slug', 'compatible_model_ids', 'compatible_model_compatibility', 'compatible_model_sources'])->all(), [
                'image_path' => $this->storeImage($request),
                'slug' => ($validated['slug'] ?? null) ?: $this->makeUniqueSlug(ServicePackage::class, $validated['name']),
                'is_active' => $request->boolean('is_active'),
                'is_public' => $request->boolean('is_public'),
                'is_featured' => $request->boolean('is_featured'),
                'sort_order' => $validated['sort_order'] ?? 0,
            ]));

            $this->savePackageItems($package, $validated['items']);
            $this->syncPackageCompatibility($package, $validated, (int) $request->user()->id);

            return $package->load(['items', 'compatibleModels.brand']);
        });
        $audit->record($request, 'package.created', $package, ['after' => $package->only(['name', 'slug', 'base_price_rupiah', 'labor_price_rupiah', 'discount_rupiah', 'duration_minutes', 'requires_fitment_confirmation']), 'items' => $package->items->map->only(['label', 'component_type', 'quantity', 'extra_price_rupiah', 'is_required'])->all(), 'compatible_models' => $package->compatibleModels->map(fn ($model): array => ['id' => $model->id, 'status' => $model->pivot->compatibility_status, 'source_reference' => $model->pivot->source_reference])->all()]);

        return back()->with('status', 'Maintenance package created.');
    }

    public function updatePackage(Request $request, ServicePackage $package, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validatePackage($request, $package);
        $before = $package->only(['name', 'slug', 'base_price_rupiah', 'labor_price_rupiah', 'discount_rupiah', 'duration_minutes', 'requires_fitment_confirmation']);
        $beforeItems = $package->items->map->only(['label', 'component_type', 'quantity', 'extra_price_rupiah', 'is_required'])->all();
        $beforeModels = $package->compatibleModels()->get()->map(fn ($model): array => ['id' => $model->id, 'status' => $model->pivot->compatibility_status, 'source_reference' => $model->pivot->source_reference])->all();

        $package = DB::transaction(function () use ($validated, $request, $package): ServicePackage {
            $package->update(array_merge(collect($validated)->except(['items', 'image', 'slug', 'compatible_model_ids', 'compatible_model_compatibility', 'compatible_model_sources'])->all(), [
                'image_path' => $this->storeImage($request, $package->image_path),
                'slug' => ($validated['slug'] ?? null) ?: $this->makeUniqueSlug(ServicePackage::class, $validated['name'], $package->id),
                'is_active' => $request->boolean('is_active'),
                'is_public' => $request->boolean('is_public'),
                'is_featured' => $request->boolean('is_featured'),
                'sort_order' => $validated['sort_order'] ?? 0,
            ]));

            $this->savePackageItems($package, $validated['items']);
            $this->syncPackageCompatibility($package, $validated, (int) $request->user()->id);

            return $package->load(['items', 'compatibleModels.brand']);
        });
        $audit->record($request, 'package.updated', $package, ['before' => $before, 'after' => $package->only(['name', 'slug', 'base_price_rupiah', 'labor_price_rupiah', 'discount_rupiah', 'duration_minutes', 'requires_fitment_confirmation']), 'items_before' => $beforeItems, 'items_after' => $package->items->map->only(['label', 'component_type', 'quantity', 'extra_price_rupiah', 'is_required'])->all(), 'compatible_models_before' => $beforeModels, 'compatible_models_after' => $package->compatibleModels->map(fn ($model): array => ['id' => $model->id, 'status' => $model->pivot->compatibility_status, 'source_reference' => $model->pivot->source_reference])->all()]);

        return back()->with('status', "Package {$package->name} updated.");
    }

    public function parts(): View
    {
        return view('admin.catalog.parts', [
            'categories' => PartCategory::orderBy('name')->get(),
            'brands' => PartBrand::orderBy('name')->get(),
            'parts' => Part::with(['category', 'brand', 'variants.attributeValues.attribute'])->orderBy('name')->get(),
        ]);
    }

    public function storePart(Request $request): RedirectResponse
    {
        $validated = $this->validatePart($request);

        DB::transaction(function () use ($validated, $request): void {
            $category = PartCategory::firstOrCreate(['slug' => Str::slug($validated['category_name'])], ['name' => $validated['category_name']]);
            $brand = filled($validated['brand_name'] ?? null)
                ? PartBrand::firstOrCreate(['slug' => Str::slug($validated['brand_name'])], ['name' => $validated['brand_name']])
                : null;

            $part = Part::create([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name'].'-'.($validated['sku'] ?: Str::lower(Str::random(8)))),
                'sku' => $validated['sku'] ?? null,
                'part_type' => $validated['part_type'],
                'description' => $validated['description'] ?? null,
                'purchase_cost_rupiah' => $validated['purchase_cost_rupiah'] ?? null,
                'selling_price_rupiah' => $validated['selling_price_rupiah'] ?? null,
                'image_path' => $this->storeImage($request),
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'is_public' => (bool) ($validated['is_public'] ?? false),
            ]);

            foreach ($validated['variants'] as $variantData) {
                $variant = $part->variants()->create(collect($variantData)->except(['attributes', 'id'])->all() + ['is_active' => true]);
                $this->syncVariantAttributes($variant, $variantData['attributes'] ?? []);
            }
        });

        return back()->with('status', 'Part and variants created. Fitment remains unverified until an authorized user confirms a source.');
    }

    public function updatePart(Request $request, Part $part): RedirectResponse
    {
        $validated = $this->validatePart($request, $part);

        foreach ($validated['variants'] as $index => $variantData) {
            if (! empty($variantData['id']) && ! $part->variants()->whereKey($variantData['id'])->exists()) {
                return back()->withErrors(["variants.{$index}.id" => 'That variant does not belong to this part.'])->withInput();
            }
        }

        DB::transaction(function () use ($validated, $request, $part): void {
            $category = PartCategory::firstOrCreate(['slug' => Str::slug($validated['category_name'])], ['name' => $validated['category_name']]);
            $brand = filled($validated['brand_name'] ?? null)
                ? PartBrand::firstOrCreate(['slug' => Str::slug($validated['brand_name'])], ['name' => $validated['brand_name']])
                : null;

            $part->update([
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name'].'-'.($validated['sku'] ?: $part->id)),
                'sku' => $validated['sku'] ?? null,
                'part_type' => $validated['part_type'],
                'description' => $validated['description'] ?? null,
                'purchase_cost_rupiah' => $validated['purchase_cost_rupiah'] ?? null,
                'selling_price_rupiah' => $validated['selling_price_rupiah'] ?? null,
                'image_path' => $this->storeImage($request, $part->image_path),
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'is_public' => (bool) ($validated['is_public'] ?? false),
            ]);

            foreach ($validated['variants'] as $variantData) {
                $variant = ! empty($variantData['id'])
                    ? $part->variants()->whereKey($variantData['id'])->firstOrFail()
                    : $part->variants()->make();

                $variant->fill(collect($variantData)->except(['attributes', 'id'])->all());
                $variant->is_active = (bool) ($variantData['is_active'] ?? false);
                $part->variants()->save($variant);
                $this->syncVariantAttributes($variant, $variantData['attributes'] ?? []);
            }
        });

        return back()->with('status', "Part {$part->name} and its variants were updated.");
    }

    private function validatePart(Request $request, ?Part $part = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('parts', 'sku')->ignore($part?->id)],
            'part_type' => ['required', Rule::in(['oem', 'aftermarket', 'generic', 'custom'])],
            'category_name' => ['required', 'string', 'max:120'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:4000'],
            'purchase_cost_rupiah' => ['nullable', 'integer', 'min:0'],
            'selling_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'variants' => ['required', 'array', 'min:1', 'max:30'],
            'variants.*.id' => ['nullable', 'integer', 'exists:part_variants,id'],
            'variants.*.name' => ['required', 'string', 'max:160'],
            'variants.*.sku' => ['nullable', 'string', 'max:80', 'distinct'],
            'variants.*.variant_code' => ['nullable', 'string', 'max:80'],
            'variants.*.selling_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.attributes' => ['nullable', 'array', 'max:12'],
            'variants.*.attributes.*.name' => ['nullable', 'string', 'max:100'],
            'variants.*.attributes.*.value' => ['nullable', 'string', 'max:100'],
            'variants.*.attributes.*.unit' => ['nullable', 'string', 'max:24'],
        ]);

        $validator->after(function (ValidatorContract $validator) use ($request, $part): void {
            $variants = $request->input('variants', []);
            if (! is_array($variants)) {
                return;
            }

            foreach ($variants as $variantIndex => $variantData) {
                if (! is_array($variantData)) {
                    continue;
                }

                if (! empty($variantData['id'])) {
                    $existing = PartVariant::query()->find($variantData['id']);
                    if ($existing && $existing->part_id !== $part?->id) {
                        $validator->errors()->add("variants.{$variantIndex}.id", 'That variant does not belong to this part.');
                    }
                }

                if (filled($variantData['sku'] ?? null)) {
                    $skuQuery = PartVariant::query()->where('sku', $variantData['sku']);
                    if (! empty($variantData['id'])) {
                        $skuQuery->where('id', '!=', $variantData['id']);
                    }
                    if ($skuQuery->exists()) {
                        $validator->errors()->add("variants.{$variantIndex}.sku", 'This variant SKU is already in use.');
                    }
                }

                foreach ($variantData['attributes'] ?? [] as $attributeIndex => $attributeData) {
                    if (filled($attributeData['name'] ?? null) !== filled($attributeData['value'] ?? null)) {
                        $validator->errors()->add("variants.{$variantIndex}.attributes.{$attributeIndex}.value", 'Provide both an attribute name and value.');
                    }
                }
            }
        });

        $validated = $validator->validate();
        foreach ($validated['variants'] as &$variant) {
            $variant['attributes'] = collect($variant['attributes'] ?? [])->filter(fn (array $attribute): bool => filled($attribute['name'] ?? null) && filled($attribute['value'] ?? null))->values()->all();
        }

        return $validated;
    }

    private function syncVariantAttributes(PartVariant $variant, array $attributes): void
    {
        $valueIds = [];

        foreach ($attributes as $attributeData) {
            $attribute = ProductAttribute::firstOrCreate(
                ['slug' => Str::slug($attributeData['name'])],
                ['name' => $attributeData['name'], 'unit' => $attributeData['unit'] ?? null],
            );
            $value = ProductAttributeValue::firstOrCreate(
                ['attribute_id' => $attribute->id, 'value' => $attributeData['value']],
            );
            $valueIds[] = $value->id;
        }

        $variant->attributeValues()->sync($valueIds);
    }

    private function validateService(Request $request, ?Service $service = null): array
    {
        $validated = $request->validate([
            'category_id' => ['required', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', Rule::unique('services', 'slug')->ignore($service?->id)],
            'summary' => ['required', 'string', 'max:280'],
            'description' => ['required', 'string', 'max:6000'],
            'labor_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'shared_operation_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'labor_category' => ['nullable', Rule::in(['cvt', 'frame', 'engine', 'electrical_diagnosis', 'electrical_installation'])],
            'labor_pricing_mode' => ['nullable', Rule::in(['fixed', 'time_based'])],
            'requires_full_day' => ['required', 'boolean'],
            'requires_operation_consent' => ['required', 'boolean'],
            'requires_bore_up_package' => ['required', 'boolean'],
            'labor_group' => ['nullable', 'string', 'max:80'],
            'part_variant_ids' => ['nullable', 'array', 'max:40'],
            'part_variant_ids.*' => ['integer', 'distinct', 'exists:part_variants,id'],
            'service_parts' => ['nullable', 'array', 'max:40'],
            'service_parts.*.component_label' => ['nullable', 'string', 'max:140'],
            'service_parts.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:99'],
            'service_parts.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'service_parts.*.is_required' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'included_items' => ['nullable', 'string', 'max:4000'],
            'excluded_items' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $sharedMinutes = (int) ($validated['shared_operation_minutes'] ?? 0);
        if ($sharedMinutes > 0 && blank($validated['labor_group'] ?? null)) {
            throw ValidationException::withMessages(['labor_group' => 'Set a shared labor group when a service includes common operation time.']);
        }
        if ($sharedMinutes > (int) ($validated['duration_minutes'] ?? 0)) {
            throw ValidationException::withMessages(['shared_operation_minutes' => 'Shared operation time cannot exceed the service duration.']);
        }
        if ($validated['requires_full_day'] && ! $validated['requires_operation_consent']) {
            throw ValidationException::withMessages(['requires_operation_consent' => 'Full-day vehicle operation must require customer consent.']);
        }

        return $validated;
    }

    /** @param list<int|string> $variantIds @param array<string, array<string, mixed>> $settings */
    private function syncServicePartVariants(Service $service, array $variantIds, array $settings = []): void
    {
        $pivot = [];
        foreach (PartVariant::query()->with('part')->whereIn('id', array_map('intval', $variantIds))->get() as $variant) {
            $config = $settings[(string) $variant->id] ?? $settings[$variant->id] ?? [];
            $pivot[$variant->id] = [
                'component_label' => filled($config['component_label'] ?? null) ? $config['component_label'] : $variant->part->name,
                'quantity' => $config['quantity'] ?? 1,
                'duration_minutes' => $config['duration_minutes'] ?? null,
                'is_required' => (bool) ($config['is_required'] ?? false),
            ];
        }
        $service->partVariants()->sync($pivot);
    }

    private function validatePackage(Request $request, ?ServicePackage $package = null): array
    {
        $validator = Validator::make($request->all(), [
            'category_id' => ['nullable', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'alpha_dash', 'max:180', Rule::unique('packages', 'slug')->ignore($package?->id)],
            'summary' => ['required', 'string', 'max:280'],
            'description' => ['required', 'string', 'max:6000'],
            'base_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'labor_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'discount_rupiah' => ['nullable', 'integer', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'requires_fitment_confirmation' => ['required', 'boolean'],
            'compatible_model_ids' => ['nullable', 'array', 'max:100'],
            'compatible_model_ids.*' => ['integer', 'distinct', Rule::exists('vehicle_models', 'id')->where('is_active', true)],
            'compatible_model_compatibility' => ['nullable', 'array', 'max:100'],
            'compatible_model_compatibility.*' => ['nullable', Rule::in(['compatible', 'unverified', 'incompatible'])],
            'compatible_model_sources' => ['nullable', 'array', 'max:100'],
            'compatible_model_sources.*' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.component_type' => ['required', Rule::in(['service', 'part', 'inspection', 'cleaning', 'task'])],
            'items.*.label' => ['required', 'string', 'max:180'],
            'items.*.service_id' => ['nullable', 'exists:services,id'],
            'items.*.part_id' => ['nullable', 'exists:parts,id'],
            'items.*.part_variant_id' => ['nullable', 'exists:part_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.extra_price_rupiah' => ['nullable', 'integer', 'min:0'],
            'items.*.is_required' => ['nullable', 'boolean'],
        ]);

        $validator->after(function (ValidatorContract $validator) use ($request): void {
            $items = $request->input('items', []);
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (($item['component_type'] ?? null) === 'service' && empty($item['service_id'])) {
                    $validator->errors()->add("items.{$index}.service_id", 'Choose a service for this package item.');
                }
                if (($item['component_type'] ?? null) === 'part' && empty($item['part_id'])) {
                    $validator->errors()->add("items.{$index}.part_id", 'Choose a part for this package item.');
                }
                if (! empty($item['part_variant_id'])) {
                    $variant = PartVariant::query()->find($item['part_variant_id']);
                    if (($item['component_type'] ?? null) !== 'part') {
                        $validator->errors()->add("items.{$index}.part_variant_id", 'A variant can only be attached to a part component.');
                    }
                    if ($variant && ! empty($item['part_id']) && (int) $variant->part_id !== (int) $item['part_id']) {
                        $validator->errors()->add("items.{$index}.part_variant_id", 'The selected variant does not belong to the selected part.');
                    }
                }
            }

            foreach ($request->input('compatible_model_ids', []) as $modelId) {
                $status = $request->input("compatible_model_compatibility.{$modelId}", 'unverified');
                if ($status === 'compatible' && blank($request->input("compatible_model_sources.{$modelId}"))) {
                    $validator->errors()->add("compatible_model_sources.{$modelId}", 'Add a source or workshop verification reference for a compatible model.');
                }
            }
        });

        return $validator->validate();
    }

    /** @param array<string, mixed> $validated */
    private function syncPackageCompatibility(ServicePackage $package, array $validated, int $actorId): void
    {
        $pivot = [];
        foreach (array_map('intval', $validated['compatible_model_ids'] ?? []) as $modelId) {
            $status = $validated['compatible_model_compatibility'][$modelId] ?? 'unverified';
            $pivot[$modelId] = [
                'compatibility_status' => $status,
                'source_reference' => $validated['compatible_model_sources'][$modelId] ?? null,
                'verified_by' => $status === 'compatible' ? $actorId : null,
                'verified_at' => $status === 'compatible' ? now() : null,
            ];
        }
        $package->compatibleModels()->sync($pivot);
    }

    private function savePackageItems(ServicePackage $package, array $items): void
    {
        $package->items()->delete();

        foreach ($items as $index => $item) {
            PackageItem::create([
                'package_id' => $package->id,
                'service_id' => $item['service_id'] ?? null,
                'part_id' => $item['part_id'] ?? null,
                'part_variant_id' => $item['part_variant_id'] ?? null,
                'component_type' => $item['component_type'],
                'label' => $item['label'],
                'quantity' => $item['quantity'],
                'extra_price_rupiah' => $item['extra_price_rupiah'] ?? null,
                'is_required' => (bool) ($item['is_required'] ?? false),
                'sort_order' => $index + 1,
            ]);
        }
    }

    private function storeImage(Request $request, ?string $current = null): ?string
    {
        return $request->hasFile('image')
            ? $request->file('image')->store('catalog', 'public')
            : $current;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function makeUniqueSlug(string $modelClass, string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name) ?: 'catalog-item';
        $slug = $baseSlug;
        $suffix = 2;

        while ($modelClass::query()->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function cleanLines(array|string $lines): array
    {
        if (is_string($lines)) {
            $lines = preg_split('/\R/u', $lines) ?: [];
        }

        return collect($lines)->map(fn ($line) => trim((string) $line))->filter()->values()->all();
    }
}
