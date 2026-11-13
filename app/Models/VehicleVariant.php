<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleVariant extends Model
{
    protected $fillable = ['vehicle_model_id', 'vehicle_generation_id', 'name', 'is_active', 'verification_status', 'data_source', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(VehicleGeneration::class, 'vehicle_generation_id');
    }

    public function years(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModelYear::class, 'vehicle_variant_year')->withTimestamps();
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(VehicleVariantAttribute::class, 'vehicle_variant_id')->orderBy('name');
    }
}
