<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    protected $fillable = [
        'category_id', 'name', 'slug', 'summary', 'description', 'included_items', 'excluded_items',
        'labor_price_rupiah', 'duration_minutes', 'shared_operation_minutes', 'labor_category', 'labor_pricing_mode', 'labor_group', 'requires_full_day', 'requires_operation_consent', 'requires_bore_up_package', 'image_path', 'sort_order', 'is_active', 'is_public',
        'is_featured', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'included_items' => 'array',
            'excluded_items' => 'array',
            'labor_price_rupiah' => 'integer',
            'duration_minutes' => 'integer',
            'shared_operation_minutes' => 'integer',
            'requires_full_day' => 'boolean',
            'requires_operation_consent' => 'boolean',
            'requires_bore_up_package' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_demo' => 'boolean',
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

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ServiceOptionGroup::class)->orderBy('sort_order');
    }

    public function compatibleModels(): BelongsToMany
    {
        return $this->belongsToMany(VehicleModel::class)->withPivot(['compatibility_status', 'source_reference', 'verified_by', 'verified_at'])->withTimestamps();
    }

    public function partVariants(): BelongsToMany
    {
        return $this->belongsToMany(PartVariant::class, 'service_part_variants')->withPivot(['component_label', 'quantity', 'duration_minutes', 'is_required'])->withTimestamps();
    }

    public function getImageUrlAttribute(): ?string
    {
        $categorySlug = $this->category?->slug;
        $illustration = match ($categorySlug) {
            'cvt-transmission' => 'cvt-deep-cleaning',
            'engine-service' => 'bore-up-standard',
            'electrical-diagnostics' => 'rewiring',
            'brake-system' => 'full-maintenance',
            'suspension' => 'semi-maintenance',
            'routine-maintenance' => 'full-maintenance',
            default => null,
        };
        if ($this->is_demo && $illustration && is_file(public_path('images/packages/'.$illustration.'.svg'))) {
            return asset('images/packages/'.$illustration.'.svg');
        }

        if (! $this->image_path) {
            return null;
        }

        return str_starts_with($this->image_path, 'images/')
            ? asset($this->image_path)
            : Storage::disk('public')->url($this->image_path);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_public', true)
            ->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }
}
