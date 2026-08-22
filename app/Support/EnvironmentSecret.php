<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

final class EnvironmentSecret
{
    public static function resolveValue(mixed $inlineValue, mixed $fileValue, string $name): ?string
    {
        $inline = self::normalizeOptionalValue($inlineValue);
        $file = self::normalizeOptionalValue($fileValue);

        if ($inline !== null && $file !== null) {
            throw new InvalidArgumentException("{$name} and {$name}_FILE cannot both be set.");
        }

        if ($file === null) {
            return $inline;
        }

        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException("{$name}_FILE is not a readable file: {$file}");
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException("{$name}_FILE could not be read: {$file}");
        }

        $secret = rtrim($contents, "\r\n");
        if ($secret === '') {
            throw new RuntimeException("{$name}_FILE must not be empty.");
        }
        if (str_contains($secret, "\0")) {
            throw new RuntimeException("{$name}_FILE must not contain NUL bytes.");
        }

        return $secret;
    }

    private static function normalizeOptionalValue(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = (string) $value;
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        return $value;
    }
}
