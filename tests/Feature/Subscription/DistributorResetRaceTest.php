<?php

namespace Tests\Feature\Subscription;

use App\Jobs\UpdateDistributorHwidObservation;
use App\Models\DistributorOrder;
use App\Services\DistributorHwidService;
use App\Services\DistributorHwidObservationPublisher;
use App\Services\DistributorSubscriptionResetService;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class DistributorResetRaceTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    private static ?LoopbackRespRedisServer $redisServer = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$redisServer = new LoopbackRespRedisServer();
        self::$redisServer->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$redisServer?->stop();
        self::$redisServer = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$redisServer?->configureApplication($this->app);
        self::$redisServer?->flushAll();
    }

    public static function authorizationModes(): array
    {
        return ['synchronous' => [false], 'asynchronous' => [true]];
    }

    #[DataProvider('authorizationModes')]
    public function test_old_authenticated_snapshot_cannot_rebind_after_reset(bool $async): void
    {
        config(['subscription.hwid_async_metadata' => $async]);
        $fixture = $this->makeDistributorSubscriptionFixture();
        $oldSubscriber = $fixture['subscriber'];
        $oldUuid = $oldSubscriber->uuid;
        $this->resetFixture($fixture);

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'HTTP_X_HWID' => 'old-request-device-001',
        ]);
        $result = app(DistributorHwidService::class)->authorizeSubscription($oldSubscriber, $request);
        $this->assertFalse($result['allowed']);
        $this->assertArrayNotHasKey('x-hwid-max-devices-reached', $result['headers']);
        $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());
        $this->assertSame($oldUuid, $oldSubscriber->uuid, 'Never refresh an old authenticated snapshot into new credentials.');

        $freshResult = app(DistributorHwidService::class)->authorizeSubscription($oldSubscriber->fresh(), $request);
        $this->assertTrue($freshResult['allowed']);
        $this->assertSame(1, $fixture['delivery']->hwidDevices()->count());
    }

    public static function deliveryStates(): array
    {
        return ['pending' => [false], 'previously-issued' => [true]];
    }

    #[DataProvider('deliveryStates')]
    public function test_reset_after_readonly_hwid_authorization_blocks_response_and_stale_delivery_writes(bool $issued): void
    {
        $this->mock(DistributorHwidObservationPublisher::class)->shouldNotReceive('publish');
        config(['subscription.hwid_async_metadata' => true, 'subscription.hwid_observation_worker_enabled' => true]);
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->makeOnlineServer();
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'registered-reset-race');
        if ($issued) {
            $fixture['delivery']->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1700000000,
                'config_issued_at' => 1700000001,
            ]);
        }
        $history = $fixture['delivery']->fresh()->only(['delivery_status', 'claimed_at', 'config_issued_at']);
        $reset = false;
        DB::listen(function ($event) use ($fixture, &$reset): void {
            if (!$reset && str_contains($event->sql, '_matched_hwid_device_id')) {
                $reset = true;
                $this->resetFixture($fixture);
            }
        });

        $this->get('/api/v1/client/subscribe?token=' . $fixture['subscriber']->token, [
            'X-HWID' => 'registered-reset-race',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertForbidden()->assertContent('');

        $this->assertTrue($reset);
        $this->assertSame($history, $fixture['delivery']->fresh()->only(array_keys($history)));
        $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());
    }

    public function test_reset_during_response_headers_is_fenced_without_publishing_observation(): void
    {
        config(['subscription.hwid_async_metadata' => true, 'subscription.hwid_observation_worker_enabled' => true]);
        $this->mock(DistributorHwidObservationPublisher::class)->shouldNotReceive('publish');
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->makeOnlineServer();
        $fixture['delivery']->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'claimed_at' => 1700000000,
            'config_issued_at' => 1700000001,
        ]);
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'headers-reset-device');
        $reset = false;
        DB::listen(function ($event) use ($fixture, &$reset): void {
            if (!$reset && preg_match('/^\s*select\b.*\bfrom\s+[`"]?v2_order[`"]?\b/is', $event->sql) === 1) {
                $reset = true;
                $this->resetFixture($fixture);
            }
        });
        $response = $this->get('/s/' . $fixture['subscriber']->token, [
            'X-HWID' => 'headers-reset-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertForbidden()->assertContent('');
        $this->assertTrue($reset);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_protocol_exception_does_not_publish_an_observation_but_success_still_does(): void
    {
        config(['subscription.hwid_async_metadata' => true, 'subscription.hwid_observation_worker_enabled' => true]);
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->makeOnlineServer();
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'protocol-exception-device');
        $publisher = $this->mock(DistributorHwidObservationPublisher::class);
        $publisher->shouldReceive('publish')->once()->andReturn(['result' => 'published', 'error_category' => null]);
        HookManager::registerFilter('client.subscribe.servers', static function (): array {
            throw new \RuntimeException('simulated protocol failure');
        });
        try {
            $this->get('/s/' . $fixture['subscriber']->token, [
                'X-HWID' => 'protocol-exception-device', 'User-Agent' => 'ClashMeta/1.0',
            ])->assertStatus(500);
        } finally {
            HookManager::remove('client.subscribe.servers');
        }
        $this->get('/s/' . $fixture['subscriber']->token, [
            'X-HWID' => 'protocol-exception-device', 'User-Agent' => 'ClashMeta/1.0',
        ])->assertOk();
    }

    public function test_disabled_hwid_stale_request_is_also_fenced_before_response(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture(hwidEnabled: false);
        $this->makeOnlineServer();
        $reset = false;
        DB::listen(function ($event) use ($fixture, &$reset): void {
            $sql = strtolower($event->sql);
            if (!$reset && str_starts_with($sql, 'select') && str_contains($sql, 'v2_distributor_order')) {
                $reset = true;
                $this->resetFixture($fixture);
            }
        });

        $this->get('/api/v1/client/subscribe?token=' . $fixture['subscriber']->token, [
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertForbidden()->assertContent('');
        $this->assertTrue($reset);
        $this->assertNull($fixture['delivery']->fresh()->claimed_at);
    }

    public function test_old_observation_does_not_resurrect_or_modify_rebound_device(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $oldDevice = $this->seedRegisteredHwidDevice($fixture['delivery'], 'observation-reset-device');
        $job = new UpdateDistributorHwidObservation(
            deviceId: $oldDevice->id,
            distributorOrderId: $fixture['delivery']->id,
            hwid: $oldDevice->hwid,
            observedAt: time() + 9999,
            deviceOs: 'StaleOS',
            osVersion: '0',
            deviceModel: 'StalePhone',
            userAgent: 'StaleAgent',
            ip: '198.51.100.250'
        );
        $this->resetFixture($fixture);
        $job->handle();
        $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());

        $newDevice = $this->seedRegisteredHwidDevice($fixture['delivery'], $oldDevice->hwid, ['device_model' => 'NewPhone']);
        $before = $newDevice->fresh()->getAttributes();
        $job->handle();
        $this->assertNotSame($oldDevice->id, $newDevice->id);
        $this->assertSame($before, $newDevice->fresh()->getAttributes());
    }

    private function resetFixture(array $fixture): void
    {
        app(DistributorSubscriptionResetService::class)->reset(
            $fixture['distributor'],
            $fixture['order']->trade_no,
            DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']),
            bin2hex(random_bytes(16))
        );
    }
}
