<?php

namespace Tests\Feature\Subscription;

use App\Jobs\UpdateDistributorHwidObservation;
use App\Models\DistributorHwidDevice;
use App\Models\DistributorOrder;
use App\Services\DistributorHwidService;
use App\Support\SubscriptionTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionHwidAsyncTest extends TestCase
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

    public function test_h01_registered_device_async_branch_performs_zero_device_writes_zero_counts_strips_alias_and_builds_scalar_payload(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $device = $this->seedRegisteredHwidDevice($delivery, 'registered-h01-device', [
            'last_seen_at' => 1_700_000_000,
        ]);

        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_X_HWID' => '  registered-h01-device  ',
            'HTTP_X_DEVICE_OS' => str_repeat('A', 130),
            'HTTP_X_VER_OS' => str_repeat('1', 130),
            'HTTP_X_DEVICE_MODEL' => str_repeat('M', 200),
            'HTTP_USER_AGENT' => str_repeat('U', 400),
        ]);

        $timing = SubscriptionTiming::attach($request);
        $service = app(DistributorHwidService::class);

        $captured = $this->captureQueries(fn (): array => $service->authorizeSubscription($subscriber, $request, $timing));
        $result = $captured['result'];

        $this->assertTrue($result['allowed']);
        $this->assertSame('true', $result['headers']['x-hwid-active'] ?? null);

        // Zero device writes and zero COUNT queries
        $this->assertCount(0, $this->filterWriteQueriesForTable($captured['queries'], 'v2_distributor_hwid_device'));
        foreach ($captured['queries'] as $q) {
            $this->assertStringNotContainsString('count(', strtolower($q['sql']));
        }

        // _matched_hwid_device_id alias must never leak onto DistributorOrder attributes or original
        /** @var DistributorOrder $returnedDelivery */
        $returnedDelivery = $result['delivery'];
        $this->assertInstanceOf(DistributorOrder::class, $returnedDelivery);
        $this->assertArrayNotHasKey('_matched_hwid_device_id', $returnedDelivery->getAttributes());
        $this->assertArrayNotHasKey('_matched_hwid_device_id', $returnedDelivery->getRawOriginal());

        // Exact scalar observation payload with strict field truncation
        $obs = $result['observation'];
        $this->assertIsArray($obs);
        $this->assertSame([
            'device_id',
            'distributor_order_id',
            'hwid',
            'observed_at',
            'device_os',
            'os_version',
            'device_model',
            'user_agent',
            'ip',
        ], array_keys($obs));
        $this->assertSame((int) $device->id, $obs['device_id']);
        $this->assertSame((int) $delivery->id, $obs['distributor_order_id']);
        $this->assertSame('registered-h01-device', $obs['hwid']);
        $this->assertIsInt($obs['observed_at']);
        $this->assertSame(100, mb_strlen((string) $obs['device_os']));
        $this->assertSame(100, mb_strlen((string) $obs['os_version']));
        $this->assertSame(150, mb_strlen((string) $obs['device_model']));
        $this->assertSame(255, mb_strlen((string) $obs['user_agent']));
        $this->assertSame('203.0.113.77', $obs['ip']);

        // DB row was NOT modified synchronously
        $this->assertSame(1_700_000_000, (int) $device->fresh()->last_seen_at);

        // When feature switch is false, legacy sync path updates DB immediately and returns no observation
        config(['subscription.hwid_async_metadata' => false]);
        $syncTiming = SubscriptionTiming::attach($request);
        $syncResult = $service->authorizeSubscription($subscriber, $request, $syncTiming);
        $this->assertTrue($syncResult['allowed']);
        $this->assertNull($syncResult['observation'] ?? null);
        $this->assertGreaterThan(1_700_000_000, (int) $device->fresh()->last_seen_at);
    }

    public function test_h03_limit_reduction_toggle_off_deleted_delivery_race_and_invalid_hwid_validation(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];

        $this->seedRegisteredHwidDevice($delivery, 'bound-device-alpha');
        $this->seedRegisteredHwidDevice($delivery, 'bound-device-bravo');

        // Lower hwid_limit from 2 -> 1: existing bound devices STILL pass; new 3rd device is blocked
        app(DistributorHwidService::class)->updateSettings($fixture['order']->id, true, 1);

        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'bound-device-alpha',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk()->assertHeader('x-hwid-active', 'true');

        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'unregistered-device-charlie',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertNotFound()
            ->assertHeader('x-hwid-max-devices-reached', 'true')
            ->assertHeader('x-hwid-limit', 'true');

        // Invalid / control-char / overlong HWID rejected before binding raw untrusted HWID into LEFT JOIN
        $overlongHwid = str_repeat('x', 200);
        $invalidRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => $overlongHwid,
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ]
        ));
        $invalidRun['result']->assertNotFound()->assertHeader('x-hwid-not-supported', 'true');
        foreach ($invalidRun['queries'] as $entry) {
            $this->assertNotContains($overlongHwid, $entry['bindings']);
        }

        // Case 2: hwid_enabled toggled off between initial LEFT JOIN read and transaction re-check
        $toggleFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $toggleDelivery = $toggleFixture['delivery'];
        $toggleSubscriber = $toggleFixture['subscriber'];

        $toggleActive = true;
        $toggled = false;
        DB::listen(function ($event) use ($toggleDelivery, &$toggleActive, &$toggled): void {
            if (!$toggleActive || $toggled) {
                return;
            }
            if (str_contains(strtolower($event->sql), '_matched_hwid_device_id')) {
                $toggled = true;
                DB::table('v2_distributor_order')
                    ->where('id', $toggleDelivery->id)
                    ->update(['hwid_enabled' => false]);
            }
        });

        try {
            $toggleResp = $this->get('/api/v1/client/subscribe?token=' . $toggleSubscriber->token, [
                'x-hwid' => 'toggle-race-new-device',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ]);
        } finally {
            $toggleActive = false;
        }
        $toggleResp->assertOk()->assertHeaderMissing('x-hwid-active');
        $this->assertSame(0, DistributorHwidDevice::query()->where('distributor_order_id', $toggleDelivery->id)->count());

        // Case 1: delivery deleted between initial LEFT JOIN read and transaction re-check -> MUST deny (404), NOT pass as ordinary user!
        $deletedFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $deletedDelivery = $deletedFixture['delivery'];
        $deletedSubscriber = $deletedFixture['subscriber'];

        $deleteHookActive = true;
        $deleted = false;
        DB::listen(function ($event) use ($deletedDelivery, &$deleteHookActive, &$deleted): void {
            if (!$deleteHookActive || $deleted) {
                return;
            }
            if (str_contains(strtolower($event->sql), '_matched_hwid_device_id')) {
                $deleted = true;
                DB::table('v2_distributor_order')->where('id', $deletedDelivery->id)->delete();
            }
        });

        try {
            $deletedResp = $this->get('/api/v1/client/subscribe?token=' . $deletedSubscriber->token, [
                'x-hwid' => 'deleted-delivery-device',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ]);
        } finally {
            $deleteHookActive = false;
        }
        $deletedResp->assertNotFound();
    }

    public function test_h04_deleted_device_rebound_with_same_hwid_is_untouched_by_stale_queued_job(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];

        $oldDevice = $this->seedRegisteredHwidDevice($delivery, 'rebound-hwid-0001', [
            'last_seen_at' => 1_760_000_100,
            'device_model' => 'OldPhone',
            'ip' => '198.51.100.1',
        ]);
        $oldDeviceId = (int) $oldDevice->id;

        // Admin deletes oldDevice
        $deletedOk = app(DistributorHwidService::class)->deleteDevice($fixture['order']->id, $oldDeviceId);
        $this->assertTrue($deletedOk);
        $this->assertNull(DistributorHwidDevice::query()->find($oldDeviceId));

        // Client re-binds the exact same HWID -> gets a brand new auto-increment primary key
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'rebound-hwid-0001',
            'x-device-model' => 'NewPhonePro',
            'User-Agent' => 'ClashMetaForAndroid/2.10.9',
            'REMOTE_ADDR' => '198.51.100.2',
        ])->assertOk();

        $newDevice = DistributorHwidDevice::query()
            ->where('distributor_order_id', $delivery->id)
            ->where('hwid', 'rebound-hwid-0001')
            ->firstOrFail();
        $this->assertNotSame($oldDeviceId, (int) $newDevice->id);
        $newSeenAt = (int) $newDevice->last_seen_at;

        // Stale queued job referencing oldDeviceId executes AFTER newDevice exists with a futuristic timestamp
        $staleJob = new UpdateDistributorHwidObservation(
            deviceId: $oldDeviceId,
            distributorOrderId: (int) $delivery->id,
            hwid: 'rebound-hwid-0001',
            observedAt: $newSeenAt + 9999,
            deviceOs: 'StaleOS',
            osVersion: '0.1',
            deviceModel: 'StaleGhostPhone',
            userAgent: 'StaleAgent/0.1',
            ip: '203.0.113.250'
        );
        $staleJob->handle();

        // Old device is NOT revived, and newDevice is completely untouched
        $this->assertNull(DistributorHwidDevice::query()->find($oldDeviceId));
        $newDevice->refresh();
        $this->assertSame($newSeenAt, (int) $newDevice->last_seen_at);
        $this->assertSame('NewPhonePro', $newDevice->device_model);
    }

    public function test_h05_observation_job_enforces_monotonic_timestamp_same_second_first_wins_and_coalesce_null_preservation(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $delivery = $fixture['delivery'];

        $device = $this->seedRegisteredHwidDevice($delivery, 'monotonic-h05-dev', [
            'first_seen_at' => 1_760_000_000,
            'last_seen_at' => 1_760_000_500,
            'device_os' => 'iOS',
            'os_version' => '17.4',
            'device_model' => 'iPhone 15 Pro',
            'user_agent' => 'Shadowrocket/2000',
            'ip' => '198.51.100.10',
        ]);

        // 1. Out-of-order older event (observed_at < last_seen_at) -> 0 changes
        (new UpdateDistributorHwidObservation(
            deviceId: (int) $device->id,
            distributorOrderId: (int) $delivery->id,
            hwid: 'monotonic-h05-dev',
            observedAt: 1_760_000_400,
            deviceOs: 'Android',
            osVersion: '12',
            deviceModel: 'OldModel',
            userAgent: 'OldAgent/1.0',
            ip: '198.51.100.11'
        ))->handle();

        $device->refresh();
        $this->assertSame(1_760_000_500, (int) $device->last_seen_at);
        $this->assertSame('iOS', $device->device_os);
        $this->assertSame('iPhone 15 Pro', $device->device_model);

        // 2. Same-second / duplicate event (observed_at == last_seen_at) -> first wins, 0 changes
        (new UpdateDistributorHwidObservation(
            deviceId: (int) $device->id,
            distributorOrderId: (int) $delivery->id,
            hwid: 'monotonic-h05-dev',
            observedAt: 1_760_000_500,
            deviceOs: 'Android',
            osVersion: '13',
            deviceModel: 'SameSecModel',
            userAgent: 'SameSecAgent/1.0',
            ip: '198.51.100.12'
        ))->handle();

        $device->refresh();
        $this->assertSame(1_760_000_500, (int) $device->last_seen_at);
        $this->assertSame('iPhone 15 Pro', $device->device_model);
        $this->assertSame('198.51.100.10', $device->ip);

        // 3. Newer event missing optional metadata headers (nulls) -> updates last_seen_at + non-nulls, preserves existing values for nulls!
        (new UpdateDistributorHwidObservation(
            deviceId: (int) $device->id,
            distributorOrderId: (int) $delivery->id,
            hwid: 'monotonic-h05-dev',
            observedAt: 1_760_000_800,
            deviceOs: null,
            osVersion: '17.5',
            deviceModel: null,
            userAgent: 'Shadowrocket/2005',
            ip: null
        ))->handle();

        $device->refresh();
        $this->assertSame(1_760_000_800, (int) $device->last_seen_at);
        $this->assertSame('iOS', $device->device_os);
        $this->assertSame('17.5', $device->os_version);
        $this->assertSame('iPhone 15 Pro', $device->device_model);
        $this->assertSame('Shadowrocket/2005', $device->user_agent);
        $this->assertSame('198.51.100.10', $device->ip);
        $this->assertSame(1_760_000_000, (int) $device->first_seen_at);
    }
}
