<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_setting:{$key}", function () use ($key, $default) {
            $setting = static::query()->where('key', $key)->first();

            if (! $setting) {
                return $default;
            }

            return match ($setting->type) {
                'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                'integer' => (int) $setting->value,
                'json' => json_decode((string) $setting->value, true),
                default => $setting->value,
            };
        });
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget("system_setting:{$setting->key}"));
        static::deleted(fn (self $setting) => Cache::forget("system_setting:{$setting->key}"));
    }
}
