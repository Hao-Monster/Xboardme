<?php

namespace Tests\Unit;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
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
}
