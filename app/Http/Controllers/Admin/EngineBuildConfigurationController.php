<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\EngineBuildConfiguration;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EngineBuildConfigurationController extends Controller
{
    public function update(Request $request, int $booking, AuditLogger $audit): RedirectResponse
    {
        $record = Booking::query()->with('items')->findOrFail($booking);
        abort_unless($record->isBoreUpBooking(), 404);
        $validated = $request->validate([
            'original_bore_mm' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'selected_bore_mm' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'stroke_mm' => ['nullable', 'numeric', 'gt:0', 'max:200'],
            'cylinder_count' => ['nullable', 'integer', 'min:1', 'max:8'],
            'selected_piston_variant_id' => ['nullable', 'integer', 'exists:part_variants,id'],
            'selected_block_variant_id' => ['nullable', 'integer', 'exists:part_variants,id'],
            'injector_variant_id' => ['nullable', 'integer', 'exists:part_variants,id'],
            'injector_information' => ['nullable', 'string', 'max:1500'],
            'supporting_components' => ['nullable', 'string', 'max:4000'],
            'compatibility_status' => ['required', Rule::in(['unverified', 'compatible', 'needs_modification', 'incompatible'])],
            'compatibility_verification_note' => ['nullable', 'string', 'max:3000', Rule::requiredIf($request->input('compatibility_status') !== 'unverified')],
        ]);

        $allowedVariantIds = $record->items->flatMap(fn ($item): array => collect(data_get($item->catalog_snapshot, 'items', []))
            ->filter(fn (array $itemSnapshot): bool => (bool) ($itemSnapshot['selected'] ?? false))
            ->pluck('variant.id')->filter()->map(fn ($id): int => (int) $id)->all())->unique()->values();
        foreach (['selected_piston_variant_id', 'selected_block_variant_id', 'injector_variant_id'] as $field) {
            if (! empty($validated[$field]) && ! $allowedVariantIds->contains((int) $validated[$field])) {
                throw ValidationException::withMessages([$field => 'Choose a variant that was included in this booking snapshot. Update the package and create a new quote to add other parts.']);
            }
        }

        if ($validated['compatibility_status'] === 'compatible') {
            foreach (['original_bore_mm', 'selected_bore_mm', 'stroke_mm', 'cylinder_count', 'selected_piston_variant_id', 'selected_block_variant_id'] as $field) {
                if (blank($validated[$field] ?? null)) {
                    throw ValidationException::withMessages([$field => 'A compatible Bore-Up configuration needs verified engine dimensions and selected piston and block variants.']);
                }
            }
            if (blank($validated['injector_variant_id'] ?? null) && blank($validated['injector_information'] ?? null)) {
                throw ValidationException::withMessages(['injector_information' => 'Record a compatible injector variant or its verified specification before marking this build compatible.']);
            }
        }

        $before = $record->engineBuildConfiguration()->first()?->toArray();
        $configuration = EngineBuildConfiguration::query()->updateOrCreate(
            ['booking_id' => $record->id],
            [
                'original_bore_mm' => $validated['original_bore_mm'] ?? null,
                'selected_bore_mm' => $validated['selected_bore_mm'] ?? null,
                'stroke_mm' => $validated['stroke_mm'] ?? null,
                'cylinder_count' => $validated['cylinder_count'] ?? null,
                'calculated_displacement_cc' => filled($validated['selected_bore_mm'] ?? null) && filled($validated['stroke_mm'] ?? null) && filled($validated['cylinder_count'] ?? null)
                    ? round(M_PI * ((float) $validated['selected_bore_mm'] ** 2) * (float) $validated['stroke_mm'] * (int) $validated['cylinder_count'] / 4 / 1000, 2)
                    : null,
                'selected_piston_variant_id' => $validated['selected_piston_variant_id'] ?? null,
                'selected_block_variant_id' => $validated['selected_block_variant_id'] ?? null,
                'injector_variant_id' => $validated['injector_variant_id'] ?? null,
                'injector_information' => $validated['injector_information'] ?? null,
                'supporting_components' => collect(preg_split('/\r\n|\r|\n/', $validated['supporting_components'] ?? '') ?: [])->map(fn (string $line): string => trim($line))->filter()->values()->all(),
                'compatibility_status' => $validated['compatibility_status'],
                'compatibility_verification_note' => $validated['compatibility_verification_note'] ?? null,
                'compatibility_verified_by' => $validated['compatibility_status'] === 'unverified' ? null : $request->user()->id,
                'compatibility_verified_at' => $validated['compatibility_status'] === 'unverified' ? null : now(),
                'updated_by' => $request->user()->id,
            ]
        );
        $audit->record($request, 'engine_build.configuration_updated', $configuration, ['booking_id' => $record->id, 'before' => $before, 'after' => $configuration->toArray()]);

        return back()->with('status', 'Engine build configuration saved and audited.');
    }
}
