<?php

namespace Tests\Unit;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DeviceStateServiceTest extends TestCase
{
    public function test_device_count_deduplicates_the_same_ip_across_nodes_and_excludes_expired_states(): void
    {
        $now = time();
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:42')
            ->andReturn([
                '1:203.0.113.10' => $now,
                '2:203.0.113.10' => $now - DeviceStateService::ONLINE_WINDOW_SECONDS,
                '2:2001:db8::10' => $now - 1,
                '3:192.0.2.99' => $now - DeviceStateService::ONLINE_WINDOW_SECONDS - 1,
            ]);

        $this->assertSame(2, app(DeviceStateService::class)->getDeviceCount(42));
    }

    public function test_the_same_ip_is_counted_once_for_each_different_user(): void
    {
        $now = time();
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:10')
            ->andReturn(['1:203.0.113.10' => $now]);
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:11')
            ->andReturn(['1:203.0.113.10' => $now]);

        $service = app(DeviceStateService::class);

        $this->assertSame(2, $service->getDeviceCount(10) + $service->getDeviceCount(11));
    }

    public function test_node_device_lookup_uses_the_node_index_instead_of_redis_keys(): void
    {
        $now = time();
        Redis::shouldReceive('keys')->never();
        Redis::shouldReceive('smembers')
            ->once()
            ->with('node_device_users:7')
            ->andReturn(['10', '11']);
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:10')
            ->andReturn(['7:203.0.113.10' => $now, '8:192.0.2.5' => $now]);
        Redis::shouldReceive('hgetall')
            ->once()
            ->with('user_devices:11')
            ->andReturn(['7:2001:db8::11' => $now]);

        $this->assertSame([
            10 => ['203.0.113.10'],
            11 => ['2001:db8::11'],
        ], app(DeviceStateService::class)->getNodeDevices(7));
    }

    public function test_set_devices_atomically_replaces_normalized_ips_and_queues_a_trailing_db_update(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                $luaArguments = $arguments[1] ?? [];

                return $command === 'eval'
                    && is_string($arguments[0] ?? null)
                    && ($arguments[2] ?? null) === 2
                    && ($luaArguments[0] ?? null) === 'user_devices:42'
                    && ($luaArguments[1] ?? null) === 'node_device_users:7'
                    && ($luaArguments[2] ?? null) === '7:'
                    && is_int($luaArguments[3] ?? null)
                    && ($luaArguments[4] ?? null) === DeviceStateService::ONLINE_WINDOW_SECONDS
                    && ($luaArguments[5] ?? null) === 42
                    && array_slice($luaArguments, 6) === ['7:203.0.113.10', '7:2001:db8::10'];
            })
            ->andReturn(1);
        Redis::shouldReceive('command')
            ->once()
            ->with('set', ['device:db_throttle:42', '1', ['NX', 'EX' => 10]])
            ->andReturn(false);
        Redis::shouldReceive('zadd')
            ->once()
            ->withArgs(fn(string $key, array $members): bool =>
                $key === 'device:db_update_pending'
                && isset($members[42])
                && $members[42] >= time() + 9
            )
            ->andReturn(1);

        app(DeviceStateService::class)->setDevices(42, 7, [
            '203.0.113.10:443',
            '203.0.113.10',
            '[2001:db8::10]:8443',
            'not-an-ip',
            123,
        ]);
    }

    public function test_pending_db_update_remains_queued_when_flushing_fails(): void
    {
        Redis::shouldReceive('zrangebyscore')
            ->once()
            ->andReturn(['42']);
        Redis::shouldReceive('zrem')
            ->never();
        Redis::shouldReceive('zadd')
            ->never();
        Redis::shouldReceive('command')
            ->never();

        $service = Mockery::mock(DeviceStateService::class)->makePartial();
        $service->shouldReceive('notifyUpdate')
            ->once()
            ->with(42)
            ->andThrow(new RuntimeException('database unavailable'));

        $this->expectException(RuntimeException::class);
        $service->flushPendingUpdates();
    }

    public function test_successful_pending_db_update_only_removes_the_due_version(): void
    {
        Redis::shouldReceive('zrangebyscore')
            ->once()
            ->andReturn(['42']);
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                $script = $arguments[0] ?? '';
                $luaArguments = $arguments[1] ?? [];

                return $command === 'eval'
                    && str_contains($script, "redis.call('ZSCORE'")
                    && str_contains($script, "redis.call('ZREM'")
                    && ($arguments[2] ?? null) === 1
                    && ($luaArguments[0] ?? null) === 'device:db_update_pending'
                    && ($luaArguments[1] ?? null) === '42'
                    && is_int($luaArguments[2] ?? null);
            })
            ->andReturn(1);

        $service = Mockery::mock(DeviceStateService::class)->makePartial();
        $service->shouldReceive('notifyUpdate')
            ->once()
            ->with(42);

        $this->assertSame(1, $service->flushPendingUpdates());
    }
}
