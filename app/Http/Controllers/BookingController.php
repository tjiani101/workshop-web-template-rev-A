<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\BookingStatusHistory;
use App\Models\PackageVehiclePrice;
use App\Models\Part;
use App\Models\PartVariant;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\VehicleModel;
use App\Models\VehicleModelYear;
use App\Models\VehicleVariant;
use App\Services\AuditLogger;
use App\Services\LaborPricingService;
use App\Services\NotificationService;
use App\Services\WorkshopAvailabilityService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function create(Request $request): View
    {
        $user = $request->user();
        abort_if($user && ! $user->hasRole('customer'), 403);

        $token = (string) Str::uuid();
        $request->session()->put('booking_submission_token', $token);
        $services = Service::public()->with(['category', 'partVariants.part.brand', 'partVariants.attributeValues.attribute', 'partVariants.compatibleModels'])->orderBy('name')->get();
        $services->each(fn (Service $service) => $service->setAttribute('estimated_labor_price_rupiah', $service->labor_pricing_mode === 'fixed' ? $service->labor_price_rupiah : null));

        return view('bookings.create', [
            'submissionToken' => $token,
            'services' => $services,
            'packages' => ServicePackage::public()->with(['items.service', 'items.part.brand', 'items.partVariant.attributeValues.attribute', 'items.partVariant.compatibleModels'])->orderBy('name')->get(),
            'customerVehicles' => $user?->customerVehicles()->with('model.brand', 'model.category', 'variant.generation')->orderByDesc('is_default')->orderBy('nickname')->get() ?? collect(),
            'vehicleCatalogUrl' => route('vehicle-catalog.index'),
            'laborRates' => app(LaborPricingService::class)->rates(),
            'prefilledServiceIds' => array_filter([Service::public()->where('slug', $request->query('service'))->value('id')]),
            'prefilledPackageIds' => array_filter([ServicePackage::public()->where('slug', $request->query('package'))->value('id')]),
        ]);
    }

    public function store(Request $request, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_if($user && ! $user->hasRole('customer'), 403);
        $isMember = $user !== null;

        $validated = $request->validate([
            'submission_token' => ['required', 'uuid'],
            'customer_name' => [$isMember ? 'nullable' : 'required', 'string', 'max:120'],
            'customer_email' => [$isMember ? 'nullable' : 'required', 'email', 'max:254'],
            'customer_phone' => [Rule::requiredIf(! $isMember || blank($user->phone)), 'nullable', 'string', 'max:40'],
            'customer_vehicle_id' => [$isMember ? 'required' : 'prohibited', 'integer', 'exists:customer_vehicles,id'],
            'vehicle_variant_id' => [$isMember ? 'prohibited' : 'required', 'integer', Rule::exists('vehicle_variants', 'id')->where('is_active', true)],
            'model_year' => [$isMember ? 'prohibited' : 'required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'service_ids' => ['nullable', 'array', 'max:30'],
            'service_ids.*' => ['integer', 'distinct', 'exists:services,id'],
            'package_ids' => ['nullable', 'array', 'max:20'],
            'package_ids.*' => ['integer', 'distinct', 'exists:packages,id'],
            'package_options' => ['nullable', 'array'],
            'package_options.*' => ['array', 'max:40'],
            'package_options.*.*' => ['integer', 'distinct', 'exists:package_items,id'],
            'service_part_variant_ids' => ['nullable', 'array', 'max:30'],
            'service_part_variant_ids.*' => ['array', 'max:40'],
            'service_part_variant_ids.*.*' => ['integer', 'distinct', 'exists:part_variants,id'],
            'payment_percentage' => [$isMember ? 'required' : 'nullable', Rule::in($isMember ? [10, 25, 50, 100] : [null, 100])],
            'preferred_start_at' => ['required', 'date', 'after:now'],
            'operation_consent' => ['sometimes', 'accepted'],
            'customer_notes' => ['nullable', 'string', 'max:3000'],
            'terms_accepted' => ['accepted'],
        ]);

        $existingSubmission = Booking::query()->where('submission_token', $validated['submission_token'])->first();
        if ($existingSubmission) {
            return redirect()->route('bookings.received', $existingSubmission->reference);
        }

        abort_unless($request->session()->get('booking_submission_token') === $validated['submission_token'], 419);

        $customerVehicle = null;
        if ($isMember) {
            $customerVehicle = $user->customerVehicles()->with(['model.brand', 'model.category', 'variant.generation', 'variant.attributes'])->find($validated['customer_vehicle_id']);
            if (! $customerVehicle?->variant || ! $customerVehicle->model->is_active || ! $customerVehicle->model->brand->is_active || ! $customerVehicle->model->category->is_active) {
                throw ValidationException::withMessages(['customer_vehicle_id' => 'Choose an active Garage motorcycle with a verified year and trim. Update it in your Garage if its record is incomplete.']);
            }
            $vehicleVariant = $customerVehicle->variant;
            $vehicleYear = (int) $customerVehicle->model_year;
        } else {
            $vehicleVariant = VehicleVariant::query()->with(['model.brand', 'model.category', 'generation', 'attributes'])
                ->whereKey($validated['vehicle_variant_id'])->where('is_active', true)
                ->whereHas('model', fn ($models) => $models->where('is_active', true)->whereHas('brand', fn ($brands) => $brands->where('is_active', true)->whereIn('slug', ['honda', 'yamaha', 'suzuki', 'vespa']))->whereHas('category', fn ($categories) => $categories->where('is_active', true)))
                ->first();
            $yearRecord = $vehicleVariant ? VehicleModelYear::query()->where('vehicle_model_id', $vehicleVariant->vehicle_model_id)->where('vehicle_generation_id', $vehicleVariant->vehicle_generation_id)->where('model_year', $validated['model_year'])->where('is_active', true)->first() : null;
            if (! $vehicleVariant || ! $yearRecord || ! $vehicleVariant->years()->whereKey($yearRecord->id)->exists()) {
                throw ValidationException::withMessages(['vehicle_variant_id' => 'Choose a trim listed for the selected model year and generation.']);
            }
            if ($vehicleVariant->model->brand->slug === 'vespa' && $vehicleVariant->model->category->slug !== 'automatic-scooter') {
                throw ValidationException::withMessages(['vehicle_variant_id' => 'Only Vespa factory automatic scooter models are supported in this catalog.']);
            }
            $vehicleYear = (int) $validated['model_year'];
        }
        $vehicleModel = $vehicleVariant->model->loadMissing(['brand', 'category']);

        $serviceIds = $validated['service_ids'] ?? [];
        $packageIds = $validated['package_ids'] ?? [];
        $services = Service::public()->with(['category', 'partVariants.part.brand', 'partVariants.attributeValues.attribute', 'partVariants.compatibleModels'])->whereIn('id', $serviceIds)->get()->keyBy('id');
        $packages = ServicePackage::public()->with(['category', 'items.service', 'items.part.brand', 'items.partVariant.attributeValues.attribute', 'items.partVariant.compatibleModels', 'compatibleModels'])->whereIn('id', $packageIds)->get()->keyBy('id');
        $packageVehiclePrices = PackageVehiclePrice::query()->whereIn('package_id', $packages->keys())->where('vehicle_model_id', $vehicleModel->id)->where('status', 'published')
            ->whereHas('package.compatibleModels', fn ($query) => $query->where('vehicle_models.id', $vehicleModel->id)->where('package_vehicle_model.compatibility_status', 'compatible'))
            ->where(fn ($query) => $query->whereNull('vehicle_variant_id')->orWhere('vehicle_variant_id', $vehicleVariant->id))
            ->where(fn ($query) => $query->whereNull('vehicle_generation_id')->orWhere('vehicle_generation_id', $vehicleVariant->vehicle_generation_id))
            ->where(fn ($query) => $query->whereNull('year_from')->orWhere('year_from', '<=', $vehicleYear))
            ->where(fn ($query) => $query->whereNull('year_to')->orWhere('year_to', '>=', $vehicleYear))
            ->get()->sortByDesc(fn (PackageVehiclePrice $price): int => (int) ($price->vehicle_variant_id !== null) * 4 + (int) ($price->vehicle_generation_id !== null) * 2 + (int) ($price->year_from !== null || $price->year_to !== null))
            ->unique('package_id')->keyBy('package_id');
        if ($services->count() !== count($serviceIds)) {
            throw ValidationException::withMessages(['service_ids' => 'One or more selected services are no longer available. Refresh the catalog and try again.']);
        }
        if ($packages->count() !== count($packageIds)) {
            throw ValidationException::withMessages(['package_ids' => 'One or more selected packages are no longer available. Refresh the catalog and try again.']);
        }
        foreach ($packages as $package) {
            $packageModelFitment = $package->compatibleModels->firstWhere('id', $vehicleModel->id);
            if ($packageModelFitment?->pivot->compatibility_status === 'incompatible') {
                throw ValidationException::withMessages(['package_ids' => $package->name.' is marked incompatible with this motorcycle.']);
            }
        }
        $fullDayServices = $services->filter(fn (Service $service): bool => $service->requires_full_day);
        $boreUpPackages = $packages->filter(fn (ServicePackage $package): bool => str_starts_with($package->slug, 'bore-up-'));
        if ($fullDayServices->isNotEmpty()) {
            if ($fullDayServices->count() !== 1 || $services->count() !== 1 || $boreUpPackages->count() !== 1 || $packages->count() !== 1) {
                throw ValidationException::withMessages(['service_ids' => 'The full-day break-in service can only be booked with one Bore-Up package.']);
            }
            if ($fullDayServices->first()->requires_operation_consent && ! $request->boolean('operation_consent')) {
                throw ValidationException::withMessages(['operation_consent' => 'Consent to MINEX operating the motorcycle for the documented break-in program is required.']);
            }
        }
        foreach ($services as $service) {
            if ($service->requires_bore_up_package && $boreUpPackages->isEmpty()) {
                throw ValidationException::withMessages(['package_ids' => 'This service is only available with an eligible Bore-Up package.']);
            }
        }
        foreach (array_keys($validated['package_options'] ?? []) as $packageOptionId) {
            if (! in_array((int) $packageOptionId, array_map('intval', $packageIds), true)) {
                throw ValidationException::withMessages(["package_options.{$packageOptionId}" => 'Choose optional items only for selected packages.']);
            }
        }
        foreach (array_keys($validated['service_part_variant_ids'] ?? []) as $servicePartServiceId) {
            if (! in_array((int) $servicePartServiceId, array_map('intval', $serviceIds), true)) {
                throw ValidationException::withMessages(["service_part_variant_ids.{$servicePartServiceId}" => 'Choose parts only for a selected service.']);
            }
        }

        $servicePartVariants = [];
        $partDurationByService = [];
        foreach ($services as $service) {
            $requestedIds = collect($validated['service_part_variant_ids'][$service->id] ?? [])->map(fn ($id) => (int) $id)->unique();
            $availableVariants = $service->partVariants->filter(fn (PartVariant $variant): bool => $variant->is_active && $variant->part?->is_active);
            $requiredIds = $availableVariants->filter(fn (PartVariant $variant): bool => (bool) $variant->pivot->is_required)->pluck('id')->map(fn ($id) => (int) $id);
            if ($requestedIds->diff($availableVariants->pluck('id')->map(fn ($id) => (int) $id))->isNotEmpty()) {
                throw ValidationException::withMessages(["service_part_variant_ids.{$service->id}" => 'A selected product is no longer associated with this active service. Refresh and try again.']);
            }
            $selectedIds = $requestedIds->merge($requiredIds)->unique();
            $servicePartVariants[$service->id] = $availableVariants->whereIn('id', $selectedIds)->values();
            $partDurationByService[$service->id] = (int) $servicePartVariants[$service->id]->sum(fn (PartVariant $variant): int => (int) ($variant->pivot->duration_minutes ?? 0));
        }

        if ($services->isEmpty() && $packages->isEmpty()) {
            throw ValidationException::withMessages(['service_ids' => 'Choose a workshop service or maintenance package. Parts are selected within workshop work only.']);
        }

        $preferredAt = Carbon::parse($validated['preferred_start_at']);
        $serviceLaborPrices = app(LaborPricingService::class)->forServices($services, $partDurationByService);
        $availabilityDuration = app(LaborPricingService::class)->durationForServices($services, $partDurationByService);
        $requiredCategories = $services->pluck('category_id')->map(fn ($id): int => (int) $id)->all();
        foreach ($packages as $package) {
            $optionalIds = collect($validated['package_options'][$package->id] ?? [])->map(fn ($id): int => (int) $id)->unique()->values();
            $optionalItems = $package->items->filter(fn ($item): bool => $optionalIds->contains((int) $item->id) && ! $item->is_required);
            if ($optionalItems->count() !== $optionalIds->count()) {
                throw ValidationException::withMessages(["package_options.{$package->id}" => 'An optional item no longer belongs to this package. Refresh and try again.']);
            }
            if ($package->category_id !== null) {
                $requiredCategories[] = (int) $package->category_id;
            }
            foreach ($package->items as $item) {
                $selected = $item->is_required || $optionalIds->contains((int) $item->id);
                if (! $selected) {
                    continue;
                }
                if ($item->service_id && ! Service::public()->whereKey($item->service_id)->exists()) {
                    throw ValidationException::withMessages(['package_ids' => "A service included in {$package->name} is no longer available."]);
                }
                if ($item->service) {
                    $availabilityDuration += (int) ($item->service->duration_minutes ?? 0);
                    $requiredCategories[] = (int) $item->service->category_id;
                }
            }
            $availabilityDuration += (int) ($package->duration_minutes ?? 0);
        }
        $availabilityDuration = min(1440, max(30, $availabilityDuration));
        $fullDayDuration = null;
        if ($fullDayServices->isNotEmpty()) {
            $fullDayAvailability = app(WorkshopAvailabilityService::class)->fullDaySlotMap($preferredAt->copy()->startOfDay(), array_values(array_unique($requiredCategories)));
            $expectedStart = $fullDayAvailability['slots'][0]['start_at'] ?? null;
            if ($fullDayAvailability['day_status'] !== 'open' || ! $expectedStart || ($fullDayAvailability['slots'][0]['status'] ?? 'full') === 'full' || $preferredAt->format('Y-m-d\\TH:i') !== $expectedStart) {
                throw ValidationException::withMessages(['preferred_start_at' => 'The break-in program requires the available full-day opening slot.']);
            }
            $fullDayDuration = $fullDayAvailability['duration_minutes'];
        } else {
            $availableSlots = app(WorkshopAvailabilityService::class)->availableSlots($preferredAt->copy()->startOfDay(), $availabilityDuration, array_values(array_unique($requiredCategories)));
            if (! in_array($preferredAt->format('Y-m-d\\TH:i'), $availableSlots, true)) {
                throw ValidationException::withMessages(['preferred_start_at' => 'That time is no longer available for the selected work. Choose a current time from the availability list.']);
            }
        }

        $customerPhone = $isMember ? ($user->phone ?: $validated['customer_phone']) : $validated['customer_phone'];
        $operationConsentAt = $fullDayServices->first()?->requires_operation_consent ? now() : null;
        $booking = DB::transaction(function () use ($user, $isMember, $validated, $customerPhone, $vehicleModel, $vehicleVariant, $vehicleYear, $customerVehicle, $services, $servicePartVariants, $partDurationByService, $serviceLaborPrices, $packages, $packageVehiclePrices, $fullDayServices, $fullDayDuration, $operationConsentAt): Booking {
            $booking = Booking::create([
                'booking_number' => 'MX-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                'reference' => Str::random(40),
                'submission_token' => $validated['submission_token'],
                'user_id' => $user?->id,
                'customer_vehicle_id' => $customerVehicle?->id,
                'customer_type' => $isMember ? 'member' : 'guest',
                'customer_name' => $isMember ? $user->name : $validated['customer_name'],
                'customer_email' => $isMember ? $user->email : $validated['customer_email'],
                'customer_phone' => $customerPhone,
                'vehicle_snapshot' => [
                    'vehicle_model_id' => $vehicleModel->id,
                    'category' => $vehicleModel->category->name,
                    'brand' => $vehicleModel->brand->name,
                    'model' => $vehicleModel->name,
                    'generation' => $vehicleVariant->generation->name,
                    'variant' => $vehicleVariant->name,
                    'variant_id' => $vehicleVariant->id,
                    'variant_verification_status' => $vehicleVariant->verification_status,
                    'technical_attributes' => $vehicleVariant->attributes->map(fn ($attribute) => ['name' => $attribute->name, 'value' => $attribute->value, 'unit' => $attribute->unit])->all(),
                    'year' => $vehicleYear,
                    'registration_number' => $customerVehicle?->registration_number,
                    'odometer_km' => $customerVehicle?->odometer_km,
                ],
                'status' => 'pending_approval',
                'payment_status' => 'not_due',
                'payment_percentage' => $isMember ? (int) $validated['payment_percentage'] : 100,
                'preferred_start_at' => $validated['preferred_start_at'],
                'operation_consent_at' => $operationConsentAt,
                'customer_notes' => $validated['customer_notes'] ?? null,
                'submitted_at' => now(),
            ]);

            $subtotal = 0;
            $discount = 0;
            $duration = app(LaborPricingService::class)->durationForServices($services, $partDurationByService);
            $hasUnpricedItems = false;
            $requiresFitmentConfirmation = $packages->contains(fn (ServicePackage $package): bool => $package->requires_fitment_confirmation);

            foreach ($services as $service) {
                $price = $serviceLaborPrices[$service->id] ?? $service->labor_price_rupiah;
                $lineTotal = $price ?? 0;
                $subtotal += $lineTotal;
                $hasUnpricedItems = $hasUnpricedItems || $price === null;
                $this->storeItem($booking, [
                    'service_id' => $service->id,
                    'item_type' => 'service',
                    'label_snapshot' => $service->name,
                    'description_snapshot' => $service->summary,
                    'labor_price_rupiah' => $price,
                    'unit_price_rupiah' => null,
                    'line_total_rupiah' => $lineTotal,
                    'duration_minutes' => $service->requires_full_day ? $fullDayDuration : $service->duration_minutes,
                    'catalog_snapshot' => [
                        'service' => [
                            'id' => $service->id,
                            'slug' => $service->slug,
                            'name' => $service->name,
                            'category_id' => $service->category_id,
                            'category' => $service->category->name,
                            'summary' => $service->summary,
                            'description' => $service->description,
                            'duration_minutes' => $service->duration_minutes,
                            'labor_category' => $service->labor_category,
                            'labor_pricing_mode' => $service->labor_pricing_mode,
                            'labor_group' => $service->labor_group,
                            'shared_operation_minutes' => $service->shared_operation_minutes,
                            'selected_part_duration_minutes' => $partDurationByService[$service->id] ?? 0,
                            'requires_full_day' => $service->requires_full_day,
                            'requires_operation_consent' => $service->requires_operation_consent,
                            'requires_bore_up_package' => $service->requires_bore_up_package,
                        ],
                        'labor_price_rupiah' => $price,
                        'price_to_be_confirmed' => $price === null,
                    ],
                ]);

                foreach ($servicePartVariants[$service->id] ?? [] as $variant) {
                    $fitment = $this->fitmentForVariant($variant, $vehicleModel, $vehicleVariant, $vehicleYear);
                    if ($fitment?->pivot->compatibility_status === 'incompatible') {
                        throw ValidationException::withMessages(['service_part_variant_ids' => $variant->part->name.' is marked incompatible with this motorcycle.']);
                    }
                    $fitmentIsVerified = $fitment?->pivot->compatibility_status === 'compatible';
                    $requiresFitmentConfirmation = $requiresFitmentConfirmation || ! $fitmentIsVerified;
                    $unitPrice = $variant->selling_price_rupiah ?? $variant->part->selling_price_rupiah;
                    $quantity = (string) $variant->pivot->quantity;
                    $partLineTotal = $unitPrice === null ? 0 : $this->quantityTotal($quantity, $unitPrice);
                    $subtotal += $partLineTotal;
                    $hasUnpricedItems = $hasUnpricedItems || $unitPrice === null;
                    $componentLabel = $variant->pivot->component_label ?: $variant->part->name;
                    $this->storeItem($booking, [
                        'service_id' => $service->id,
                        'part_id' => $variant->part_id,
                        'part_variant_id' => $variant->id,
                        'item_type' => 'part',
                        'label_snapshot' => $service->name.' · '.$componentLabel.' — '.$variant->name,
                        'description_snapshot' => $variant->part->description,
                        'quantity' => $quantity,
                        'unit_price_rupiah' => $unitPrice,
                        'line_total_rupiah' => $partLineTotal,
                        'duration_minutes' => $variant->pivot->duration_minutes,
                        'catalog_snapshot' => [
                            'associated_service' => ['id' => $service->id, 'name' => $service->name],
                            'component_label' => $componentLabel,
                            'part' => ['id' => $variant->part->id, 'name' => $variant->part->name, 'brand' => $variant->part->brand?->name, 'category' => $variant->part->category?->name, 'type' => $variant->part->part_type, 'sku' => $variant->part->sku],
                            'variant' => ['id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'variant_code' => $variant->variant_code],
                            'attributes' => $variant->attributeValues->map(fn ($value): array => ['name' => $value->attribute->name, 'value' => $value->value, 'unit' => $value->attribute->unit])->all(),
                            'quantity' => $quantity,
                            'unit_price_rupiah' => $unitPrice,
                            'line_total_rupiah' => $partLineTotal,
                            'fitment' => $fitment ? ['status' => $fitment->pivot->compatibility_status, 'source_name' => $fitment->pivot->source_name, 'source_reference' => $fitment->pivot->source_reference, 'source_type' => $fitment->pivot->source_type, 'part_number' => $fitment->pivot->part_number] : ['status' => 'unverified'],
                            'price_to_be_confirmed' => $unitPrice === null,
                            'requires_fitment_confirmation' => ! $fitmentIsVerified,
                        ],
                    ]);
                }
            }

            foreach ($packages as $package) {
                $vehiclePrice = $packageVehiclePrices->get($package->id);
                if ($package->compatibleModels->isNotEmpty()) {
                    $packageModelFitment = $package->compatibleModels->firstWhere('id', $vehicleModel->id);
                    $requiresFitmentConfirmation = $requiresFitmentConfirmation || $packageModelFitment?->pivot->compatibility_status !== 'compatible';
                }
                $optionalIds = collect($validated['package_options'][$package->id] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
                $optionalItems = $package->items->filter(fn ($item) => $optionalIds->contains($item->id) && ! $item->is_required);
                if ($optionalItems->count() !== $optionalIds->count()) {
                    throw ValidationException::withMessages(["package_options.{$package->id}" => 'An optional item no longer belongs to this package. Refresh and try again.']);
                }

                $optionalTotal = 0;
                $packageItemsSnapshot = [];
                foreach ($package->items as $item) {
                    $selected = $item->is_required || $optionalIds->contains($item->id);
                    $componentPrice = $this->packageItemPrice($item);
                    if ($selected && $item->service_id && ! Service::public()->whereKey($item->service_id)->exists()) {
                        throw ValidationException::withMessages(['package_ids' => "A service included in {$package->name} is no longer available."]);
                    }
                    if ($selected && $item->part_id && ! Part::query()->whereKey($item->part_id)->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(['package_ids' => "A part included in {$package->name} is no longer available."]);
                    }
                    if ($selected && $item->partVariant) {
                        if (! $item->partVariant->is_active || ! $item->part?->is_active) {
                            throw ValidationException::withMessages(['package_ids' => "A variant included in {$package->name} is no longer available."]);
                        }
                        $fitment = $this->fitmentForVariant($item->partVariant, $vehicleModel, $vehicleVariant, $vehicleYear);
                        if ($fitment?->pivot->compatibility_status === 'incompatible') {
                            throw ValidationException::withMessages(['package_ids' => 'A package variant is marked incompatible with this motorcycle.']);
                        }
                        $requiresFitmentConfirmation = $requiresFitmentConfirmation || $fitment?->pivot->compatibility_status !== 'compatible';
                    }
                    if ($selected && ! $item->is_required) {
                        if ($componentPrice === null) {
                            $hasUnpricedItems = true;
                        } else {
                            $optionalTotal += $this->quantityTotal((string) $item->quantity, $componentPrice);
                        }
                    }
                    if ($selected) {
                        $duration += (int) ($item->service?->duration_minutes ?? 0);
                    }
                    $packageItemsSnapshot[] = [
                        'label' => $item->label,
                        'description' => $item->description,
                        'component_type' => $item->component_type,
                        'quantity' => (string) $item->quantity,
                        'is_required' => $item->is_required,
                        'selected' => $selected,
                        'extra_price_rupiah' => $componentPrice,
                        'component_unit_price_rupiah' => $componentPrice,
                        'component_line_price_rupiah' => $selected && $componentPrice !== null ? $this->quantityTotal((string) $item->quantity, $componentPrice) : null,
                        'package_item_id' => $item->id,
                        'service' => $item->service ? ['id' => $item->service->id, 'name' => $item->service->name, 'category_id' => $item->service->category_id, 'labor_price_rupiah' => $item->service->labor_price_rupiah] : null,
                        'part' => $item->part ? ['id' => $item->part->id, 'name' => $item->part->name, 'brand' => $item->part->brand?->name, 'sku' => $item->part->sku] : null,
                        'variant' => $item->partVariant ? ['id' => $item->partVariant->id, 'name' => $item->partVariant->name, 'sku' => $item->partVariant->sku, 'selling_price_rupiah' => $item->partVariant->selling_price_rupiah ?? $item->part?->selling_price_rupiah] : null,
                    ];
                }

                $isBoreUpPackage = str_starts_with($package->slug, 'bore-up-');
                $basePrice = $vehiclePrice
                    ? $vehiclePrice->parts_selling_rupiah + $vehiclePrice->consumables_rupiah + $vehiclePrice->additional_costs_rupiah
                    : ($isBoreUpPackage ? null : $package->base_price_rupiah);
                $laborPrice = $vehiclePrice?->fixed_labor_rupiah ?? ($isBoreUpPackage ? null : $package->labor_price_rupiah);
                $packageDiscount = $vehiclePrice?->discount_rupiah ?? $package->discount_rupiah;
                $gross = ($basePrice ?? 0) + ($laborPrice ?? 0) + $optionalTotal;
                $appliedDiscount = min($packageDiscount ?? 0, $gross);
                $subtotal += $gross;
                $discount += $appliedDiscount;
                $hasUnpricedItems = $hasUnpricedItems || $basePrice === null || $laborPrice === null;
                $duration += $package->duration_minutes ?? 0;
                $this->storeItem($booking, [
                    'package_id' => $package->id,
                    'item_type' => 'package',
                    'label_snapshot' => $package->name,
                    'description_snapshot' => $package->summary,
                    'labor_price_rupiah' => $laborPrice,
                    'unit_price_rupiah' => $basePrice,
                    'discount_rupiah' => $appliedDiscount,
                    'line_total_rupiah' => max(0, $gross - $appliedDiscount),
                    'duration_minutes' => $package->duration_minutes,
                    'catalog_snapshot' => [
                        'package' => ['id' => $package->id, 'slug' => $package->slug, 'name' => $package->name, 'category_id' => $package->category_id, 'category' => $package->category?->name, 'summary' => $package->summary, 'description' => $package->description, 'requires_fitment_confirmation' => $package->requires_fitment_confirmation, 'motorcycle_compatibility' => $package->compatibleModels->map(fn ($model): array => ['vehicle_model_id' => $model->id, 'vehicle_model' => $model->name, 'status' => $model->pivot->compatibility_status, 'source_reference' => $model->pivot->source_reference])->all()],
                        'base_price_rupiah' => $basePrice,
                        'labor_price_rupiah' => $laborPrice,
                        'discount_rupiah' => $appliedDiscount,
                        'optional_total_rupiah' => $optionalTotal,
                        'vehicle_specific_price' => $vehiclePrice ? [
                            'parts_selling_rupiah' => $vehiclePrice->parts_selling_rupiah,
                            'fixed_labor_rupiah' => $vehiclePrice->fixed_labor_rupiah,
                            'consumables_rupiah' => $vehiclePrice->consumables_rupiah,
                            'additional_costs_rupiah' => $vehiclePrice->additional_costs_rupiah,
                            'discount_rupiah' => $vehiclePrice->discount_rupiah,
                            'source' => $vehiclePrice->price_source,
                            'last_updated_at' => $vehiclePrice->updated_at?->toDateString(),
                        ] : null,
                        'price_to_be_confirmed' => $basePrice === null || $laborPrice === null,
                        'items' => $packageItemsSnapshot,
                    ],
                ]);
                if ($package->requires_fitment_confirmation) {
                    $requiresFitmentConfirmation = true;
                }
            }

            if ($fullDayDuration !== null) {
                $duration = $fullDayDuration;
            }

            if ($fullDayServices->isNotEmpty()) {
                $booking->engineBreakInProgram()->create([
                    'status' => 'awaiting_budget',
                    'customer_consent_at' => $operationConsentAt,
                    'customer_consent_version' => 'minex-engine-break-in-v1',
                    'created_by' => $user?->id,
                ]);
            }
            if ($packages->contains(fn (ServicePackage $package): bool => str_starts_with($package->slug, 'bore-up-'))) {
                $booking->engineBuildConfiguration()->create(['compatibility_status' => 'unverified', 'updated_by' => $user?->id]);
            }

            $booking->update([
                'subtotal_rupiah' => $subtotal,
                'discount_rupiah' => $discount,
                'estimated_duration_minutes' => $duration ?: null,
                'has_unpriced_items' => $hasUnpricedItems,
                'requires_fitment_confirmation' => $requiresFitmentConfirmation,
            ]);
            BookingStatusHistory::create([
                'booking_id' => $booking->id,
                'from_status' => null,
                'to_status' => 'pending_approval',
                'changed_by' => $user?->id,
                'note' => 'Booking request submitted.',
            ]);

            return $booking;
        });

        if ($isMember && blank($user->phone)) {
            $user->update(['phone' => $customerPhone]);
        }
        $request->session()->forget('booking_submission_token');
        $notifications->sendToRoles(['owner', 'admin'], 'booking_submitted', 'New booking request', $booking->booking_number.' was submitted and is waiting for review.', route('admin.bookings.show', $booking->id), ['booking_id' => $booking->id]);
        $audit->record($request, 'booking.submitted', $booking, ['customer_type' => $booking->customer_type, 'item_count' => $booking->items()->count()]);

        return redirect()->route('bookings.received', $booking->reference)->with('status', 'Your booking request was received. MINEX will confirm the scope and price before approval.');
    }

    public function received(Request $request, string $reference): View
    {
        $booking = Booking::query()->with(['items', 'statusHistory', 'payments', 'engineBreakInProgram.fuelExpenses', 'engineBreakInProgram.fuelPayments', 'engineBuildConfiguration', 'workOrder.inspections.findings.media', 'workOrder.recommendations.mechanicUser', 'workOrder.recommendations.workOrderItems.stockReservation', 'workOrder.recommendations.decisionHistory.actor'])->where('reference', $reference)->firstOrFail();
        if ($booking->user_id && $booking->user_id !== $request->user()?->id) {
            abort(404);
        }

        return view('bookings.received', compact('booking'));
    }

    private function packageItemPrice(Model $item): ?int
    {
        if ($item->extra_price_rupiah !== null) {
            return (int) $item->extra_price_rupiah;
        }

        if ($item->service) {
            return app(LaborPricingService::class)->price($item->service);
        }

        return $item->partVariant?->selling_price_rupiah ?? $item->part?->selling_price_rupiah;
    }

    private function fitmentForVariant(PartVariant $partVariant, VehicleModel $vehicleModel, VehicleVariant $vehicleVariant, int $vehicleYear): mixed
    {
        return $partVariant->compatibleModels->first(function ($model) use ($vehicleModel, $vehicleVariant, $vehicleYear): bool {
            if ((int) $model->id !== (int) $vehicleModel->id) {
                return false;
            }
            if ($model->pivot->vehicle_variant_id && (int) $model->pivot->vehicle_variant_id !== (int) $vehicleVariant->id) {
                return false;
            }

            return (! $model->pivot->compatible_year_from || $vehicleYear >= $model->pivot->compatible_year_from)
                && (! $model->pivot->compatible_year_to || $vehicleYear <= $model->pivot->compatible_year_to);
        });
    }

    private function quantityTotal(string $quantity, int $unitPrice): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '0');
        $quantityHundredths = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return intdiv(($quantityHundredths * $unitPrice) + 50, 100);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function storeItem(Booking $booking, array $attributes): void
    {
        BookingItem::create($attributes + [
            'booking_id' => $booking->id,
            'quantity' => 1,
            'discount_rupiah' => 0,
            'line_total_rupiah' => 0,
        ]);
    }
}
