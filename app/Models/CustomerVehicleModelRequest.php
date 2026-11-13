<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVehicleModelRequest extends Model
{
    protected $fillable = ['user_id', 'manufacturer', 'model_name', 'model_year', 'variant_details', 'notes', 'status', 'reviewed_by', 'admin_note'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
