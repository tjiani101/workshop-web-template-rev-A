<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopClosure extends Model
{
    protected $fillable = ['closure_date', 'is_closed', 'opens_at', 'closes_at', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['closure_date' => 'date', 'is_closed' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
