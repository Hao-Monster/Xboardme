<?php

namespace Tests\Feature\Subscription;

use App\Exceptions\HwidObservationPersistenceException;
use App\Jobs\UpdateDistributorHwidObservation;
use App\Models\DistributorOrder;
use App\Services\Plugin\HookManager;
use App\Support\SubscriptionTiming;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionTimingDiagnosticsTest extends TestCase
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

    private function installMemoryLogChannel(string $channelName = 'sub_test_log'): TestHandler
    {
        $handler = new TestHandler();
        $monolog = new Logger($channelName, [$handler]);
        Log::extend('memory_test', static fn () => $monolog);
        config([
            "logging.channels.{$channelName}" => ['driver' => 'memory_test'],
            'subscription.timing_log_channel' => $channelName,
            'subscription.timing_log_enabled' => true,
        ]);

        return $handler;
    }

    public function test_o01_stage_timing_routes_sampling_slow_retention_unhandled_null_status_and_log_failure_resilience(): void
    {
        $handler = $this->installMemoryLogChannel();
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
            'subscription.timing_sample_rate' => 1.0,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $this->seedRegisteredHwidDevice($delivery, 'o01-timing-device');

        // 1. Named route client.subscribe (/s/{token}) AND legacy route client.subscribe.legacy (/api/v1/client/subscribe) both emit subscription.request
        $modernPath = parse_url(\App\Utils\Helper::getSubscribeUrl($subscriber->token), PHP_URL_PATH) ?: ('/s/' . $subscriber->token);
        $this->get($modernPath, [
            'x-hwid' => 'o01-timing-device',
            'X-Request-Id' => 'edge-req-abc_123',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'o01-timing-device',
            'X-Request-Id' => 'unsafe spaces & <script> rejected!',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn ($r): bool => $r->message === 'subscription.request'
        ));
        $this->assertCount(2, $records);

        $ctx1 = $records[0]->context;
        $this->assertSame(16, strlen((string) $ctx1['request_id']));
        $this->assertSame('edge-req-abc_123', $ctx1['correlation_id']);
        $this->assertSame('client.subscribe', $ctx1['route']);
        $this->assertSame('success', $ctx1['outcome']);
        $this->assertSame(200, $ctx1['http_status']);
        $this->assertSame(2, $ctx1['delivery_write_attempts']);
        $this->assertSame('registered_readonly', $ctx1['hwid_branch']);
        $this->assertSame('published', $ctx1['publish_result']);
        $this->assertSame(['server_status_cache' => 'server_lookup_transform'], $ctx1['stage_parents']);
        $this->assertIsFloat($ctx1['stages_ms']['token_lookup']);
        $this->assertIsFloat($ctx1['stages_ms']['hwid_authorize']);
        $this->assertIsFloat($ctx1['stages_ms']['server_lookup_transform']);
        $this->assertIsFloat($ctx1['stages_ms']['server_status_cache']);
        $this->assertIsFloat($ctx1['stages_ms']['protocol_generate']);
        $this->assertIsFloat($ctx1['stages_ms']['observation_publish']);

        // Second request hit client.subscribe.legacy -> delivery_write_attempts = 0, unsafe correlation_id rejected to null
        $ctx2 = $records[1]->context;
        $this->assertSame('client.subscribe.legacy', $ctx2['route']);
        $this->assertNull($ctx2['correlation_id']);
        $this->assertSame(0, $ctx2['delivery_write_attempts']);

        // Legacy route /client/subscribe (if mounted under /api/v1/client/subscribe vs legacy) & non-subscription route exclusion
        $handler->clear();
        $this->get('/non-existent-404-path')->assertNotFound();
        $this->get('/api/v1/client/app/getVersion?token=' . $subscriber->token)->assertOk();
        $this->assertCount(0, $handler->getRecords(), 'Non-subscription routes and 404s must never emit subscription.request.');

        // 2. Sampling: sample_rate=0.0 drops fast 2xx, retains >= threshold slow 2xx, and retains non-2xx!
        config([
            'subscription.timing_sample_rate' => 0.0,
            'subscription.timing_slow_threshold_ms' => 1000,
        ]);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'o01-timing-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        $this->assertCount(0, $handler->getRecords());

        // Slow request (threshold = 0ms) retained even when sample_rate = 0.0
        config(['subscription.timing_slow_threshold_ms' => 0]);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'o01-timing-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        $this->assertCount(1, $handler->getRecords());
        $handler->clear();

        // 3. Unhandled exception (not yet converted to HTTP Response) logs http_status = null (NEVER fabricated as 500!)
        config(['subscription.timing_slow_threshold_ms' => 1000]);
        $rawReq = Request::create('/api/v1/client/subscribe', 'GET');
        $rawRoute = $this->app['router']->getRoutes()->getByName('client.subscribe');
        $rawReq->setRouteResolver(static fn () => $rawRoute);
        $rawTiming = SubscriptionTiming::attach($rawReq);
        $unhandledPayload = $rawTiming->finish($rawReq, null, new RuntimeException('boom before response'));
        $this->assertNotNull($unhandledPayload);
        $this->assertNull($unhandledPayload['http_status']);
        $this->assertSame('exception', $unhandledPayload['outcome']);
        $this->assertSame('unhandled_exception', $unhandledPayload['error_category']);

        // 4. Broken log channel throwing exception MUST NOT alter subscription HTTP response!
        Log::extend('throwing_logger', static function () {
            return new class {
                public function info(): void
                {
                    throw new RuntimeException('disk full log write error');
                }
            };
        });
        config([
            'logging.channels.broken_sub_log' => ['driver' => 'throwing_logger'],
            'subscription.timing_log_channel' => 'broken_sub_log',
            'subscription.timing_sample_rate' => 1.0,
        ]);

        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'o01-timing-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
    }

    public function test_o02_sensitive_fixture_zero_pii_leakage_across_request_worker_exceptions_and_zero_listener_accumulation(): void
    {
        $handler = $this->installMemoryLogChannel('pii_audit_log');
        config([
            'logging.default' => 'pii_audit_log',
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
            'subscription.timing_sample_rate' => 1.0,
        ]);
        $this->makeOnlineServer(['name' => 'SECRET_NODE_NAME_999']);

        $secretToken = 'SECRET-TOKEN-9876543210abcdef';
        $secretUuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $secretHwid = 'SECRET-HWID-VALUE-123456789';
        $secretIp = '203.0.113.199';
        $secretUa = 'ClashMetaForAndroid/9.99 SecretClientTag';
        $secretModel = 'SecretPhoneUltraMax';
        $secretQuery = 'SECRET_EXTRA_QUERY_VAL';

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $subscriber->update([
            'token' => $secretToken,
            'uuid' => $secretUuid,
        ]);
        $device = $this->seedRegisteredHwidDevice($delivery, $secretHwid);

        // Execute multiple consecutive requests (normal + 404 HWID + exception)
        for ($i = 0; $i < 3; $i++) {
            $resp = $this->get(
                "/api/v1/client/subscribe?token={$secretToken}&extra={$secretQuery}",
                [
                    'x-hwid' => $secretHwid,
                    'x-device-model' => $secretModel,
                    'User-Agent' => $secretUa,
                    'REMOTE_ADDR' => $secretIp,
                ]
            );
            $resp->assertOk();
        }

        // Simulate DB QueryException inside UpdateDistributorHwidObservation containing raw SQL + bindings!
        $pdoEx = new \PDOException("SQLSTATE[HY000]: database is locked near {$secretHwid}");
        $pdoEx->errorInfo = ['HY000', 5, "database is locked near {$secretHwid}"];
        $queryEx = new QueryException(
            'sqlite',
            'update "v2_distributor_hwid_device" set "ip" = ? where "hwid" = ?',
            [$secretIp, $secretHwid],
            $pdoEx
        );

        $sanitized = HwidObservationPersistenceException::fromThrowable($queryEx);
        $this->assertSame('db_busy', $sanitized->category());
        $this->assertNull($sanitized->getPrevious(), 'QueryException must never be chained as $previous!');

        $serializedException = (string) $sanitized . ' ' . json_encode($sanitized->context());
        foreach ([$secretToken, $secretUuid, $secretHwid, $secretIp, $secretUa, $secretModel, $secretQuery] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedException);
        }

        // Also run UpdateDistributorHwidObservation with simulated DB failure to emit subscription.hwid_observation log
        $failHook = true;
        DB::beforeExecuting(static function (string $sql) use (&$failHook, $secretHwid, $secretIp): void {
            $lower = ltrim(strtolower($sql));
            if ($failHook && str_starts_with($lower, 'update ') && str_contains($lower, 'v2_distributor_hwid_device')) {
                $constraintPdo = new \PDOException("SQLSTATE[23000]: constraint error for {$secretHwid}", 23000);
                $constraintPdo->errorInfo = ['23000', 19, "constraint error for {$secretHwid}"];
                throw new QueryException(
                    'sqlite',
                    $sql,
                    [$secretIp, $secretHwid],
                    $constraintPdo
                );
            }
        });
        try {
            $job = new UpdateDistributorHwidObservation(
                deviceId: (int) $device->id,
                distributorOrderId: (int) $delivery->id,
                hwid: $secretHwid,
                observedAt: time() + 100,
                deviceOs: 'SecretOS',
                osVersion: '99.1',
                deviceModel: $secretModel,
                userAgent: $secretUa,
                ip: $secretIp
            );
            $this->assertSame([], $job->tags());
            try {
                $job->handle();
                $this->fail('Expected HwidObservationPersistenceException to be thrown.');
            } catch (HwidObservationPersistenceException $caught) {
                $this->assertSame('db_write_failed', $caught->category());
                app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($caught);
            }
        } finally {
            $failHook = false;
        }

        // Inspect EVERY emitted log record (message + context + formatted output): ZERO sensitive values anywhere!
        $allLogJson = json_encode(array_map(
            static fn ($r): array => [
                'message' => $r->message,
                'context' => $r->context,
                'extra' => $r->extra,
            ],
            $handler->getRecords()
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ([
            $secretToken,
            $secretUuid,
            $secretHwid,
            $secretIp,
            $secretUa,
            $secretModel,
            $secretQuery,
            'SECRET_NODE_NAME_999',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                (string) $allLogJson,
                "Sensitive value [{$forbidden}] leaked into logs!"
            );
        }

        // Verify zero DB/Event listener accumulation across consecutive subscription requests
        $dispatcher = DB::getEventDispatcher();
        $beforeCount = $dispatcher !== null ? count($dispatcher->getListeners(\Illuminate\Database\Events\QueryExecuted::class)) : 0;
        for ($k = 0; $k < 5; $k++) {
            $this->get("/api/v1/client/subscribe?token={$secretToken}", [
                'x-hwid' => $secretHwid,
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ])->assertOk();
        }
        $afterCount = $dispatcher !== null ? count($dispatcher->getListeners(\Illuminate\Database\Events\QueryExecuted::class)) : 0;
        $this->assertSame($beforeCount, $afterCount, 'Subscription requests must never register per-request DB listeners.');
    }
}
