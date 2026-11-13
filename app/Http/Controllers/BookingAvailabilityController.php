<?php

namespace App\Http\Controllers;

use App\Models\PartVariant;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Services\LaborPricingService;
use App\Services\WorkshopAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingAvailabilityController extends Controller
{
    public function __invoke(Request $request, WorkshopAvailabilityService $availability): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['nullable', Rule::in(['manual', 'automatic'])],
            'date' => ['required_if:mode,manual', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(90)->toDateString()],
            'service_ids' => ['nullable', 'array', 'max:30'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'package_ids' => ['nullable', 'array', 'max:20'],
            'package_ids.*' => ['integer', 'distinct', 'exists:packages,id'],
            'package_options' => ['nullable', 'array', 'max:20'],
            'package_options.*' => ['array', 'max:40'],
            'package_options.*.*' => ['integer', 'distinct', 'exists:package_items,id'],
            'service_part_variant_ids' => ['nullable', 'array', 'max:30'],
            'service_part_variant_ids.*' => ['array', 'max:40'],
            'service_part_variant_ids.*.*' => ['integer', 'distinct', 'exists:part_variants,id'],
        ]);

        $serviceIds = $validated['service_ids'] ?? [];
        $packageIds = $validated['package_ids'] ?? [];
        $services = Service::public()->with(['category', 'partVariants.part'])->whereIn('id', $serviceIds)->get()->keyBy('id');
        $packages = ServicePackage::public()->with(['category', 'items.service.category'])->whereIn('id', $packageIds)->get()->keyBy('id');
        if ($services->count() !== count($serviceIds) || $packages->count() !== count($packageIds)) {
            throw ValidationException::withMessages(['services' => 'Refresh the catalog and choose currently available work.']);
        }
        $fullDayServices = $services->filter(fn (Service $service): bool => $service->requires_full_day);
        if ($fullDayServices->isNotEmpty()) {
            $hasBoreUpPackage = $packages->contains(fn (ServicePackage $package): bool => str_starts_with($package->slug, 'bore-up-'));
            if ($fullDayServices->count() !== 1 || $services->count() !== 1 || ! $hasBoreUpPackage || $packages->contains(fn (ServicePackage $package): bool => ! str_starts_with($package->slug, 'bore-up-'))) {
                throw ValidationException::withMessages(['service_ids' => 'The full-day break-in service can only be booked with one Bore-Up package.']);
            }
        }
        foreach (array_keys($validated['service_part_variant_ids'] ?? []) as $serviceId) {
            if (! in_array((int) $serviceId, array_map('intval', $serviceIds), true)) {
                throw ValidationException::withMessages(['service_part_variant_ids' => 'Select product options only for chosen services.']);
            }
        }

        $partDurationByService = [];
        $categories = [];
        foreach ($services as $service) {
            $categories[] = (int) $service->category_id;
            $requested = collect($validated['service_part_variant_ids'][$service->id] ?? [])->map(fn ($id) => (int) $id)->unique();
            $activeVariants = $service->partVariants->filter(fn (PartVariant $variant): bool => $variant->is_active && $variant->part?->is_active);
            $allowedIds = $activeVariants->pluck('id')->map(fn ($id) => (int) $id);
            if ($requested->diff($allowedIds)->isNotEmpty()) {
                throw ValidationException::withMessages(['service_part_variant_ids' => 'Refresh the service options and select only attached active products.']);
            }
            $selected = $requested->merge($activeVariants->filter(fn (PartVariant $variant): bool => (bool) $variant->pivot->is_required)->pluck('id'))->unique();
            $partDurationByService[$service->id] = (int) $activeVariants->whereIn('id', $selected)->sum(fn (PartVariant $variant): int => (int) ($variant->pivot->duration_minutes ?? 0));
        }
        $durationMinutes = app(LaborPricingService::class)->durationForServices($services, $partDurationByService);
        foreach ($packages as $package) {
            if ($package->category_id !== null) {
                $categories[] = (int) $package->category_id;
            }
            $optionalIds = array_map('intval', $validated['package_options'][$package->id] ?? []);
            $optionalItems = $package->items->whereIn('id', $optionalIds)->where('is_required', false);
            if ($optionalItems->count() !== count(array_unique($optionalIds))) {
                throw ValidationException::withMessages(['package_options' => 'Refresh the package choices and select only optional items in the chosen packages.']);
            }
            foreach ($package->items as $item) {
                if (! $item->is_required && ! in_array((int) $item->id, $optionalIds, true)) {
                    continue;
                }
                if ($item->service_id && ! Service::public()->whereKey($item->service_id)->exists()) {
                    throw ValidationException::withMessages(['package_ids' => 'A service in this package is no longer available.']);
                }
                if ($item->service) {
                    $durationMinutes += (int) ($item->service->duration_minutes ?? 0);
                    $categories[] = (int) $item->service->category_id;
                }
            }
            $durationMinutes += (int) ($package->duration_minutes ?? 0);
        }

        $durationMinutes = min(1440, max(30, $durationMinutes));

        if (($validated['mode'] ?? 'manual') === 'automatic') {
            if ($fullDayServices->isNotEmpty()) {
                $recommended = $availability->recommendFullDay(now()->startOfDay(), array_values(array_unique($categories)));

                return response()->json([
                    'duration_minutes' => $recommended['duration_minutes'] ?? 0,
                    'recommended' => $recommended,
                    'slots' => $recommended ? [['start_at' => $recommended['start_at'], 'status' => 'recommended']] : [],
                    'day_status' => $recommended ? 'open' : 'full',
                ]);
            }

            $recommended = $availability->recommendEarliest(now()->startOfDay(), $durationMinutes, array_values(array_unique($categories)));

            return response()->json([
                'duration_minutes' => $durationMinutes,
                'recommended' => $recommended,
                'slots' => $recommended ? [['start_at' => $recommended['start_at'], 'status' => 'recommended']] : [],
                'day_status' => $recommended ? 'open' : 'full',
            ]);
        }

        $date = Carbon::createFromFormat('Y-m-d', $validated['date']);

        return response()->json($fullDayServices->isNotEmpty()
            ? $availability->fullDaySlotMap($date, array_values(array_unique($categories)))
            : $availability->slotMap($date, $durationMinutes, array_values(array_unique($categories))));
    }
}
