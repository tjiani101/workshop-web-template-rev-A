<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Part extends Model
{
    protected $fillable = [
        'category_id', 'brand_id', 'name', 'slug', 'sku', 'part_type', 'description',
        'purchase_cost_rupiah', 'selling_price_rupiah', 'image_path', 'is_active', 'is_public', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'purchase_cost_rupiah' => 'integer', 'selling_price_rupiah' => 'integer',
            'is_active' => 'boolean', 'is_public' => 'boolean', 'is_demo' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(PartBrand::class, 'brand_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PartVariant::class)->orderBy('id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true))
            ->where(fn (Builder $parts) => $parts->whereNull('brand_id')->orWhereHas('brand', fn (Builder $brand) => $brand->where('is_active', true)));
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return str_starts_with($this->image_path, 'images/')
            ? asset($this->image_path)
            : Storage::disk('public')->url($this->image_path);
    }
}
