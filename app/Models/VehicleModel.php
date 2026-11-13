<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleModel extends Model
{
    protected $table = 'vehicle_models';

    protected $fillable = ['brand_id', 'category_id', 'name', 'slug', 'year_from', 'year_to', 'is_active', 'is_demo', 'verification_status', 'data_source', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class, 'brand_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(CustomerVehicle::class, 'model_id');
    }

    public function generations(): HasMany
    {
        return $this->hasMany(VehicleGeneration::class, 'vehicle_model_id')->orderBy('id');
    }

    public function modelYears(): HasMany
    {
        return $this->hasMany(VehicleModelYear::class, 'vehicle_model_id')->orderByDesc('model_year');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(VehicleVariant::class, 'vehicle_model_id')->orderBy('id');
    }

    public function compatibleParts(): BelongsToMany
    {
        return $this->belongsToMany(PartVariant::class, 'part_variant_vehicle_model')->withPivot(['vehicle_variant_id', 'compatible_year_from', 'compatible_year_to', 'source_name', 'source_type', 'part_number', 'compatibility_status', 'source_reference', 'verified_by', 'verified_at', 'notes'])->withTimestamps();
    }
}
