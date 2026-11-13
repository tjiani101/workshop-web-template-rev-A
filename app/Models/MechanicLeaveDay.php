<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MechanicLeaveDay extends Model
{
    protected $fillable = ['mechanic_profile_id', 'leave_date', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['leave_date' => 'date'];
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(MechanicProfile::class, 'mechanic_profile_id');
    }
}
