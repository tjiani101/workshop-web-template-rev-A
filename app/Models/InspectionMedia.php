<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionMedia extends Model
{
    public $timestamps = false;

    protected $fillable = ['inspection_finding_id', 'storage_disk', 'storage_path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by', 'created_at'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(InspectionFinding::class, 'inspection_finding_id');
    }
}
