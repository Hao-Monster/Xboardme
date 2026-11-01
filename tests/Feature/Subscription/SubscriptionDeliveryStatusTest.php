<?php

namespace Tests\Feature\Subscription;

use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Services\Plugin\HookManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionDeliveryStatusTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_s01_delivery_write_sql_counts_and_timestamps_across_lifecycle(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];

        // Pre-seed device so we isolate delivery SQL writes cleanly.
        $this->seedRegisteredHwidDevice($delivery, 's01-device-001');

        // 1. PENDING + config_issued_at=null first successful download -> exactly 2 delivery UPDATEs
        $baseTs = time();
        Carbon::setTestNow(Carbon::createFromTimestamp($baseTs + 10));
        $firstRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => 's01-device-001',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
                'REMOTE_ADDR' => '198.51.100.11',
            ]
        ));
        $firstRun['result']->assertOk();
        $firstDeliveryWrites = $this->filterWriteQueriesForTable($firstRun['queries'], 'v2_distributor_order');
        $this->assertCount(2, $firstDeliveryWrites);
        $this->assertStringContainsString('claimed_at', strtolower($firstDeliveryWrites[0]));
        $this->assertStringContainsString('config_issued_at', strtolower($firstDeliveryWrites[1]));

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNotNull($delivery->claimed_at);
        $this->assertNotNull($delivery->config_issued_at);
        $firstClaimedAt = (int) $delivery->claimed_at;
        $firstIssuedAt = (int) $delivery->config_issued_at;
        $settledUpdatedAt = (int) $delivery->getRawOriginal('updated_at');

        // 2. Settled CLAIMED + config_issued_at!=null repeat download -> 0 delivery UPDATE SQLs & updated_at unchanged
        Carbon::setTestNow(Carbon::createFromTimestamp($baseTs + 900));
        $repeatRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => 's01-device-001',
                'User-Agent' => 'ClashMetaForAndroid/2.11.0',
                'REMOTE_ADDR' => '198.51.100.99',
            ]
        ));
        $repeatRun['result']->assertOk();
        $this->assertCount(0, $this->filterWriteQueriesForTable($repeatRun['queries'], 'v2_distributor_order'));

        $delivery->refresh();
        $this->assertSame($firstClaimedAt, (int) $delivery->claimed_at);
        $this->assertSame($firstIssuedAt, (int) $delivery->config_issued_at);
        $this->assertSame($settledUpdatedAt, (int) $delivery->getRawOriginal('updated_at'));

        // 3. CLAIMED + config_issued_at=null recovery download -> exactly 1 delivery UPDATE SQL
        $recoveryFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $recoveryDelivery = $recoveryFixture['delivery'];
        $this->seedRegisteredHwidDevice($recoveryDelivery, 's01-recovery-dev');
        DB::table('v2_distributor_order')
            ->where('id', $recoveryDelivery->id)
            ->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1_760_000_200,
                'claim_ip' => '198.51.100.20',
                'claim_ua' => 'InitialClaimAgent/1.0',
                'config_issued_at' => null,
            ]);

        $recoveryRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $recoveryFixture['subscriber']->token,
            [
                'x-hwid' => 's01-recovery-dev',
                'User-Agent' => 'ClashMetaForAndroid/2.10.5',
            ]
        ));
        $recoveryRun['result']->assertOk();
        $recoveryWrites = $this->filterWriteQueriesForTable($recoveryRun['queries'], 'v2_distributor_order');
        $this->assertCount(1, $recoveryWrites);
        $this->assertStringContainsString('config_issued_at', strtolower($recoveryWrites[0]));
        $this->assertStringNotContainsString('claimed_at', strtolower($recoveryWrites[0]));

        $recoveryDelivery->refresh();
        $this->assertSame(1_760_000_200, (int) $recoveryDelivery->claimed_at);
        $this->assertSame('198.51.100.20', $recoveryDelivery->claim_ip);
        $this->assertSame('InitialClaimAgent/1.0', $recoveryDelivery->claim_ua);
        $this->assertNotNull($recoveryDelivery->config_issued_at);

        // 4. CLOSED delivery & non-distributor subscriber -> 0 delivery UPDATE SQLs
        $closedFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: false);
        DB::table('v2_distributor_order')
            ->where('id', $closedFixture['delivery']->id)
            ->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLOSED,
                'closed_at' => 1_760_000_300,
                'config_issued_at' => null,
            ]);

        $closedRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $closedFixture['subscriber']->token,
            ['User-Agent' => 'ClashMetaForAndroid/2.10.5']
        ));
        $closedRun['result']->assertOk();
        $this->assertCount(0, $this->filterWriteQueriesForTable($closedRun['queries'], 'v2_distributor_order'));

        $ordinaryPlan = $this->makeSubscriptionPlan();
        $ordinaryUser = $this->makeOrdinarySubscriber($ordinaryPlan);
        $ordinaryRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $ordinaryUser->token,
            ['User-Agent' => 'ClashMetaForAndroid/2.10.5']
        ));
        $ordinaryRun['result']->assertOk();
        $this->assertCount(0, $this->filterWriteQueriesForTable($ordinaryRun['queries'], 'v2_distributor_order'));
    }

    public function test_s03_empty_config_claims_without_issuing_and_subsequent_usable_download_issues_once(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);

        // Start with NO servers -> General protocol returns empty base64("") -> not usable
        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $this->seedRegisteredHwidDevice($delivery, 's03-device-001');

        $emptyRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => 's03-device-001',
                'User-Agent' => 'Karing/1.2.22.2502 Android',
                'REMOTE_ADDR' => '198.51.100.31',
            ]
        ));
        $emptyRun['result']->assertOk();
        $emptyWrites = $this->filterWriteQueriesForTable($emptyRun['queries'], 'v2_distributor_order');
        $this->assertCount(1, $emptyWrites);
        $this->assertStringContainsString('claimed_at', strtolower($emptyWrites[0]));

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNotNull($delivery->claimed_at);
        $this->assertNull($delivery->config_issued_at);

        // Distributor recovery query still sees CLAIMED + config_issued_at=null
        Sanctum::actingAs($fixture['distributor']);
        $this->getJson('/api/v1/user/distributor/delivery')
            ->assertOk()
            ->assertJsonPath('data.trade_no', $fixture['order']->trade_no);
        $this->app['auth']->forgetGuards();
        \Illuminate\Support\Facades\Auth::shouldUse((string) config('auth.defaults.guard'));

        // Now add an online server -> usable config -> issues once (1 write)
        $this->makeOnlineServer();
        $usableRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => 's03-device-001',
                'User-Agent' => 'Karing/1.2.22.2502 Android',
            ]
        ));
        $usableRun['result']->assertOk();
        $usableWrites = $this->filterWriteQueriesForTable($usableRun['queries'], 'v2_distributor_order');
        $this->assertCount(1, $usableWrites);
        $this->assertStringContainsString('config_issued_at', strtolower($usableWrites[0]));

        $delivery->refresh();
        $issuedAt = (int) $delivery->config_issued_at;
        $this->assertGreaterThan(0, $issuedAt);

        // Third request -> 0 writes
        $thirdRun = $this->captureQueries(fn () => $this->get(
            '/api/v1/client/subscribe?token=' . $subscriber->token,
            [
                'x-hwid' => 's03-device-001',
                'User-Agent' => 'Karing/1.2.22.2502 Android',
            ]
        ));
        $thirdRun['result']->assertOk();
        $this->assertCount(0, $this->filterWriteQueriesForTable($thirdRun['queries'], 'v2_distributor_order'));
        $this->assertSame($issuedAt, (int) $delivery->fresh()->config_issued_at);
    }

    public function test_s04_close_wins_does_not_revive_closed_and_claim_wins_blocks_close(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        // Case 1: Close wins AFTER initial PENDING snapshot is loaded in subscribe()!
        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $this->seedRegisteredHwidDevice($delivery, 's04-device-001');

        $hookActive = true;
        $closedInjected = false;
        DB::beforeExecuting(function (string $sql) use ($delivery, &$hookActive, &$closedInjected): void {
            if (!$hookActive) {
                return;
            }
            $lower = strtolower($sql);
            if (!$closedInjected
                && str_contains($lower, 'update')
                && str_contains($lower, 'v2_distributor_order')
                && str_contains($lower, 'claimed_at')
            ) {
                $closedInjected = true;
                DB::table('v2_distributor_order')
                    ->where('id', $delivery->id)
                    ->where('delivery_status', DistributorOrder::DELIVERY_PENDING)
                    ->update([
                        'delivery_status' => DistributorOrder::DELIVERY_CLOSED,
                        'closed_at' => 1_760_000_500,
                        'claim_token' => null,
                    ]);
            }
        });

        try {
            $response = $this->get(
                '/api/v1/client/subscribe?token=' . $subscriber->token,
                [
                    'x-hwid' => 's04-device-001',
                    'User-Agent' => 'ClashMetaForAndroid/2.10.1',
                ]
            );
        } finally {
            $hookActive = false;
        }

        // HTTP contract remains 200 and CLOSED is never revived or marked config_issued_at
        $response->assertOk();
        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLOSED, $delivery->delivery_status);
        $this->assertNull($delivery->claimed_at);
        $this->assertNull($delivery->config_issued_at);

        // Case 2: Claim wins -> Distributor close endpoint cannot overwrite CLAIMED into CLOSED
        $claimedFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $this->seedRegisteredHwidDevice($claimedFixture['delivery'], 's04-claimed-dev');
        $this->get(
            '/api/v1/client/subscribe?token=' . $claimedFixture['subscriber']->token,
            [
                'x-hwid' => 's04-claimed-dev',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ]
        )->assertOk();

        Sanctum::actingAs($claimedFixture['distributor']);
        $this->postJson('/api/v1/user/distributor/delivery/close', [
            'trade_no' => $claimedFixture['order']->trade_no,
            'confirm' => true,
        ])->assertOk();

        $claimedDelivery = $claimedFixture['delivery']->fresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $claimedDelivery->delivery_status);
        $this->assertNull($claimedDelivery->closed_at);
    }

    public function test_s05_head_prefetch_auth_failure_empty_and_protocol_exception_preserve_contracts(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $server = $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];

        // 1. HEAD -> 405, zero device bind, zero claim/issue
        $this->call('HEAD', '/api/v1/client/subscribe?token=' . $subscriber->token, [], [], [], [
            'HTTP_X_HWID' => 's05-head-dev',
            'HTTP_USER_AGENT' => 'ClashMeta/1.0',
        ])->assertStatus(405);
        $this->assertSame(DistributorOrder::DELIVERY_PENDING, $delivery->fresh()->delivery_status);
        $this->assertSame(0, $delivery->hwidDevices()->count());

        // 2. Prefetch -> 425, zero device bind, zero claim/issue
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'Sec-Purpose' => 'prefetch',
            'x-hwid' => 's05-prefetch-dev',
            'User-Agent' => 'ClashMeta/1.0',
        ])->assertStatus(425);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'Purpose' => 'prefetch',
            'x-hwid' => 's05-prefetch-dev',
            'User-Agent' => 'ClashMeta/1.0',
        ])->assertStatus(425);
        $this->assertSame(DistributorOrder::DELIVERY_PENDING, $delivery->fresh()->delivery_status);
        $this->assertSame(0, $delivery->hwidDevices()->count());

        // 3. Invalid token -> 403/422 without binding or claiming
        $this->getJson('/api/v1/client/subscribe?token=nonexistent-invalid-token', [
            'x-hwid' => 's05-bad-token',
        ])->assertStatus(403);

        // 4. Expired/banned subscriber -> 403 without binding or claiming
        $subscriber->update(['banned' => true]);
        $this->getJson('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 's05-banned-dev',
            'User-Agent' => 'ClashMeta/1.0',
        ])->assertStatus(403);
        $this->assertSame(DistributorOrder::DELIVERY_PENDING, $delivery->fresh()->delivery_status);
        $this->assertSame(0, $delivery->hwidDevices()->count());
        $subscriber->update(['banned' => false]);

        // 5. Protocol exception on PENDING subscription -> claims delivery, does NOT set config_issued_at
        HookManager::registerFilter('client.subscribe.servers', static function (): array {
            throw new \RuntimeException('simulated protocol generation failure');
        });
        try {
            $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
                'x-hwid' => 's05-valid-dev',
                'User-Agent' => 'ClashMeta/1.0',
            ])->assertStatus(500);
        } finally {
            HookManager::remove('client.subscribe.servers');
        }

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNull($delivery->config_issued_at);

        // 6. Normal request issues config_issued_at; subsequent empty/exception NEVER clears config_issued_at
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 's05-valid-dev',
            'User-Agent' => 'ClashMeta/1.0',
        ])->assertOk();

        $delivery->refresh();
        $firstIssuedAt = (int) $delivery->config_issued_at;
        $this->assertGreaterThan(0, $firstIssuedAt);

        Server::query()->whereKey($server->id)->update(['show' => false]);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 's05-valid-dev',
            'User-Agent' => 'Karing/1.2.22.2502 Android',
        ])->assertOk();
        $this->assertSame($firstIssuedAt, (int) $delivery->fresh()->config_issued_at);
    }

    public function test_s06_renewal_settlement_and_subscription_name_headers_remain_compatible_without_get_backfill(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true, customerName: '财务兼容客户');
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $order = $fixture['order'];
        $this->seedRegisteredHwidDevice($delivery, 's06-device-001');

        // First download sets claimed_at & config_issued_at and returns new subscription_name headers
        $firstResp = $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 's06-device-001',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        $delivery->refresh();
        $originalClaimedAt = (int) $delivery->claimed_at;
        $originalIssuedAt = (int) $delivery->config_issued_at;
        $subscriptionName = (string) $delivery->subscription_name;
        $this->assertNotSame('', $subscriptionName);
        $this->assertSame('base64:' . base64_encode($subscriptionName), $firstResp->headers->get('profile-title'));
        $this->assertSame($order->trade_no, $firstResp->headers->get('x-order-no'));
        $this->assertStringContainsString(
            "filename*=UTF-8''" . rawurlencode($subscriptionName),
            (string) $firstResp->headers->get('content-disposition')
        );

        // Simulate settled financial state + renewal
        DB::table('v2_distributor_order')
            ->where('id', $delivery->id)
            ->update([
                'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
                'settled_at' => 1_760_001_000,
                'settled_by' => 99,
            ]);

        Sanctum::actingAs($fixture['distributor']);
        $this->postJson('/api/v1/user/order/renew', [
            'trade_no' => $order->trade_no,
            'period' => 'month_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614174999',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        \Illuminate\Support\Facades\Auth::shouldUse((string) config('auth.defaults.guard'));

        // Repeat subscription download does NOT touch settlement fields or reset claimed_at / config_issued_at
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 's06-device-001',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        $delivery->refresh();
        $this->assertSame(DistributorOrder::SETTLEMENT_SETTLED, $delivery->settlement_status);
        $this->assertSame(1_760_001_000, (int) $delivery->settled_at);
        $this->assertSame(99, (int) $delivery->settled_by);
        $this->assertSame($originalClaimedAt, (int) $delivery->claimed_at);
        $this->assertSame($originalIssuedAt, (int) $delivery->config_issued_at);
        $this->assertSame(2, Order::query()->where('distributor_order_id', $delivery->id)->count());

        // Legacy subscription with subscription_name = null -> falls back to app_name, does NOT backfill on GET
        $legacyFixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: false);
        DB::table('v2_distributor_order')
            ->where('id', $legacyFixture['delivery']->id)
            ->update([
                'subscription_name' => null,
                'subscription_code' => null,
            ]);

        $expectedLegacyTitle = '订单号：' . $legacyFixture['order']->trade_no;
        $legacyResp = $this->get('/api/v1/client/subscribe?token=' . $legacyFixture['subscriber']->token, [
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        $this->assertSame('base64:' . base64_encode($expectedLegacyTitle), $legacyResp->headers->get('profile-title'));
        $this->assertSame($legacyFixture['order']->trade_no, $legacyResp->headers->get('x-order-no'));
        $this->assertNull($legacyFixture['delivery']->fresh()->subscription_name);
    }
}
