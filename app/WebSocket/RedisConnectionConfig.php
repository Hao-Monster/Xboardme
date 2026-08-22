<?php

namespace App\WebSocket;

use InvalidArgumentException;

final class RedisConnectionConfig
{
    /**
     * @param string|array{0:string,1:string}|null $auth
     */
    private function __construct(
        public readonly string $uri,
        public readonly string|array|null $auth,
        public readonly string $pushChannel,
        public readonly string $replacementChannel,
    ) {
    }

    public static function fromLaravelConfig(): self
    {
        return self::fromArray(
            (array) config('database.redis.default', []),
            (string) config('database.redis.options.prefix', '')
        );
    }

    public static function fromArray(array $connection, string $prefix = ''): self
    {
        $host = trim((string) ($connection['host'] ?? '127.0.0.1'));
        if ($host === '' || str_contains($host, "\0")) {
            throw new InvalidArgumentException('Redis host must not be empty.');
        }

        if (str_starts_with($host, '/')) {
            $uri = "unix://{$host}";
        } else {
            $port = (int) ($connection['port'] ?? 6379);
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Redis port is outside the valid range.');
            }
            $uri = "redis://{$host}:{$port}";
        }

        $username = self::optionalString($connection['username'] ?? null);
        $password = self::optionalString($connection['password'] ?? null);
        if ($username !== null && $password === null) {
            throw new InvalidArgumentException('Redis ACL username requires a password.');
        }

        $auth = $username !== null ? [$username, $password] : $password;

        return new self(
            $uri,
            $auth,
            $prefix . 'node:push',
            $prefix . 'node:connection-replaced'
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
