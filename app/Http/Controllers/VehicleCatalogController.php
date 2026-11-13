<?php

namespace App\Http\Controllers;

use App\Models\CustomerVehicleModelRequest;
use App\Models\VehicleBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VehicleCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $brands = VehicleBrand::query()->where('is_active', true)->whereIn('slug', ['honda', 'yamaha', 'suzuki', 'vespa'])
            ->with(['models' => fn ($models) => $models->where('is_active', true)->with([
                'category',
                'generations' => fn ($generations) => $generations->where('is_active', true)->with([
                    'years' => fn ($years) => $years->where('is_active', true)->with(['variants' => fn ($variants) => $variants->where('is_active', true)->with('attributes')]),
                ]),
            ])])->orderBy('name')->get();

        return response()->json($brands);
    }

    public function storeRequest(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'manufacturer' => ['required', 'string', Rule::in(['Honda', 'Yamaha', 'Suzuki', 'Vespa'])],
            'model_name' => ['required', 'string', 'max:160'],
            'model_year' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'variant_details' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1500'],
        ]);
        $record = CustomerVehicleModelRequest::create($validated + ['user_id' => $request->user()?->id]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Vehicle catalog request sent for workshop review.', 'id' => $record->id], 201);
        }

        return back()->with('status', 'Vehicle model request sent. MINEX will review the source information before adding verified variants.');
    }
}
