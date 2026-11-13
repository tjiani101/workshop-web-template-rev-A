<?php

namespace App\Http\Controllers;

use App\Models\CustomerVehicle;
use App\Models\VehicleModelYear;
use App\Models\VehicleVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerVehicleController extends Controller
{
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validatedVehicle($request);
        $variant = $this->resolveVariantAndYear($data['variant_id'], $data['model_year']);
        $isFirst = ! $request->user()->customerVehicles()->exists();
        $vehicle = $request->user()->customerVehicles()->create([
            'model_id' => $variant->vehicle_model_id,
            'vehicle_variant_id' => $variant->id,
            'model_year' => $data['model_year'],
            'registration_number' => $data['registration_number'] ?? null,
            'vin' => $data['vin'] ?? null,
            'odometer_km' => $data['odometer_km'] ?? null,
            'nickname' => $data['nickname'] ?? null,
            'is_default' => $isFirst,
        ]);
        $vehicle->load(['model.brand', 'model.category', 'variant.generation', 'variant.attributes']);

        if ($request->expectsJson()) {
            return response()->json(['vehicle' => $this->vehiclePayload($vehicle)], 201);
        }

        return back()->with('status', 'Motorcycle added to your MINEX Garage.');
    }

    public function update(Request $request, int $vehicleId): RedirectResponse
    {
        $vehicle = $request->user()->customerVehicles()->findOrFail($vehicleId);
        $data = $this->validatedVehicle($request, $vehicle);
        $variant = $this->resolveVariantAndYear($data['variant_id'], $data['model_year']);
        $vehicle->update([
            'model_id' => $variant->vehicle_model_id,
            'vehicle_variant_id' => $variant->id,
            'model_year' => $data['model_year'],
            'registration_number' => $data['registration_number'] ?? null,
            'vin' => $data['vin'] ?? null,
            'odometer_km' => $data['odometer_km'] ?? null,
            'nickname' => $data['nickname'] ?? null,
        ]);

        return back()->with('status', 'Motorcycle details updated.');
    }

    public function setDefault(Request $request, int $vehicleId): RedirectResponse
    {
        $vehicle = $request->user()->customerVehicles()->findOrFail($vehicleId);
        DB::transaction(function () use ($request, $vehicle): void {
            $request->user()->customerVehicles()->lockForUpdate()->update(['is_default' => false]);
            $vehicle->update(['is_default' => true]);
        });

        return back()->with('status', 'Default motorcycle updated.');
    }

    public function destroy(Request $request, int $vehicleId): RedirectResponse
    {
        $vehicle = $request->user()->customerVehicles()->findOrFail($vehicleId);
        if ($vehicle->bookings()->exists()) {
            throw ValidationException::withMessages(['vehicle' => 'A motorcycle with booking history cannot be deleted. You can update its details instead.']);
        }
        $wasDefault = $vehicle->is_default;
        $vehicle->delete();
        if ($wasDefault) {
            $request->user()->customerVehicles()->oldest('id')->first()?->update(['is_default' => true]);
        }

        return back()->with('status', 'Motorcycle removed from your Garage.');
    }

    private function validatedVehicle(Request $request, ?CustomerVehicle $vehicle = null): array
    {
        return $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('vehicle_variants', 'id')->where('is_active', true)],
            'model_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'registration_number' => ['nullable', 'string', 'max:32', Rule::unique('customer_vehicles', 'registration_number')->ignore($vehicle?->id)],
            'vin' => ['nullable', 'string', 'max:64', Rule::unique('customer_vehicles', 'vin')->ignore($vehicle?->id)],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'nickname' => ['nullable', 'string', 'max:80'],
        ]);
    }

    private function resolveVariantAndYear(int|string $variantId, int|string $modelYear): VehicleVariant
    {
        $variant = VehicleVariant::query()->with(['model.brand', 'model.category', 'generation'])
            ->whereKey($variantId)->where('is_active', true)
            ->whereHas('model', fn ($models) => $models->where('is_active', true)->whereHas('brand', fn ($brands) => $brands->where('is_active', true)->whereIn('slug', ['honda', 'yamaha', 'suzuki', 'vespa']))->whereHas('category', fn ($categories) => $categories->where('is_active', true)))
            ->first();
        $year = VehicleModelYear::query()->where('vehicle_model_id', $variant?->vehicle_model_id)->where('model_year', $modelYear)->where('is_active', true)->first();

        if (! $variant || ! $year || (int) $year->vehicle_generation_id !== (int) $variant->vehicle_generation_id || ! $variant->years()->whereKey($year->id)->exists()) {
            throw ValidationException::withMessages(['variant_id' => 'Choose a trim that is listed for the selected model year and generation.']);
        }
        if ($variant->model->brand->slug === 'vespa' && $variant->model->category->slug !== 'automatic-scooter') {
            throw ValidationException::withMessages(['variant_id' => 'Only Vespa factory automatic scooter models are supported in this catalog.']);
        }

        return $variant;
    }

    private function vehiclePayload(CustomerVehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'label' => trim($vehicle->model->brand->name.' '.$vehicle->model->name.' · '.$vehicle->variant->name.' · '.$vehicle->model_year),
            'brand' => $vehicle->model->brand->name,
            'model' => $vehicle->model->name,
            'category' => $vehicle->model->category->name,
            'generation' => $vehicle->variant->generation->name,
            'variant' => $vehicle->variant->name,
            'model_year' => $vehicle->model_year,
            'registration_number' => $vehicle->registration_number,
        ];
    }
}
