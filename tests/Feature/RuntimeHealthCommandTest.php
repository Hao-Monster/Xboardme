<?php

namespace Tests\Feature;

use App\Support\RuntimeHealthKey;
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

    public function test_ws_health_does_not_accept_another_runtime_instances_signal(): void
    {
        config()->set('app.runtime_instance_id', 'release-b-ws');
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->twice()->andReturn('PONG');
        Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis);

        Cache::put(NodeWorker::HEARTBEAT_CACHE_KEY, time(), 60);
        Cache::put(NodeWorker::REDIS_READY_CACHE_KEY, time(), 60);

        $this->assertSame(1, Artisan::call('runtime:health', ['role' => 'ws']));
        $this->assertStringContainsString('"ws_process":false', Artisan::output());

        Cache::put(RuntimeHealthKey::forInstance(NodeWorker::HEARTBEAT_CACHE_KEY), time(), 60);
        Cache::put(RuntimeHealthKey::forInstance(NodeWorker::REDIS_READY_CACHE_KEY), time(), 60);

        $this->assertSame(0, Artisan::call('runtime:health', ['role' => 'ws']));
        $this->assertStringContainsString('"ws_redis_subscription":true', Artisan::output());
    }

    public function test_scheduler_health_uses_the_current_runtime_instance_tick(): void
    {
        config()->set('app.runtime_instance_id', 'release-b-scheduler');
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->twice()->andReturnTrue();
        Redis::shouldReceive('connection')->twice()->with('default')->andReturn($redis);
        Cache::put('SCHEDULE_LAST_CHECK_AT', time(), 180);

        $this->assertSame(1, Artisan::call('runtime:health', ['role' => 'scheduler']));
        $this->assertStringContainsString('"scheduler_tick":false', Artisan::output());

        Cache::put(RuntimeHealthKey::forInstance('SCHEDULE_LAST_CHECK_AT'), time(), 180);

        $this->assertSame(0, Artisan::call('runtime:health', ['role' => 'scheduler']));
        $this->assertStringContainsString('"scheduler_tick":true', Artisan::output());
    }
}
