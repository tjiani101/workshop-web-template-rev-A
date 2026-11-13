<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MechanicProfile extends Model
{
    protected $fillable = ['user_id', 'daily_capacity_minutes', 'is_active', 'is_demo'];

    protected function casts(): array
    {
        return ['daily_capacity_minutes' => 'integer', 'is_active' => 'boolean', 'is_demo' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'mechanic_specializations', 'mechanic_profile_id', 'service_category_id')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MechanicAssignment::class);
    }

    public function leaveDays(): HasMany
    {
        return $this->hasMany(MechanicLeaveDay::class);
    }
}
