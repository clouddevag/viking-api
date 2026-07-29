<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime configuration editable from the admin panel. Reads go through a
 * single cached map rather than one query per key.
 */
class Setting extends Model
{
    public const CACHE_KEY = 'viking:settings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * All settings as a flat key => value map.
     *
     * @return array<string, mixed>
     */
    public static function map(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->pluck('value', 'key')
                ->map(fn ($value) => is_array($value) && array_key_exists('_', $value) ? $value['_'] : $value)
                ->all()
        );
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::map()[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general', bool $isPublic = false): self
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => ['_' => $value], 'group' => $group, 'is_public' => $isPublic, 'type' => gettype($value)]
        );

        static::flushCache();

        return $setting;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
