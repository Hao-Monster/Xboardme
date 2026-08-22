<?php

namespace App\Support;

use InvalidArgumentException;

final class RuntimeHealthKey
{
    public static function forInstance(string $legacyKey): string
    {
        $instanceId = trim((string) config('app.runtime_instance_id', 'default'));
        if ($instanceId === '' || $instanceId === 'default') {
            return $legacyKey;
        }

        if (!preg_match('/\A[A-Za-z0-9_.-]+\z/', $instanceId)) {
            throw new InvalidArgumentException('RUNTIME_INSTANCE_ID contains unsupported characters.');
        }

        return "runtime_health:{$instanceId}:{$legacyKey}";
    }

    /**
     * Keep the legacy signal during the compatibility window while publishing
     * an instance-scoped signal that cannot be satisfied by another release.
     *
     * @return list<string>
     */
    public static function compatibilityKeys(string $legacyKey): array
    {
        $scopedKey = self::forInstance($legacyKey);

        return $scopedKey === $legacyKey ? [$legacyKey] : [$legacyKey, $scopedKey];
    }
}
