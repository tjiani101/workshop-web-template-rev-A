<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOption extends Model
{
    protected $fillable = ['group_id', 'name', 'description', 'price_delta_rupiah', 'duration_delta_minutes', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['price_delta_rupiah' => 'integer', 'duration_delta_minutes' => 'integer', 'is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServiceOptionGroup::class, 'group_id');
    }
}
