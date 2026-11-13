<?php

namespace App\Models;

use Database\Factories\EngineBuildConfigurationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineBuildConfiguration extends Model
{
    /** @use HasFactory<EngineBuildConfigurationFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'original_bore_mm', 'selected_bore_mm', 'stroke_mm', 'cylinder_count',
        'calculated_displacement_cc', 'selected_piston_variant_id', 'selected_block_variant_id',
        'injector_variant_id', 'injector_information', 'supporting_components', 'compatibility_status',
        'compatibility_verification_note', 'compatibility_verified_by', 'compatibility_verified_at', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'original_bore_mm' => 'decimal:2',
            'selected_bore_mm' => 'decimal:2',
            'stroke_mm' => 'decimal:2',
            'cylinder_count' => 'integer',
            'calculated_displacement_cc' => 'decimal:2',
            'supporting_components' => 'array',
            'compatibility_verified_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function pistonVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class, 'selected_piston_variant_id');
    }

    public function blockVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class, 'selected_block_variant_id');
    }

    public function injectorVariant(): BelongsTo
    {
        return $this->belongsTo(PartVariant::class, 'injector_variant_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compatibility_verified_by');
    }
}
