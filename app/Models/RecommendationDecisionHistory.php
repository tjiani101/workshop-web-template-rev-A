<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecommendationDecisionHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_recommendation_id', 'from_decision', 'to_decision', 'acted_by', 'actor_type', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(WorkRecommendation::class, 'work_recommendation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
