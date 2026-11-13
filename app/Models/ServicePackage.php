<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ServicePackage extends Model
{
    protected $table = 'packages';

    protected $fillable = [
        'category_id', 'name', 'slug', 'summary', 'description', 'base_price_rupiah',
        'labor_price_rupiah', 'discount_rupiah', 'duration_minutes', 'image_path', 'sort_order',
        'is_active', 'is_public', 'is_featured', 'is_demo', 'requires_fitment_confirmation',
    ];

    protected function casts(): array
    {
        return [
            'base_price_rupiah' => 'integer',
            'labor_price_rupiah' => 'integer',
            'discount_rupiah' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_demo' => 'boolean',
            'requires_fitment_confirmation' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PackageItem::class, 'package_id')->orderBy('sort_order');
    }

    public function vehiclePrices(): HasMany
    {
        return $this->hasMany(PackageVehiclePrice::class, 'package_id')->with(['vehicleModel.brand', 'generation', 'variant']);
    }

    public function compatibleModels(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModel::class, 'package_vehicle_model', 'package_id', 'vehicle_model_id')->withPivot(['compatibility_status', 'source_reference', 'verified_by', 'verified_at'])->withTimestamps();
    }

    public function getImageUrlAttribute(): ?string
    {
        $illustration = public_path('images/packages/'.$this->slug.'.svg');
        if (($this->is_demo || ! $this->image_path) && is_file($illustration)) {
            return asset('images/packages/'.$this->slug.'.svg');
        }

        if (! $this->image_path) {
            $fallback = str_starts_with($this->slug, 'bore-up-') ? 'bore-up-standard' : (str_contains($this->slug, 'oil-cooler') ? 'oil-cooler-6-row' : 'full-maintenance');

            return asset('images/packages/'.$fallback.'.svg');
        }

        return str_starts_with($this->image_path, 'images/')
            ? asset($this->image_path)
            : Storage::disk('public')->url($this->image_path);
    }

    public function getStartingPriceRupiahAttribute(): ?int
    {
        if ($this->base_price_rupiah === null || $this->labor_price_rupiah === null) {
            return null;
        }

        $gross = $this->base_price_rupiah + $this->labor_price_rupiah;

        return max(0, $gross - min($this->discount_rupiah ?? 0, $gross));
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)
            ->where(fn (Builder $packages) => $packages->whereNull('category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)));
    }
}
