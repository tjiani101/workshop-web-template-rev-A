<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageVehiclePrice extends Model
{
    protected $fillable = [
        'package_id', 'vehicle_model_id', 'vehicle_generation_id', 'vehicle_variant_id', 'year_from', 'year_to',
        'parts_cost_rupiah', 'parts_selling_rupiah', 'fixed_labor_rupiah', 'consumables_rupiah', 'additional_costs_rupiah', 'discount_rupiah',
        'price_source', 'status', 'updated_by', 'approved_by', 'approved_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'parts_selling_rupiah' => 'integer',
            'parts_cost_rupiah' => 'integer',
            'fixed_labor_rupiah' => 'integer',
            'consumables_rupiah' => 'integer',
            'additional_costs_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'year_from' => 'integer',
            'year_to' => 'integer',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'vehicle_generation_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(VehicleVariant::class, 'vehicle_variant_id');
    }

    public function totalRupiah(): int
    {
        $gross = $this->parts_selling_rupiah + $this->fixed_labor_rupiah + $this->consumables_rupiah + $this->additional_costs_rupiah;

        return max(0, $gross - min($gross, $this->discount_rupiah));
    }
}
