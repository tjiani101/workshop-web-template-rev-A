<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VehicleModelYear extends Model
{
    protected $fillable = ['vehicle_model_id', 'vehicle_generation_id', 'model_year', 'is_active', 'verification_status', 'data_source'];

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

    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(VehicleVariant::class, 'vehicle_variant_year')->withTimestamps();
    }
}
