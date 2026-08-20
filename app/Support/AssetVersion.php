<?php

namespace App\Support;

use InvalidArgumentException;

final class AssetVersion
{
    public static function current(): string
    {
        return self::resolve((string) config('app.version'), (string) app()->environment());
    }

    public static function resolve(string $version, string $environment): string
    {
        $version = trim($version);

        if ($version === '' || str_contains(strtolower($version), 'unknown')) {
            throw new InvalidArgumentException('The asset version must identify a known release.');
        }

        if ($environment === 'production' && preg_match('/^[a-f0-9]{40}$/', $version) !== 1) {
            throw new InvalidArgumentException('Production assets must be versioned by the full release commit SHA.');
        }

        return $version;
    }
}
