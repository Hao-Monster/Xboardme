<?php

namespace App\Models;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SubscribeTemplate extends Model
{
    protected $table = 'v2_subscribe_templates';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'content' => 'string',
    ];

    private static string $cachePrefix = 'subscribe_template:';

    private static function cacheStore(): Repository
    {
        return Cache::store(config('cache.settings_store', 'redis'));
    }

    public static function getContent(string $name): ?string
    {
        $cacheKey = self::$cachePrefix . $name;

        return self::cacheStore()->remember($cacheKey, 3600, function () use ($name) {
            return self::where('name', $name)->value('content');
        });
    }

    public static function setContent(string $name, ?string $content): void
    {
        self::updateOrCreate(
            ['name' => $name],
            ['content' => $content]
        );
        self::cacheStore()->forget(self::$cachePrefix . $name);
    }

    public static function getAllContents(): array
    {
        return self::pluck('content', 'name')->toArray();
    }

    public static function flushCache(string $name): void
    {
        self::cacheStore()->forget(self::$cachePrefix . $name);
    }
}
