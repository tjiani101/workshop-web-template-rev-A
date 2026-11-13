<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PartVariant extends Model
{
    protected $fillable = ['part_id', 'name', 'sku', 'variant_code', 'purchase_cost_rupiah', 'selling_price_rupiah', 'is_active', 'is_demo'];

    protected function casts(): array
    {
        return ['purchase_cost_rupiah' => 'integer', 'selling_price_rupiah' => 'integer', 'is_active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttributeValue::class, 'part_variant_attribute', 'part_variant_id', 'attribute_value_id');
    }

    public function compatibleModels(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModel::class, 'part_variant_vehicle_model')->withPivot(['vehicle_variant_id', 'compatible_year_from', 'compatible_year_to', 'source_name', 'source_type', 'part_number', 'compatibility_status', 'source_reference', 'verified_by', 'verified_at', 'notes'])->withTimestamps();
    }

    public function stockLevel(): HasOne
    {
        return $this->hasOne(StockLevel::class);
    }
}
