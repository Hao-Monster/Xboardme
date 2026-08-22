<?php

namespace Tests\Unit;

use App\WebSocket\RedisConnectionConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RedisConnectionConfigTest extends TestCase
{
    public function test_tcp_acl_configuration_builds_two_argument_authentication(): void
    {
        $config = RedisConnectionConfig::fromArray([
            'host' => 'redis-infra',
            'port' => 6380,
            'username' => 'xboard_ws',
            'password' => 'secret',
        ], 'xboard_');

        $this->assertSame('redis://redis-infra:6380', $config->uri);
        $this->assertSame(['xboard_ws', 'secret'], $config->auth);
        $this->assertSame('xboard_node:push', $config->pushChannel);
        $this->assertSame('xboard_node:connection-replaced', $config->replacementChannel);
    }

    public function test_unix_socket_and_legacy_password_remain_supported(): void
    {
        $config = RedisConnectionConfig::fromArray([
            'host' => '/data/redis.sock',
            'port' => 6379,
            'password' => 'legacy-secret',
        ]);

        $this->assertSame('unix:///data/redis.sock', $config->uri);
        $this->assertSame('legacy-secret', $config->auth);
    }

    public function test_username_without_password_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a password');

        RedisConnectionConfig::fromArray([
            'host' => 'redis-infra',
            'username' => 'xboard_ws',
            'password' => null,
        ]);
    }
}
