<?php

namespace Tests\Feature;

use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class RuntimeHealthCommandTest extends TestCase
{
    public function test_web_health_reports_database_redis_and_release_identity(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturn('PONG');
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);

        $this->assertSame(0, Artisan::call('runtime:health', ['role' => 'web']));
        $output = Artisan::output();

        $this->assertStringContainsString('"healthy":true', $output);
        $this->assertStringContainsString('"database":true', $output);
        $this->assertStringContainsString('"redis":true', $output);
    }

    public function test_ws_health_fails_when_redis_subscription_readiness_is_stale(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->once()->andReturnTrue();
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);
        Cache::put(NodeWorker::HEARTBEAT_CACHE_KEY, time(), 60);
        Cache::put(NodeWorker::REDIS_READY_CACHE_KEY, time() - 60, 60);

        $this->assertSame(1, Artisan::call('runtime:health', ['role' => 'ws']));
        $output = Artisan::output();

        $this->assertStringContainsString('"healthy":false', $output);
        $this->assertStringContainsString('"ws_redis_subscription":false', $output);
    }
}
