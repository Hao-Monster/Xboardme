<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\RealtimeStatsService;
use App\Services\StatisticalService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRealtimeStatsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        $this->app->instance(StatisticalService::class, \Mockery::mock(StatisticalService::class));
        Cache::flush();
        $this->now = Carbon::create(2026, 8, 18, 12, 0, 0, config('app.timezone'));
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_counts_only_positive_device_states_inside_the_shared_online_window(): void
    {
        $this->user('active-two@example.com', [
            'online_count' => 2,
            'last_online_at' => $this->now->copy()->subSeconds(299),
            't' => $this->now->copy()->subDay()->timestamp,
        ]);
        $this->user('boundary@example.com', [
            'online_count' => 1,
            'last_online_at' => $this->now->copy()->subSeconds(300),
            't' => 0,
        ]);
        $this->user('recent-zero@example.com', [
            'online_count' => 0,
            'last_online_at' => $this->now->copy()->subSecond(),
        ]);
        $this->user('stale@example.com', [
            'online_count' => 9,
            'last_online_at' => $this->now->copy()->subSeconds(301),
        ]);
        $this->user('missing-heartbeat@example.com', [
            'online_count' => 4,
            'last_online_at' => null,
        ]);

        $this->server('active', $this->now->copy()->subSeconds(299)->timestamp);
        $this->server('boundary', $this->now->copy()->subSeconds(300)->timestamp);
        $this->server('stale', $this->now->copy()->subSeconds(301)->timestamp);
        $this->server('never', null);

        $snapshot = app(RealtimeStatsService::class)->snapshot();

        $this->assertSame(3, $snapshot['onlineDevices']);
        $this->assertSame(2, $snapshot['onlineUsers']);
        $this->assertSame(2, $snapshot['onlineNodes']);
        $this->assertSame(DeviceStateService::ONLINE_WINDOW_SECONDS, $snapshot['windowSeconds']);
        $this->assertSame($this->now->timestamp, $snapshot['generatedAt']);
    }

    public function test_snapshot_returns_numeric_zeroes_when_nothing_is_online(): void
    {
        $this->user('offline@example.com', [
            'online_count' => 3,
            'last_online_at' => $this->now->copy()->subMinutes(10),
        ]);
        $this->server('offline', $this->now->copy()->subMinutes(10)->timestamp);

        $this->assertSame([
            'onlineDevices' => 0,
            'onlineUsers' => 0,
            'onlineNodes' => 0,
            'windowSeconds' => 300,
            'generatedAt' => $this->now->timestamp,
        ], app(RealtimeStatsService::class)->snapshot());
    }

    public function test_realtime_endpoint_requires_an_admin_and_exposes_the_snapshot_contract(): void
    {
        $endpoint = $this->endpoint('stat/getRealtimeStats');

        $this->getJson($endpoint)->assertForbidden();

        Sanctum::actingAs($this->user('member@example.com'));
        $this->getJson($endpoint)->assertForbidden();

        $admin = $this->user('admin@example.com', ['is_admin' => true]);
        $this->user('online@example.com', [
            'online_count' => 2,
            'last_online_at' => $this->now->copy()->subMinute(),
            't' => $this->now->copy()->subDay()->timestamp,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.onlineDevices', 2)
            ->assertJsonPath('data.onlineUsers', 1)
            ->assertJsonPath('data.onlineNodes', 0)
            ->assertJsonPath('data.windowSeconds', 300)
            ->assertJsonPath('data.generatedAt', $this->now->timestamp);
    }

    public function test_legacy_dashboard_stats_reuses_the_realtime_device_and_user_definition(): void
    {
        $admin = $this->user('legacy-admin@example.com', ['is_admin' => true]);
        $this->user('idle-but-alive@example.com', [
            'online_count' => 3,
            'last_online_at' => $this->now->copy()->subMinute(),
            't' => $this->now->copy()->subDay()->timestamp,
        ]);
        $this->user('traffic-without-alive@example.com', [
            'online_count' => 5,
            'last_online_at' => $this->now->copy()->subMinutes(6),
            't' => $this->now->timestamp,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson($this->endpoint('stat/getStats'))
            ->assertOk()
            ->assertJsonPath('data.onlineDevices', 3)
            ->assertJsonPath('data.onlineUsers', 1)
            ->assertJsonPath('data.onlineNodes', 0);
    }

    public function test_realtime_index_is_present_for_the_window_query(): void
    {
        $indexes = collect(Schema::getIndexes('v2_user'))->pluck('name');

        $this->assertTrue($indexes->contains('idx_user_last_online_at'));
    }

    public function test_realtime_index_migration_can_roll_back_and_reapply(): void
    {
        $migration = require database_path(
            'migrations/2026_08_18_000001_add_last_online_at_index_to_v2_user_table.php'
        );

        $migration->down();
        $this->assertFalse(
            collect(Schema::getIndexes('v2_user'))->pluck('name')->contains('idx_user_last_online_at')
        );

        $migration->up();
        $this->assertTrue(
            collect(Schema::getIndexes('v2_user'))->pluck('name')->contains('idx_user_last_online_at')
        );
    }

    private function endpoint(string $path): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return '/api/v2/' . $securePath . '/' . ltrim($path, '/');
    }

    private function user(string $email, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
            't' => 0,
        ], $attributes));
    }

    private function server(string $name, ?int $lastCheckAt): Server
    {
        $server = Server::query()->create([
            'name' => 'Realtime ' . $name,
            'type' => Server::TYPE_SOCKS,
            'host' => '127.0.0.1',
            'port' => 10000 + Server::query()->count(),
            'server_port' => 10000 + Server::query()->count(),
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
        ]);

        if ($lastCheckAt !== null) {
            Cache::put(CacheKey::get('SERVER_SOCKS_LAST_CHECK_AT', $server->id), $lastCheckAt, 3600);
        }

        return $server;
    }
}
