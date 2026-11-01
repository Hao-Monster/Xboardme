<?php

namespace Tests\Feature\Subscription;

use App\Models\DistributorHwidDevice;
use App\Models\DistributorOrder;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\InteractsWithIsolatedSubscriptionSqlite;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionConcurrencyWalTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use InteractsWithIsolatedSubscriptionSqlite;

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

    protected function tearDown(): void
    {
        $this->cleanupIsolatedFileSqliteWal();
        parent::tearDown();
    }

    public function test_s02_two_concurrent_processes_on_same_pending_snapshot_preserve_first_claim_and_allow_loser_to_win_issue(): void
    {
        $dbPath = $this->bootIsolatedFileSqliteWal();
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $this->seedRegisteredHwidDevice($delivery, 's02-shared-dev');

        $uri = '/api/v1/client/subscribe?token=' . $subscriber->token;

        $results = $this->runConcurrentSubscribeWorkers([
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $uri,
                'remote_addr' => '198.51.100.11',
                'headers' => [
                    'x-hwid' => 's02-shared-dev',
                    'User-Agent' => 'ClashMeta/WorkerOne',
                ],
                'barriers' => [
                    'before_delivery_claim' => ['sleep_ms' => 0],
                    'before_delivery_issue' => ['sleep_ms' => 60],
                ],
            ],
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $uri,
                'remote_addr' => '198.51.100.22',
                'headers' => [
                    'x-hwid' => 's02-shared-dev',
                    'User-Agent' => 'ClashMeta/WorkerTwo',
                ],
                'barriers' => [
                    'before_delivery_claim' => ['sleep_ms' => 50],
                    'before_delivery_issue' => ['sleep_ms' => 0],
                ],
            ],
        ]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertSame(200, $results[1]['status']);
        $this->assertGreaterThan(0, $results[0]['body_bytes']);
        $this->assertGreaterThan(0, $results[1]['body_bytes']);

        $this->assertCount(2, $this->filterWriteQueriesForTable($results[0]['queries'], 'v2_distributor_order'));
        $this->assertCount(2, $this->filterWriteQueriesForTable($results[1]['queries'], 'v2_distributor_order'));

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertSame('198.51.100.11', $delivery->claim_ip);
        $this->assertSame('ClashMeta/WorkerOne', $delivery->claim_ua);
        $this->assertNotNull($delivery->claimed_at);
        $this->assertNotNull($delivery->config_issued_at);
    }

    public function test_h01_external_1500ms_sqlite_wal_write_lock_does_not_block_registered_repeat_get(): void
    {
        $dbPath = $this->bootIsolatedFileSqliteWal();
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $this->seedRegisteredHwidDevice($delivery, 'wal-lock-dev');

        DB::table('v2_distributor_order')
            ->where('id', $delivery->id)
            ->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1_760_000_100,
                'config_issued_at' => 1_760_000_100,
            ]);

        // Warm template cache before acquiring DB write lock
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'wal-lock-dev',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        // Another OS process holds BEGIN IMMEDIATE on the SQLite WAL file for 1.8s (> 1.5s requirement)
        $lockHandle = $this->spawnSqliteWriteLockHolder($dbPath, 1800);
        try {
            $startedNs = hrtime(true);
            $run = $this->captureQueries(fn () => $this->get(
                '/api/v1/client/subscribe?token=' . $subscriber->token,
                [
                    'x-hwid' => 'wal-lock-dev',
                    'User-Agent' => 'ClashMetaForAndroid/2.10.1',
                ]
            ));
            $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;
        } finally {
            $this->releaseSqliteWriteLockHolder($lockHandle);
        }

        $run['result']->assertOk();
        $this->assertLessThan(600.0, $elapsedMs, 'Registered repeat GET must not wait on the 1.8s SQLite write lock.');
        $this->assertCount(0, $this->filterWriteQueriesForTable($run['queries'], 'v2_distributor_order'));
        $this->assertCount(0, $this->filterWriteQueriesForTable($run['queries'], 'v2_distributor_hwid_device'));
    }

    public function test_h02_concurrent_same_hwid_registration_and_concurrent_distinct_hwids_racing_for_last_slot(): void
    {
        $dbPath = $this->bootIsolatedFileSqliteWal();
        $this->makeOnlineServer();

        // 1. Concurrent same-HWID registration: both processes read _matched_hwid_device_id=null, synchronize, then race
        $sameFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $sameDelivery = $sameFixture['delivery'];
        $sameUri = '/api/v1/client/subscribe?token=' . $sameFixture['subscriber']->token;

        $sameResults = $this->runConcurrentSubscribeWorkers([
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $sameUri,
                'remote_addr' => '198.51.100.41',
                'headers' => [
                    'x-hwid' => 'race-same-hwid-001',
                    'x-device-os' => 'iOS',
                    'User-Agent' => 'Shadowrocket/1901',
                ],
                'barriers' => [
                    'after_hwid_left_join' => ['sleep_ms' => 0],
                ],
            ],
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $sameUri,
                'remote_addr' => '198.51.100.42',
                'headers' => [
                    'x-hwid' => 'race-same-hwid-001',
                    'x-device-os' => 'iOS',
                    'User-Agent' => 'Shadowrocket/1902',
                ],
                'barriers' => [
                    'after_hwid_left_join' => ['sleep_ms' => 0],
                ],
            ],
        ]);

        $this->assertSame(200, $sameResults[0]['status']);
        $this->assertSame(200, $sameResults[1]['status']);
        $this->assertSame(
            1,
            DistributorHwidDevice::query()->where('distributor_order_id', $sameDelivery->id)->count()
        );

        // 2. Concurrent distinct HWIDs racing for the LAST remaining slot (limit = 2, 1 already bound)
        $slotFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 2, hwidEnabled: true);
        $slotDelivery = $slotFixture['delivery'];
        $this->seedRegisteredHwidDevice($slotDelivery, 'already-bound-slot-1');
        $slotUri = '/api/v1/client/subscribe?token=' . $slotFixture['subscriber']->token;

        $slotResults = $this->runConcurrentSubscribeWorkers([
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $slotUri,
                'remote_addr' => '198.51.100.51',
                'headers' => [
                    'x-hwid' => 'race-distinct-slot-a',
                    'User-Agent' => 'ClashMeta/SlotA',
                ],
                'barriers' => [
                    'after_hwid_left_join' => ['sleep_ms' => 0],
                ],
            ],
            [
                'db_path' => $dbPath,
                'redis_host' => self::$redisServer->host(),
                'redis_port' => self::$redisServer->port(),
                'hwid_async_metadata' => true,
                'uri' => $slotUri,
                'remote_addr' => '198.51.100.52',
                'headers' => [
                    'x-hwid' => 'race-distinct-slot-b',
                    'User-Agent' => 'ClashMeta/SlotB',
                ],
                'barriers' => [
                    'after_hwid_left_join' => ['sleep_ms' => 0],
                ],
            ],
        ]);

        $statuses = [$slotResults[0]['status'], $slotResults[1]['status']];
        sort($statuses);
        $this->assertSame([200, 404], $statuses);

        $loser = $slotResults[0]['status'] === 404 ? $slotResults[0] : $slotResults[1];
        $this->assertSame('true', $loser['headers']['x-hwid-max-devices-reached']);
        $this->assertSame('true', $loser['headers']['x-hwid-limit']);

        $this->assertSame(
            2,
            DistributorHwidDevice::query()->where('distributor_order_id', $slotDelivery->id)->count()
        );
    }
}
