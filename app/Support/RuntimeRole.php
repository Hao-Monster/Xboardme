<?php

namespace App\Support;

use InvalidArgumentException;

final class RuntimeRole
{
    public const LEGACY = 'legacy';
    public const WEB = 'web';
    public const WS = 'ws';
    public const HORIZON = 'horizon';
    public const SCHEDULER = 'scheduler';
    public const MAINTENANCE = 'maintenance';

    private const ROLES = [
        self::LEGACY,
        self::WEB,
        self::WS,
        self::HORIZON,
        self::SCHEDULER,
        self::MAINTENANCE,
    ];

    public static function normalize(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException("Unsupported Xboard runtime role: {$role}");
        }

        return $role;
    }

    public static function schedulerEnabled(string $role, mixed $configured): bool
    {
        if (self::normalize($role) !== self::LEGACY) {
            return false;
        }

        if ($configured === null || $configured === '') {
            return true;
        }

        $enabled = filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new InvalidArgumentException('ENABLE_SCHEDULER must be a boolean value.');
        }

        return $enabled;
    }
}
