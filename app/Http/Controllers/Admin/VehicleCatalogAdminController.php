<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerVehicleModelRequest;
use App\Models\PartVariant;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleModel;
use App\Models\VehicleModelYear;
use App\Models\VehicleVariant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VehicleCatalogAdminController extends Controller
{
    public function index(): View
    {
        return view('admin.vehicles', [
            'categories' => VehicleCategory::query()->withCount('models')->orderBy('name')->get(),
            'brands' => VehicleBrand::query()->withCount('models')->orderBy('name')->get(),
            'models' => VehicleModel::query()->with(['brand', 'category', 'generations.years', 'generations.variants.years', 'generations.variants.attributes'])->orderBy('brand_id')->orderBy('name')->get(),
            'modelRequests' => CustomerVehicleModelRequest::query()->with('customer')->latest()->limit(50)->get(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash', 'max:120', 'unique:vehicle_categories,slug'],
        ]);

        VehicleCategory::create($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Vehicle category created.');
    }

    public function updateCategory(Request $request, VehicleCategory $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('vehicle_categories', 'slug')->ignore($category->id)],
        ]);

        $category->update($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Vehicle category updated.');
    }

    public function storeBrand(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', Rule::in(['Honda', 'Yamaha', 'Suzuki', 'Vespa'])],
            'slug' => ['required', 'alpha_dash', 'max:140', 'unique:vehicle_brands,slug'],
        ]);

        VehicleBrand::create($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Vehicle brand created.');
    }

    public function updateBrand(Request $request, VehicleBrand $brand): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', Rule::in(['Honda', 'Yamaha', 'Suzuki', 'Vespa'])],
            'slug' => ['required', 'alpha_dash', 'max:140', Rule::unique('vehicle_brands', 'slug')->ignore($brand->id)],
        ]);

        $brand->update($validated + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Vehicle brand updated.');
    }

    public function storeModel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'name' => ['required', 'string', 'max:140'],
            'slug' => ['nullable', 'alpha_dash', 'max:160'],
            'year_from' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'year_to' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'verification_status' => ['nullable', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $slug = $validated['slug'] ?: Str::slug($validated['name']);
        $this->assertAllowedBrandAndCategory($validated['brand_id'], $validated['category_id']);
        $this->assertModelYears($request, $validated);
        Validator::make(['slug' => $slug], ['slug' => [Rule::unique('vehicle_models', 'slug')->where('brand_id', $validated['brand_id'])]])->validate();

        VehicleModel::create(array_merge($validated, [
            'slug' => $slug,
            'is_active' => $request->boolean('is_active'),
        ]));

        return back()->with('status', 'Vehicle model created.');
    }

    public function updateModel(Request $request, VehicleModel $vehicleModel): RedirectResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'name' => ['required', 'string', 'max:140'],
            'slug' => ['nullable', 'alpha_dash', 'max:160'],
            'year_from' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'year_to' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'verification_status' => ['nullable', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $slug = $validated['slug'] ?: Str::slug($validated['name']);
        $this->assertAllowedBrandAndCategory($validated['brand_id'], $validated['category_id']);
        $this->assertModelYears($request, $validated);
        Validator::make(['slug' => $slug], ['slug' => [Rule::unique('vehicle_models', 'slug')->where('brand_id', $validated['brand_id'])->ignore($vehicleModel->id)]])->validate();

        $vehicleModel->update(array_merge($validated, [
            'slug' => $slug,
            'is_active' => $request->boolean('is_active'),
        ]));

        return back()->with('status', 'Vehicle model updated.');
    }

    public function storeGeneration(Request $request, VehicleModel $vehicleModel): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'years' => ['required', 'string', 'max:1000'],
            'verification_status' => ['required', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $years = collect(explode(',', $data['years']))->map(fn ($year) => trim($year))->filter()->unique()->values();
        if ($years->isEmpty() || $years->contains(fn ($year) => ! ctype_digit($year) || (int) $year < 1950 || (int) $year > now()->year + 1)) {
            throw ValidationException::withMessages(['years' => 'Enter valid model years separated by commas.']);
        }
        $generation = VehicleGeneration::create([
            'vehicle_model_id' => $vehicleModel->id,
            'name' => $data['name'],
            'year_from' => (int) $years->min(),
            'year_to' => (int) $years->max(),
            'is_active' => true,
            'verification_status' => $data['verification_status'],
            'data_source' => $data['data_source'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        foreach ($years as $year) {
            VehicleModelYear::create([
                'vehicle_model_id' => $vehicleModel->id,
                'vehicle_generation_id' => $generation->id,
                'model_year' => (int) $year,
                'is_active' => true,
                'verification_status' => $data['verification_status'],
                'data_source' => $data['data_source'] ?? null,
            ]);
        }

        return back()->with('status', 'Vehicle generation and model years saved. Add only source-backed trims for customer selection.');
    }

    public function updateGeneration(Request $request, VehicleModel $vehicleModel, VehicleGeneration $generation): RedirectResponse
    {
        abort_unless((int) $generation->vehicle_model_id === (int) $vehicleModel->id, 404);
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'verification_status' => ['required', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $active = $request->boolean('is_active');
        $generation->update($data + ['is_active' => $active]);
        $generation->years()->update(['is_active' => $active, 'verification_status' => $data['verification_status'], 'data_source' => $data['data_source'] ?? null]);

        return back()->with('status', 'Vehicle generation and model-year source updated.');
    }

    public function storeVariant(Request $request, VehicleModel $vehicleModel, VehicleGeneration $generation): RedirectResponse
    {
        abort_unless((int) $generation->vehicle_model_id === (int) $vehicleModel->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:140'],
            'model_year_ids' => ['required', 'array', 'min:1', 'max:30'],
            'model_year_ids.*' => ['required', 'integer', 'distinct', 'exists:vehicle_model_years,id'],
            'verification_status' => ['required', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attributes' => ['nullable', 'string', 'max:5000'],
        ]);
        $yearIds = collect($data['model_year_ids'])->map(fn ($id) => (int) $id)->unique();
        $validYearIds = $generation->years()->whereIn('id', $yearIds)->pluck('id');
        if ($validYearIds->count() !== $yearIds->count()) {
            throw ValidationException::withMessages(['model_year_ids' => 'Choose model years from this generation only.']);
        }
        $variant = VehicleVariant::create([
            'vehicle_model_id' => $vehicleModel->id,
            'vehicle_generation_id' => $generation->id,
            'name' => $data['name'],
            'is_active' => true,
            'verification_status' => $data['verification_status'],
            'data_source' => $data['data_source'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $variant->years()->sync($validYearIds);
        foreach (preg_split('/\R/', $data['attributes'] ?? '') as $line) {
            [$name, $value] = array_pad(array_map('trim', explode('=', $line, 2)), 2, null);
            if ($name !== '' && $value !== null && $value !== '') {
                $variant->attributes()->create(['name' => mb_substr($name, 0, 100), 'value' => mb_substr($value, 0, 160)]);
            }
        }

        return back()->with('status', 'Vehicle trim saved with year-specific availability.');
    }

    public function updateVariant(Request $request, VehicleModel $vehicleModel, VehicleVariant $variant): RedirectResponse
    {
        abort_unless((int) $variant->vehicle_model_id === (int) $vehicleModel->id, 404);
        $data = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'verification_status' => ['required', Rule::in(['verified', 'needs_verification'])],
            'data_source' => ['nullable', 'url', 'max:255', Rule::requiredIf($request->input('verification_status') === 'verified')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $variant->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Vehicle trim status and source updated.');
    }

    public function reviewModelRequest(Request $request, int $modelRequest): RedirectResponse
    {
        $record = CustomerVehicleModelRequest::query()->findOrFail($modelRequest);
        $data = $request->validate([
            'status' => ['required', Rule::in(['in_review', 'resolved', 'declined'])],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $record->update($data + ['reviewed_by' => $request->user()->id]);

        return back()->with('status', 'Vehicle catalog request reviewed. A request never publishes an unverified trim by itself.');
    }

    public function compatibility(): View
    {
        return view('admin.compatibility', [
            'variants' => PartVariant::query()->with(['part.brand', 'part.category', 'attributeValues.attribute', 'compatibleModels.variants'])->orderBy('part_id')->orderBy('name')->get(),
            'models' => VehicleModel::query()->with(['brand', 'variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])->where('is_active', true)->whereHas('brand', fn ($brands) => $brands->where('is_active', true))->orderBy('brand_id')->orderBy('name')->get(),
        ]);
    }

    public function updateCompatibility(Request $request, PartVariant $variant): RedirectResponse
    {
        $validated = $request->validate([
            'vehicle_model_id' => ['required', 'exists:vehicle_models,id'],
            'vehicle_variant_id' => ['nullable', 'integer', 'exists:vehicle_variants,id'],
            'compatibility_status' => ['required', Rule::in(['unverified', 'compatible', 'incompatible'])],
            'source_type' => ['required', Rule::in(['unverified', 'manufacturer_catalog', 'aftermarket_catalog', 'mechanic_confirmed'])],
            'source_name' => ['nullable', 'string', 'max:160', Rule::requiredIf($request->input('compatibility_status') !== 'unverified')],
            'source_reference' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('compatibility_status') !== 'unverified')],
            'part_number' => ['nullable', 'string', 'max:120'],
            'compatible_year_from' => ['nullable', 'integer', 'between:1950,2100'],
            'compatible_year_to' => ['nullable', 'integer', 'between:1950,2100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = VehicleModel::query()->findOrFail($validated['vehicle_model_id']);
        if (! empty($validated['vehicle_variant_id']) && (int) VehicleVariant::query()->whereKey($validated['vehicle_variant_id'])->value('vehicle_model_id') !== (int) $model->id) {
            throw ValidationException::withMessages(['vehicle_variant_id' => 'Choose a trim that belongs to the selected motorcycle model.']);
        }
        if (! empty($validated['compatible_year_from']) && ! empty($validated['compatible_year_to']) && $validated['compatible_year_from'] > $validated['compatible_year_to']) {
            throw ValidationException::withMessages(['compatible_year_to' => 'The ending fitment year must be the same as or later than the starting year.']);
        }
        if ($validated['compatibility_status'] !== 'unverified' && $validated['source_type'] === 'unverified') {
            throw ValidationException::withMessages(['source_type' => 'Select a documented source type before marking fitment verified.']);
        }
        if ($validated['compatibility_status'] === 'unverified') {
            $validated['source_type'] = 'unverified';
            $validated['source_name'] = null;
            $validated['source_reference'] = null;
        }

        $isVerified = $validated['compatibility_status'] !== 'unverified';
        $variant->compatibleModels()->syncWithoutDetaching([
            $validated['vehicle_model_id'] => [
                'vehicle_variant_id' => $validated['vehicle_variant_id'] ?? null,
                'compatible_year_from' => $validated['compatible_year_from'] ?? null,
                'compatible_year_to' => $validated['compatible_year_to'] ?? null,
                'source_name' => $validated['source_name'] ?? null,
                'source_type' => $validated['source_type'],
                'part_number' => $validated['part_number'] ?? null,
                'compatibility_status' => $validated['compatibility_status'],
                'source_reference' => $validated['source_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'verified_by' => $isVerified ? $request->user()->id : null,
                'verified_at' => $isVerified ? now() : null,
            ],
        ]);

        return back()->with('status', 'Compatibility record updated.');
    }

    private function assertModelYears(Request $request, array $validated): void
    {
        if (! empty($validated['year_from']) && ! empty($validated['year_to']) && $validated['year_to'] < $validated['year_from']) {
            throw ValidationException::withMessages(['year_to' => 'The final model year must be the same as or later than the first model year.']);
        }
    }

    private function assertAllowedBrandAndCategory(int|string $brandId, int|string $categoryId): void
    {
        $brand = VehicleBrand::query()->findOrFail($brandId);
        $category = VehicleCategory::query()->findOrFail($categoryId);
        if (! in_array($brand->slug, ['honda', 'yamaha', 'suzuki', 'vespa'], true)) {
            throw ValidationException::withMessages(['brand_id' => 'Only Honda, Yamaha, Suzuki, and Vespa can be configured as supported manufacturers.']);
        }
        if ($brand->slug === 'vespa' && $category->slug !== 'automatic-scooter') {
            throw ValidationException::withMessages(['category_id' => 'Vespa entries in this catalog are restricted to factory automatic scooters.']);
        }
    }
}
