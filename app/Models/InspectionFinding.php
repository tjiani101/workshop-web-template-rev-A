<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionFinding extends Model
{
    public $timestamps = false;

    protected $fillable = ['inspection_id', 'category', 'severity', 'title', 'description', 'technical_finding', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(InspectionMedia::class);
    }
}
