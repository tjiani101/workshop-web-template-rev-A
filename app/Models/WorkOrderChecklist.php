<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderChecklist extends Model
{
    protected $fillable = ['work_order_id', 'label', 'is_complete', 'completed_by', 'completed_at', 'sort_order'];

    protected function casts(): array
    {
        return ['is_complete' => 'boolean', 'completed_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
