<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageItem extends Model
{
    protected $fillable = [
        'package_id', 'service_id', 'part_id', 'part_variant_id', 'component_type', 'label', 'description', 'quantity',
        'extra_price_rupiah', 'is_required', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'extra_price_rupiah' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function partVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class);
    }
}
