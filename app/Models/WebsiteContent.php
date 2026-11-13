<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteContent extends Model
{
    protected $table = 'website_content';

    protected $fillable = ['content_key', 'content', 'is_published'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_published' => 'boolean'];
    }

    public static function publishedContent(): array
    {
        return static::query()->where('is_published', true)->pluck('content', 'content_key')->all();
    }
}
