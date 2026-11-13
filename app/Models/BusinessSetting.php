<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $fillable = ['setting_key', 'setting_value'];

    protected function casts(): array
    {
        return ['setting_value' => 'array'];
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        return static::query()->where('setting_key', $key)->first()?->setting_value ?? $default;
    }

    /** @param array<string, mixed> $value */
    public static function setValue(string $key, array $value): void
    {
        static::query()->updateOrCreate(['setting_key' => $key], ['setting_value' => $value]);
    }
}
