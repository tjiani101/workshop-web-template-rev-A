<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleGeneration extends Model
{
    protected $fillable = ['vehicle_model_id', 'name', 'year_from', 'year_to', 'is_active', 'verification_status', 'data_source', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'vehicle_model_id');
    }

    public function years(): HasMany
    {
        return $this->hasMany(VehicleModelYear::class, 'vehicle_generation_id')->orderByDesc('model_year');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(VehicleVariant::class, 'vehicle_generation_id')->orderBy('id');
    }
}
