<?php

namespace Tests\Feature\Subscription;

use App\Jobs\UpdateDistributorHwidObservation;
use App\Models\DistributorOrder;
use App\Queue\HwidObservationRedisQueue;
use App\Services\DistributorHwidObservationPublisher;
use App\Support\HwidObservationConfigValidator;
use App\Support\SubscriptionTiming;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\RedisQueue as FrameworkRedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Horizon\RedisQueue as HorizonRedisQueue;
use Laravel\Horizon\WaitTimeCalculator;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionObservationQueueTest extends TestCase
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
        config(['queue.failed.database' => config('database.default')]);
        $this->app->forgetInstance('queue.failer');
        self::$redisServer?->setFaultMode('NONE', 0);
        self::$redisServer?->configureApplication($this->app);
        self::$redisServer?->flushAll();
        self::$redisServer?->resetCommandLog();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        self::$redisServer?->setFaultMode('NONE', 0);
        parent::tearDown();
    }

    public function test_q01_explicit_publish_connection_pushes_to_real_redis_even_when_default_queue_is_sync(): void
    {
        config([
            'queue.default' => 'sync',
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $device = $this->seedRegisteredHwidDevice($delivery, 'q01-sync-bypass-dev', [
            'last_seen_at' => 1_760_000_000,
        ]);

        DB::table('v2_distributor_order')
            ->where('id', $delivery->id)
            ->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1_760_000_000,
                'config_issued_at' => 1_760_000_000,
            ]);

        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'q01-sync-bypass-dev',
            'x-device-model' => 'QueuedModel',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();

        // Web request MUST NOT run UpdateDistributorHwidObservation::handle() synchronously
        $this->assertSame(1_760_000_000, (int) $device->fresh()->last_seen_at);

        /** @var HwidObservationRedisQueue $workerQueue */
        $workerQueue = Queue::connection('hwid_observation_worker');
        $this->assertSame(1, $workerQueue->pendingSize('hwid_observation'));
    }

    public function test_q02_hwid_observation_redis_queue_ready_now_horizon_zero_publish_metadata_and_worker_lifecycle(): void
    {
        /** @var HwidObservationRedisQueue $publishQueue */
        $publishQueue = Queue::connection('hwid_observation_publish');
        /** @var HwidObservationRedisQueue $workerQueue */
        $workerQueue = Queue::connection('hwid_observation_worker');

        $this->assertInstanceOf(HwidObservationRedisQueue::class, $publishQueue);
        $this->assertInstanceOf(FrameworkRedisQueue::class, $publishQueue);
        $this->assertNotInstanceOf(HorizonRedisQueue::class, $publishQueue);

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $device = $this->seedRegisteredHwidDevice($delivery, 'q02-worker-dev', [
            'last_seen_at' => 1_760_000_000,
            'device_model' => 'BeforeWorker',
        ]);

        self::$redisServer->resetCommandLog();

        $publisher = app(DistributorHwidObservationPublisher::class);
        $outcome = $publisher->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q02-worker-dev',
            'observed_at' => 1_760_001_234,
            'device_os' => 'Android',
            'os_version' => '15',
            'device_model' => 'AfterWorker',
            'user_agent' => 'SingBox/1.10',
            'ip' => '198.51.100.88',
        ]);

        $this->assertSame(DistributorHwidObservationPublisher::RESULT_PUBLISHED, $outcome['result']);
        $this->assertSame(1, $workerQueue->readyNow('hwid_observation'));
        $this->assertSame(1, $workerQueue->pendingSize('hwid_observation'));

        // Zero Horizon JobPending / JobPushed metadata I/O during producer push!
        $commands = self::$redisServer->commandLog();
        foreach ($commands as $cmdLine) {
            $this->assertStringNotContainsString('_horizon:', $cmdLine);
        }

        // WaitTimeCalculator compatibility check
        config(['horizon.environments.testing' => [
            'hwid-observation' => [
                'connection' => 'hwid_observation_worker',
                'queue' => ['hwid_observation'],
                'balance' => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 1,
                'timeout' => 45,
            ],
        ]]);
        $waitTimes = app(WaitTimeCalculator::class)->calculate();
        $this->assertIsArray($waitTimes);

        // Consume via real Laravel queue:work command on hwid_observation_worker
        $this->artisan('queue:work', [
            'connection' => 'hwid_observation_worker',
            '--queue' => 'hwid_observation',
            '--once' => true,
            '--tries' => 3,
        ])->assertExitCode(0);

        $this->assertSame(0, $workerQueue->readyNow('hwid_observation'));
        $device->refresh();
        $this->assertSame(1_760_001_234, (int) $device->last_seen_at);
        $this->assertSame('AfterWorker', $device->device_model);
    }

    public function test_q03_real_tcp_redis_refused_blackhole_ack_lost_and_horizon_metadata_faults_are_bounded_and_isolated(): void
    {
        config([
            'subscription.hwid_observation_worker_enabled' => true,
            'subscription.hwid_async_metadata' => true,
        ]);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $subscriber = $fixture['subscriber'];
        $device = $this->seedRegisteredHwidDevice($delivery, 'q03-fault-device', [
            'last_seen_at' => 1_760_000_000,
        ]);

        DB::table('v2_distributor_order')
            ->where('id', $delivery->id)
            ->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1_760_000_000,
                'config_issued_at' => 1_760_000_000,
            ]);

        // Warm template cache so subscription response itself does not miss SubscribeTemplate cache
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'q03-fault-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        self::$redisServer->controlCommand(['DEL', 'xboard_test_database_queues:hwid_observation']);

        // 1. BLACKHOLE (server stalls 1500ms, publish socket read_timeout=0.10s bounds publish < 1000ms!)
        self::$redisServer->setFaultMode('BLACKHOLE', 1500);
        $startedNs = hrtime(true);
        $reqBlackhole = Request::create('/api/v1/client/subscribe', 'GET');
        $timingBlackhole = SubscriptionTiming::attach($reqBlackhole);
        $outBlackhole = app(DistributorHwidObservationPublisher::class)->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q03-fault-device',
            'observed_at' => 1_760_002_000,
        ], $timingBlackhole);
        $elapsedMs = (hrtime(true) - $startedNs) / 1_000_000;

        $this->assertLessThan(1000.0, $elapsedMs);
        $this->assertContains($outBlackhole['result'], [
            DistributorHwidObservationPublisher::RESULT_FAILED,
            DistributorHwidObservationPublisher::RESULT_UNKNOWN,
        ]);
        $this->assertSame(1_760_000_000, (int) $device->fresh()->last_seen_at);
        self::$redisServer->setFaultMode('NONE', 0);

        // 2. DROP_AFTER_EXEC (command writes to Redis, socket closes before ACK -> unknown, NO duplicate push, worker still consumes!)
        self::$redisServer->controlCommand(['DEL', 'xboard_test_database_queues:hwid_observation']);
        self::$redisServer->setFaultMode('DROP_AFTER_EXEC', 0);

        $outAckLost = app(DistributorHwidObservationPublisher::class)->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q03-fault-device',
            'observed_at' => 1_760_003_000,
            'device_model' => 'AckLostRecovered',
        ]);
        $this->assertSame(DistributorHwidObservationPublisher::RESULT_UNKNOWN, $outAckLost['result']);
        $this->assertSame('redis_ack_lost', $outAckLost['error_category']);

        self::$redisServer->setFaultMode('NONE', 0);
        /** @var HwidObservationRedisQueue $workerQueue */
        $workerQueue = Queue::connection('hwid_observation_worker');
        $this->assertSame(1, $workerQueue->pendingSize('hwid_observation'));

        $this->artisan('queue:work', [
            'connection' => 'hwid_observation_worker',
            '--queue' => 'hwid_observation',
            '--once' => true,
            '--tries' => 3,
        ])->assertExitCode(0);
        $this->assertSame(1_760_003_000, (int) $device->fresh()->last_seen_at);
        $this->assertSame('AckLostRecovered', $device->fresh()->device_model);

        // 3. ERROR_ON_HORIZON (metadata Redis failure does not affect producer or HTTP 200)
        self::$redisServer->setFaultMode('ERROR_ON_HORIZON', 0);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'q03-fault-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        self::$redisServer->setFaultMode('NONE', 0);

        // 4. Connection refused on hwid_observation_publish ONLY (cache Redis remains up -> HTTP 200 unaffected, 0 sync DB fallback!)
        $unusedSock = stream_socket_server('tcp://127.0.0.1:0');
        $unusedName = stream_socket_get_name($unusedSock, false);
        fclose($unusedSock);
        $unusedPort = (int) substr((string) $unusedName, strrpos((string) $unusedName, ':') + 1);

        config([
            'database.redis.hwid_observation_publish.port' => $unusedPort,
            'database.redis.hwid_observation_publish.timeout' => 0.10,
            'database.redis.default.port' => $unusedPort,
        ]);
        LoopbackRespRedisServer::rebindRedisManager($this->app);

        $beforeSeen = (int) $device->fresh()->last_seen_at;
        $refusedStartedNs = hrtime(true);
        $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
            'x-hwid' => 'q03-fault-device',
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        $refusedMs = (hrtime(true) - $refusedStartedNs) / 1_000_000;
        $this->assertLessThan(1000.0, $refusedMs);
        $this->assertSame($beforeSeen, (int) $device->fresh()->last_seen_at);
    }

    public function test_q04_reserved_recovery_retry_exhaustion_status_command_and_scoped_7day_failure_pruning(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
        $delivery = $fixture['delivery'];
        $device = $this->seedRegisteredHwidDevice($delivery, 'q04-recovery-dev', [
            'last_seen_at' => 1_760_000_000,
        ]);

        // 1. Simulate worker crash after pop() reserves job -> recovered after retry_after (90s > 45s timeout)
        $baseNow = Carbon::now();
        Carbon::setTestNow($baseNow);

        app(DistributorHwidObservationPublisher::class)->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q04-recovery-dev',
            'observed_at' => 1_760_010_000,
            'device_model' => 'RecoveredAfterCrash',
        ]);

        /** @var HwidObservationRedisQueue $workerQueue */
        $workerQueue = Queue::connection('hwid_observation_worker');
        $reservedJob = $workerQueue->pop('hwid_observation');
        $this->assertNotNull($reservedJob);
        $this->assertSame(0, $workerQueue->pendingSize('hwid_observation'));
        $this->assertSame(1, $workerQueue->reservedSize('hwid_observation'));

        // Before retry_after (90s), another worker pop() gets null
        Carbon::setTestNow($baseNow->copy()->addSeconds(50));
        $this->assertNull($workerQueue->pop('hwid_observation'));

        // After retry_after (95s > 90s), expired reserved job migrates back and executes idempotently
        Carbon::setTestNow($baseNow->copy()->addSeconds(95));
        $this->artisan('queue:work', [
            'connection' => 'hwid_observation_worker',
            '--queue' => 'hwid_observation',
            '--once' => true,
            '--tries' => 3,
        ])->assertExitCode(0);

        $this->assertSame(1_760_010_000, (int) $device->fresh()->last_seen_at);
        $this->assertSame('RecoveredAfterCrash', $device->fresh()->device_model);
        Carbon::setTestNow();

        // 2. subscription:hwid-queue-status on empty, non-empty, corrupt payload, and unreachable Redis
        $exitEmpty = Artisan::call('subscription:hwid-queue-status', [
            '--require-empty' => true,
            '--samples' => 3,
            '--interval' => 0,
        ]);
        $this->assertSame(0, $exitEmpty);
        $emptyJson = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $emptyJson['status']);
        $this->assertNull($emptyJson['oldest_ready_age_seconds']);
        $this->assertSame(3, $emptyJson['samples_checked']);

        app(DistributorHwidObservationPublisher::class)->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q04-recovery-dev',
            'observed_at' => 1_760_020_000,
        ]);
        $exitNonEmpty = Artisan::call('subscription:hwid-queue-status', [
            '--require-empty' => true,
            '--samples' => 3,
            '--interval' => 0,
        ]);
        $this->assertSame(1, $exitNonEmpty);
        $nonEmptyJson = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('not_empty', $nonEmptyJson['status']);
        $this->assertSame(1, $nonEmptyJson['ready']);
        $this->assertIsInt($nonEmptyJson['oldest_ready_age_seconds']);

        // Corrupt payload -> non-zero decode_failed without echoing raw secret payload
        self::$redisServer->controlCommand(['DEL', 'xboard_test_database_queues:hwid_observation']);
        Redis::connection('default')->rpush('queues:hwid_observation', 'CORRUPT_SECRET_PAYLOAD_999');
        $exitCorrupt = Artisan::call('subscription:hwid-queue-status');
        $corruptOut = trim(Artisan::output());
        $this->assertSame(1, $exitCorrupt);
        $this->assertStringNotContainsString('CORRUPT_SECRET_PAYLOAD_999', $corruptOut);
        $corruptJson = json_decode($corruptOut, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('decode_failed', $corruptJson['error_category']);
        self::$redisServer->controlCommand(['DEL', 'xboard_test_database_queues:hwid_observation']);

        // 3. Retry exhaustion (tries=3, backoff=[5,30]) into failed_jobs + subscription:hwid-failures-prune
        app(DistributorHwidObservationPublisher::class)->publish([
            'device_id' => (int) $device->id,
            'distributor_order_id' => (int) $delivery->id,
            'hwid' => 'q04-recovery-dev',
            'observed_at' => 1_760_030_000,
        ]);

        $failDbActive = true;
        DB::beforeExecuting(static function (string $sql) use (&$failDbActive): void {
            $lower = ltrim(strtolower($sql));
            if ($failDbActive && str_starts_with($lower, 'update ') && str_contains($lower, 'v2_distributor_hwid_device')) {
                $locked = new \PDOException('SQLSTATE[HY000]: database is locked');
                $locked->errorInfo = ['HY000', 5, 'database is locked'];
                throw $locked;
            }
        });
        try {
            $baseExhaust = Carbon::now();
            Carbon::setTestNow($baseExhaust);
            // Attempt 1 -> releases with backoff[0] = 5s
            $this->artisan('queue:work', [
                'connection' => 'hwid_observation_worker',
                '--queue' => 'hwid_observation',
                '--once' => true,
                '--tries' => 3,
            ])->assertExitCode(0);
            $this->assertSame(1, $workerQueue->delayedSize('hwid_observation'));

            // Attempt 2 after 6s -> releases with backoff[1] = 30s
            Carbon::setTestNow($baseExhaust->copy()->addSeconds(6));
            $this->artisan('queue:work', [
                'connection' => 'hwid_observation_worker',
                '--queue' => 'hwid_observation',
                '--once' => true,
                '--tries' => 3,
            ])->assertExitCode(0);
            $this->assertSame(1, $workerQueue->delayedSize('hwid_observation'));

            // Attempt 3 after 37s -> exhausts tries=3 and writes into failed_jobs!
            Carbon::setTestNow($baseExhaust->copy()->addSeconds(37));
            $this->artisan('queue:work', [
                'connection' => 'hwid_observation_worker',
                '--queue' => 'hwid_observation',
                '--once' => true,
                '--tries' => 3,
            ])->assertExitCode(0);
        } finally {
            $failDbActive = false;
            Carbon::setTestNow();
        }

        $this->assertSame(0, $workerQueue->pendingSize('hwid_observation'));
        $this->assertSame(0, $workerQueue->delayedSize('hwid_observation'));
        $this->assertSame(1, DB::table('failed_jobs')->where('queue', 'hwid_observation')->count());
        DB::table('failed_jobs')->delete();

        $oldTs = Carbon::now()->subDays(8)->toDateTimeString();
        $recentTs = Carbon::now()->subDays(2)->toDateTimeString();

        DB::table('failed_jobs')->insert([
            [
                'connection' => 'hwid_observation_worker',
                'queue' => 'hwid_observation',
                'payload' => '{}',
                'exception' => 'App\\Exceptions\\HwidObservationPersistenceException: db_busy',
                'failed_at' => $oldTs,
            ],
            [
                'connection' => 'hwid_observation_worker',
                'queue' => 'hwid_observation',
                'payload' => '{}',
                'exception' => 'App\\Exceptions\\HwidObservationPersistenceException: db_busy',
                'failed_at' => $recentTs,
            ],
            [
                'connection' => 'redis',
                'queue' => 'order_handle',
                'payload' => '{}',
                'exception' => 'OrderFailure',
                'failed_at' => $oldTs,
            ],
        ]);

        // Dry-run deletes 0 rows
        $this->assertSame(0, Artisan::call('subscription:hwid-failures-prune'));
        $dryJson = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($dryJson['dry_run']);
        $this->assertSame(1, $dryJson['eligible_count']);
        $this->assertSame(3, DB::table('failed_jobs')->count());

        // --execute deletes ONLY the >7d hwid_observation row; preserves recent hwid_observation and old order_handle!
        $this->assertSame(0, Artisan::call('subscription:hwid-failures-prune', ['--execute' => true]));
        $execJson = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
        $this->assertFalse($execJson['dry_run']);
        $this->assertSame(1, $execJson['deleted_count']);
        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('queue', 'order_handle')->count());
        $this->assertSame(1, DB::table('failed_jobs')->where('queue', 'hwid_observation')->count());
    }

    public function test_q05_config_validator_horizon_supervisor_isolation_entrypoint_budget_and_deploy_rollback_contracts(): void
    {
        // 1. Valid parity passes
        HwidObservationConfigValidator::assertQueueParity();
        HwidObservationConfigValidator::assertReleaseSwitchCombination([
            'hwid_async_metadata' => false,
            'hwid_observation_worker_enabled' => false,
        ]);
        HwidObservationConfigValidator::assertReleaseSwitchCombination([
            'hwid_async_metadata' => false,
            'hwid_observation_worker_enabled' => true,
        ]);
        HwidObservationConfigValidator::assertReleaseSwitchCombination([
            'hwid_async_metadata' => true,
            'hwid_observation_worker_enabled' => true,
        ]);

        // Producer enabled without worker enabled -> rejected!
        $this->expectExceptionObject(new InvalidArgumentException(
            'SUBSCRIPTION_HWID_ASYNC_METADATA cannot be enabled while SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED is false.'
        ));
        try {
            HwidObservationConfigValidator::assertReleaseSwitchCombination([
                'hwid_async_metadata' => true,
                'hwid_observation_worker_enabled' => false,
            ]);
        } catch (InvalidArgumentException $e) {
            // Verify queue/Redis mismatch rejections as well before rethrowing expected exception
            $badQueue = config('queue');
            $badQueue['connections']['hwid_observation_worker']['retry_after'] = 45;
            try {
                HwidObservationConfigValidator::assertQueueParity(null, $badQueue);
                $this->fail('Expected retry_after <= timeout to fail validation.');
            } catch (InvalidArgumentException $retryEx) {
                $this->assertStringContainsString('retry_after', $retryEx->getMessage());
            }

            $badDb = config('database');
            $badDb['redis']['hwid_observation_publish']['database'] = 9;
            try {
                HwidObservationConfigValidator::assertQueueParity($badDb);
                $this->fail('Expected Redis database mismatch to fail validation.');
            } catch (InvalidArgumentException $dbEx) {
                $this->assertStringContainsString('database', $dbEx->getMessage());
            }

            // Verify config/horizon.php supervisor gating and queue isolation
            $prevEnv = getenv('SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED');
            try {
                putenv('SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=false');
                $_ENV['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED'] = 'false';
                $_SERVER['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED'] = 'false';
                $disabledHorizon = require base_path('config/horizon.php');
                $this->assertArrayNotHasKey('hwid-observation', $disabledHorizon['environments']['production']);
                $this->assertNotContains('hwid_observation', $disabledHorizon['environments']['production']['business']['queue']);

                putenv('SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true');
                $_ENV['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED'] = 'true';
                $_SERVER['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED'] = 'true';
                $enabledHorizon = require base_path('config/horizon.php');
                $sup = $enabledHorizon['environments']['production']['hwid-observation'];
                $this->assertSame('hwid_observation_worker', $sup['connection']);
                $this->assertSame(['hwid_observation'], $sup['queue']);
                $this->assertSame('simple', $sup['balance']);
                $this->assertSame(1, $sup['minProcesses']);
                $this->assertSame(1, $sup['maxProcesses']);
                $this->assertSame(45, $sup['timeout']);
                $this->assertSame(3, $sup['tries']);
                $this->assertSame([5, 30], $sup['backoff']);
                $this->assertNotContains('hwid_observation', $enabledHorizon['environments']['production']['business']['queue']);
            } finally {
                if ($prevEnv === false) {
                    putenv('SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED');
                    unset($_ENV['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED'], $_SERVER['SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED']);
                } else {
                    putenv("SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED={$prevEnv}");
                }
            }

            // Verify deployment, compose, entrypoint, and rollback script contracts
            $entrypoint = (string) file_get_contents(base_path('.docker/entrypoint.sh'));
            $this->assertStringContainsString('resolveAndValidateBootstrapSwitches', $entrypoint);
            $this->assertStringContainsString('Refusing invalid subscription HWID configuration:', $entrypoint);

            $compose = (string) file_get_contents(base_path('compose.v2.sample.yaml'));
            $this->assertStringContainsString('stop_grace_period: 60s', $compose);

            $activate = (string) file_get_contents(base_path('.github/scripts/activate-xboard-green-roles.sh'));
            $this->assertStringContainsString('--time 60', $activate);
            $this->assertStringContainsString('hwid_observation', $activate);
            $this->assertStringContainsString('HwidObservationConfigValidator', $activate);

            $rollback = (string) file_get_contents(base_path('.github/scripts/rollback-xboard-live-green.sh'));
            $this->assertStringContainsString('--time 60', $rollback);
            $this->assertStringContainsString('hwid_observation', $rollback);

            $lowMem = (string) file_get_contents(base_path('.github/scripts/v2-low-memory-common.sh'));
            $this->assertStringContainsString('v2_hwid_observation_backlog', $lowMem);

            throw $e;
        }
    }

    public function test_q06_redis_url_query_parameter_overrides_are_sanitized_in_config_and_rejected_when_injected_raw(): void
    {
        // 1. Evaluate config/database.php with a hostile REDIS_URL carrying long timeouts, retries, and persistence
        $hostileUrl = sprintf(
            'redis://:urlSecret@%s:%d/0?read_timeout=30&read_write_timeout=30&timeout=30&max_retries=3&command_retries=5&retry_interval=250&persistent=1&persistent_id=shared_pool&backoff_algorithm=decorrelated_jitter&backoff_base=100&backoff_cap=1000',
            self::$redisServer->host(),
            self::$redisServer->port()
        );

        $prevRedisUrl = getenv('REDIS_URL');
        try {
            putenv("REDIS_URL={$hostileUrl}");
            $_ENV['REDIS_URL'] = $hostileUrl;
            $_SERVER['REDIS_URL'] = $hostileUrl;

            /** @var array<string, mixed> $loadedDatabaseConfig */
            $loadedDatabaseConfig = require base_path('config/database.php');
        } finally {
            if ($prevRedisUrl === false) {
                putenv('REDIS_URL');
                unset($_ENV['REDIS_URL'], $_SERVER['REDIS_URL']);
            } else {
                putenv("REDIS_URL={$prevRedisUrl}");
                $_ENV['REDIS_URL'] = $prevRedisUrl;
                $_SERVER['REDIS_URL'] = $prevRedisUrl;
            }
        }

        // Parity between default (unparsed REDIS_URL) and hwid_observation_publish (pre-parsed + sanitized) MUST pass
        HwidObservationConfigValidator::assertQueueParity($loadedDatabaseConfig);

        $publishRaw = $loadedDatabaseConfig['redis']['hwid_observation_publish'];
        $globalOpts = $loadedDatabaseConfig['redis']['options'];
        $effective = HwidObservationConfigValidator::resolveEffectiveRedisConfigs(
            $publishRaw,
            $globalOpts,
            'hwid_observation_publish'
        );

        foreach (['parsed', 'merged'] as $view) {
            $state = $effective[$view];
            $this->assertSame(self::$redisServer->host(), (string) $state['host']);
            $this->assertSame(self::$redisServer->port(), (int) $state['port']);
            $this->assertSame(0, (int) $state['database']);
            $this->assertSame('urlSecret', (string) $state['password']);
            $this->assertFalse($state['persistent']);
            $this->assertNull($state['persistent_id'] ?? null);
            $this->assertEqualsWithDelta(0.10, (float) $state['timeout'], 0.0001);
            $this->assertEqualsWithDelta(0.10, (float) $state['read_timeout'], 0.0001);
            $this->assertEqualsWithDelta(0.10, (float) $state['read_write_timeout'], 0.0001);
            $this->assertSame(0, (int) $state['max_retries']);
            $this->assertSame(0, (int) $state['retry_interval']);
            $this->assertSame(0, (int) $state['command_retries']);
            $this->assertArrayNotHasKey('backoff_algorithm', $state);
        }

        // When ext-redis (phpredis) is loaded, verify the actual C \Redis socket options too
        if (extension_loaded('redis')) {
            $noAuthUrl = sprintf(
                'redis://%s:%d/0?read_timeout=30&read_write_timeout=30&timeout=30&max_retries=3&command_retries=5&persistent=1',
                self::$redisServer->host(),
                self::$redisServer->port()
            );
            $prevUrl2 = getenv('REDIS_URL');
            try {
                putenv("REDIS_URL={$noAuthUrl}");
                $_ENV['REDIS_URL'] = $noAuthUrl;
                $_SERVER['REDIS_URL'] = $noAuthUrl;
                /** @var array<string, mixed> $liveDbCfg */
                $liveDbCfg = require base_path('config/database.php');
            } finally {
                if ($prevUrl2 === false) {
                    putenv('REDIS_URL');
                    unset($_ENV['REDIS_URL'], $_SERVER['REDIS_URL']);
                } else {
                    putenv("REDIS_URL={$prevUrl2}");
                    $_ENV['REDIS_URL'] = $prevUrl2;
                    $_SERVER['REDIS_URL'] = $prevUrl2;
                }
            }

            $manager = new \Illuminate\Redis\RedisManager($this->app, 'phpredis', $liveDbCfg['redis']);
            $client = $manager->connection('hwid_observation_publish')->client();
            $this->assertInstanceOf(\Redis::class, $client);
            $this->assertNull($client->getPersistentID());
            $this->assertEqualsWithDelta(0.10, (float) $client->getTimeout(), 0.005);
            $this->assertEqualsWithDelta(0.10, (float) $client->getReadTimeout(), 0.005);
            $this->assertSame(0, (int) $client->getOption(\Redis::OPT_MAX_RETRIES));
            $client->close();
        }

        // 2. Direct synthetic URL / global option overrides on hwid_observation_publish MUST be rejected
        $invalidCases = [
            'combined_url_override' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?read_timeout=30&read_write_timeout=30&max_retries=3&persistent=1';
            },
            'url_read_timeout_30' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?read_timeout=30';
            },
            'url_read_write_timeout_30' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?read_write_timeout=30';
            },
            'url_zero_infinite_timeout' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?timeout=0';
            },
            'url_max_retries_3' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?max_retries=3';
            },
            'url_command_retries_2' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?command_retries=2';
            },
            'url_retry_interval_100' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?retry_interval=100';
            },
            'url_persistent_1' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?persistent=1';
            },
            'url_backoff_algorithm' => static function (array &$db): void {
                $db['redis']['hwid_observation_publish']['url'] = 'redis://127.0.0.1:6379/0?backoff_algorithm=decorrelated_jitter';
            },
            'global_options_read_timeout_30_without_nested_guard' => static function (array &$db): void {
                unset($db['redis']['hwid_observation_publish']['options']);
                $db['redis']['options']['read_timeout'] = 30;
            },
        ];

        foreach ($invalidCases as $caseName => $mutator) {
            /** @var array<string, mixed> $candidateDb */
            $candidateDb = config('database');
            $candidateDb['redis']['default']['url'] = 'redis://127.0.0.1:6379/0';
            $candidateDb['redis']['default']['host'] = '127.0.0.1';
            $candidateDb['redis']['default']['port'] = 6379;
            $candidateDb['redis']['default']['database'] = 0;
            $candidateDb['redis']['hwid_observation_publish']['host'] = '127.0.0.1';
            $candidateDb['redis']['hwid_observation_publish']['port'] = 6379;
            $candidateDb['redis']['hwid_observation_publish']['database'] = 0;
            $mutator($candidateDb);

            try {
                HwidObservationConfigValidator::assertQueueParity($candidateDb);
                $this->fail("Expected assertQueueParity() to reject unsafe publish config case [{$caseName}].");
            } catch (InvalidArgumentException $expected) {
                $this->assertStringContainsString('HWID observation publish Redis connection', $expected->getMessage());
            }
        }
    }

    public function test_q07_real_application_boot_and_entrypoint_gate_enforce_switch_and_memory_contracts_with_dotenv_comments(): void
    {
        $tempDir = storage_path('framework/testing/xboard_q07_' . Str::random(10));
        mkdir($tempDir, 0777, true);

        try {
            // 1. Real child-process Laravel full Console\Kernel::bootstrap() with inline .env comment
            $illegalCommentEnv = implode("\n", [
                'APP_ENV=production',
                'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
                'SUBSCRIPTION_HWID_ASYNC_METADATA=true # enable feature',
                'SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=false',
                '',
            ]);
            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $illegalCommentEnv);

            $bootIllegal = $this->runChildLaravelBoot($tempDir);
            $this->assertNotSame(0, $bootIllegal['exit_code'], 'Normal Laravel boot must reject producer=true with inline comment while worker=false.');
            $this->assertStringContainsString(
                'SUBSCRIPTION_HWID_ASYNC_METADATA cannot be enabled while SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED is false.',
                $bootIllegal['stderr'] . $bootIllegal['stdout']
            );

            // Export + parenthesized boolean syntax in .env must also be caught identically
            $illegalExportEnv = implode("\n", [
                'APP_ENV=production',
                'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
                'export SUBSCRIPTION_HWID_ASYNC_METADATA=(true)',
                'SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=(false) # disabled',
                '',
            ]);
            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $illegalExportEnv);

            $bootIllegalExport = $this->runChildLaravelBoot($tempDir);
            $this->assertNotSame(0, $bootIllegalExport['exit_code']);
            $this->assertStringContainsString(
                'SUBSCRIPTION_HWID_ASYNC_METADATA cannot be enabled while SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED is false.',
                $bootIllegalExport['stderr'] . $bootIllegalExport['stdout']
            );

            // Valid Stage C .env with inline comments and hostile REDIS_URL boots cleanly (exit 0)
            $validStageCEnv = implode("\n", [
                'APP_ENV=production',
                'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
                'REDIS_URL="redis://127.0.0.1:6379/0?read_timeout=30&read_write_timeout=30&max_retries=3&persistent=1"',
                'SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true # stage B consumer enabled',
                'SUBSCRIPTION_HWID_ASYNC_METADATA=true # stage C producer enabled',
                '',
            ]);
            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $validStageCEnv);

            $bootValid = $this->runChildLaravelBoot($tempDir);
            $this->assertSame(0, $bootValid['exit_code'], 'Valid Stage C boot failed: ' . $bootValid['stderr']);
            $this->assertStringContainsString('BOOT_OK async=true worker=true', $bootValid['stdout']);

            // 2. Real POSIX sh execution of .docker/entrypoint.sh self-test gate (never silently skip!)
            $shBinary = $this->findPosixShellBinary();
            if ($shBinary === null) {
                $this->markTestSkipped('No POSIX sh binary found to execute .docker/entrypoint.sh gate assertions.');
            }

            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $illegalCommentEnv);
            $entryIllegal = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '4',
                'XBOARD_TEST_MEM_MIB' => '1024',
            ]);
            $this->assertSame(1, $entryIllegal['exit_code']);
            $this->assertStringContainsString(
                'SUBSCRIPTION_HWID_ASYNC_METADATA cannot be enabled while SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED is false.',
                $entryIllegal['stderr']
            );

            // Minimal all-in-one profile with 512 MiB + worker=true # comment MUST fail memory budget gate
            // (200 reserved + (1 web + 10 Horizon queue pools) * 100 = 1300 MiB)
            $stageBCommentEnv = implode("\n", [
                'APP_ENV=production',
                'APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
                'SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true # stage B',
                'SUBSCRIPTION_HWID_ASYNC_METADATA=false',
                '',
            ]);
            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $stageBCommentEnv);
            $entryLowMem = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'minimal',
                'XBOARD_TEST_CPUS' => '1',
                'XBOARD_TEST_MEM_MIB' => '512',
            ]);
            $this->assertSame(1, $entryLowMem['exit_code']);
            $this->assertStringContainsString(
                'Refusing SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true for profile=minimal: mem=512MiB is below required 1300MiB.',
                $entryLowMem['stderr']
            );

            // Horizon-only minimal with 600 MiB MUST fail against the real 10-pool topology (200 + 10 * 100 = 1200 MiB)
            $entryHorizonOnlyLowMem = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'XBOARD_RUNTIME_ROLE' => 'horizon',
                'RESOURCE_PROFILE' => 'minimal',
                'XBOARD_TEST_CPUS' => '2',
                'XBOARD_TEST_MEM_MIB' => '600',
            ]);
            $this->assertSame(1, $entryHorizonOnlyLowMem['exit_code']);
            $this->assertStringContainsString(
                'Refusing SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true for profile=minimal: mem=600MiB is below required 1200MiB.',
                $entryHorizonOnlyLowMem['stderr']
            );

            // Horizon-only minimal with 1200 MiB passes cleanly
            $entryHorizonOnlyValid = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'XBOARD_RUNTIME_ROLE' => 'horizon',
                'RESOURCE_PROFILE' => 'minimal',
                'XBOARD_TEST_CPUS' => '2',
                'XBOARD_TEST_MEM_MIB' => '1200',
            ]);
            $this->assertSame(0, $entryHorizonOnlyValid['exit_code'], 'Horizon-only 1200MiB failed: ' . $entryHorizonOnlyValid['stderr']);

            // Balanced all-in-one with 1024 MiB MUST fail (300 + (1 + 10) * 80 = 1180 MiB)
            file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', $validStageCEnv);
            $entryBalancedLow = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '4',
                'XBOARD_TEST_MEM_MIB' => '1024',
            ]);
            $this->assertSame(1, $entryBalancedLow['exit_code']);
            $this->assertStringContainsString(
                'Refusing SUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=true for profile=balanced: mem=1024MiB is below required 1180MiB.',
                $entryBalancedLow['stderr']
            );

            // Valid Stage C on balanced 1280 MiB passes entrypoint gate cleanly and caps auto_octane to remaining slots (12 - 10 = 2)
            $entryValid = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '4',
                'XBOARD_TEST_MEM_MIB' => '1280',
            ]);
            $this->assertSame(0, $entryValid['exit_code'], 'Entrypoint valid Stage C failed: ' . $entryValid['stderr']);
            $this->assertStringContainsString('[entrypoint] Auto-tune (profile=balanced):', $entryValid['stdout']);
            $this->assertStringContainsString('slots=12 -> octane=2', $entryValid['stdout']);

            // OCTANE_WORKERS=auto resolves to CPUS (4) so 4 + 10 = 14 slots requires 300 + 14 * 80 = 1420 MiB
            $entryOctaneAutoLow = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '4',
                'XBOARD_TEST_MEM_MIB' => '1280',
                'OCTANE_WORKERS' => 'auto',
            ]);
            $this->assertSame(1, $entryOctaneAutoLow['exit_code']);
            $this->assertStringContainsString('below required 1420MiB', $entryOctaneAutoLow['stderr']);

            $entryOctaneAutoOk = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '4',
                'XBOARD_TEST_MEM_MIB' => '1420',
                'OCTANE_WORKERS' => 'auto',
            ]);
            $this->assertSame(0, $entryOctaneAutoOk['exit_code'], 'Entrypoint OCTANE_WORKERS=auto failed: ' . $entryOctaneAutoOk['stderr']);

            // Invalid OCTANE_WORKERS values fail fast with exit code 1
            $entryOctaneInvalid = $this->runEntrypointAutotuneGate($shBinary, $tempDir, [
                'RESOURCE_PROFILE' => 'balanced',
                'XBOARD_TEST_CPUS' => '2',
                'XBOARD_TEST_MEM_MIB' => '1420',
                'OCTANE_WORKERS' => 'bogus',
            ]);
            $this->assertSame(1, $entryOctaneInvalid['exit_code']);
            $this->assertStringContainsString("Refusing invalid OCTANE_WORKERS='bogus'", $entryOctaneInvalid['stderr']);
        } finally {
            @unlink($tempDir . DIRECTORY_SEPARATOR . '.env');
            @rmdir($tempDir);
        }
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runChildLaravelBoot(string $envDir): array
    {
        $basePath = base_path();
        $code = <<<'PHP'
$basePath = $argv[1];
$envDir = $argv[2];
require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->useEnvironmentPath($envDir);
try {
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    printf(
        "BOOT_OK async=%s worker=%s\n",
        config('subscription.hwid_async_metadata') ? 'true' : 'false',
        config('subscription.hwid_observation_worker_enabled') ? 'true' : 'false'
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
PHP;

        $env = $this->buildCleanChildEnv();

        return $this->runSubprocess([PHP_BINARY, '-r', $code, $basePath, $envDir], $env);
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runEntrypointAutotuneGate(string $shBinary, string $envDir, array $extraEnv): array
    {
        $env = array_merge($this->buildCleanChildEnv(), [
            'XBOARD_ENTRYPOINT_SELF_TEST' => 'autotune-gate',
            'XBOARD_APP_ROOT' => str_replace('\\', '/', base_path()),
            'XBOARD_ENV_PATH' => str_replace('\\', '/', $envDir),
            'XBOARD_ENV_FILE' => '.env',
            'PHP_BIN' => str_replace('\\', '/', PHP_BINARY),
        ], $extraEnv);

        $entrypointPath = str_replace('\\', '/', base_path('.docker/entrypoint.sh'));

        return $this->runSubprocess([$shBinary, $entrypointPath], $env);
    }

    /**
     * @return array<string, string>
     */
    private function buildCleanChildEnv(): array
    {
        $raw = getenv();
        $clean = [];
        foreach ($raw as $key => $val) {
            if (!is_string($key) || !is_string($val)) {
                continue;
            }
            $upper = strtoupper($key);
            if (
                str_starts_with($upper, 'SUBSCRIPTION_HWID_')
                || str_starts_with($upper, 'REDIS_')
                || in_array($upper, ['APP_ENV', 'APP_KEY', 'CACHE_STORE', 'CACHE_DRIVER', 'QUEUE_CONNECTION'], true)
            ) {
                continue;
            }
            $clean[$key] = $val;
        }

        return $clean;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runSubprocess(array $command, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($command, $descriptors, $pipes, base_path(), $env);
        $this->assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function findPosixShellBinary(): ?string
    {
        if (DIRECTORY_SEPARATOR === '/' && is_executable('/bin/sh')) {
            return '/bin/sh';
        }

        $candidates = [
            'C:\\Program Files\\Git\\bin\\sh.exe',
            'C:\\Program Files\\Git\\usr\\bin\\sh.exe',
            'D:\\Program Files\\Git\\bin\\sh.exe',
            'C:\\msys64\\usr\\bin\\sh.exe',
            'C:\\cygwin64\\bin\\sh.exe',
            'C:\\cygwin\\bin\\sh.exe',
        ];

        $pathEnv = getenv('PATH');
        if (is_string($pathEnv) && $pathEnv !== '') {
            foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
                $dir = trim($dir, " \t\n\r\0\x0B\"");
                if ($dir === '') {
                    continue;
                }
                $lower = strtolower($dir);
                if (str_contains($lower, '\\windows\\system32') || str_contains($lower, '\\windowsapps')) {
                    continue;
                }
                $candidates[] = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'sh.exe';
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $probe = $this->runSubprocess([$candidate, '-c', 'exit 0'], $this->buildCleanChildEnv());
            if ($probe['exit_code'] === 0) {
                return $candidate;
            }
        }

        return null;
    }

    public function test_q08_real_redis_server_and_phpredis_driver_fault_isolation_and_commandstats(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Requires ext-redis (phpredis) production C extension.');
        }

        $realRedis = $this->startDedicatedRealRedisServer();
        if ($realRedis === null) {
            $this->markTestSkipped('Requires local redis-server binary to spawn an isolated ephemeral instance.');
        }

        $blackholeProxy = null;
        $dropProxy = null;
        $prefix = 'xboard_q08_redis_' . Str::lower(Str::random(8)) . '_';
        $cachePrefix = 'xboard_q08_cache_' . Str::lower(Str::random(8)) . '_';

        try {
            $realHost = $realRedis['host'];
            $realPort = $realRedis['port'];

            // Load config/database.php with a hostile REDIS_URL pointing at our isolated child redis-server
            $hostileUrl = "redis://{$realHost}:{$realPort}/0?read_timeout=30&read_write_timeout=30&timeout=30&max_retries=3&command_retries=5&persistent=1";
            $previousEnvironment = [];
            foreach (['REDIS_URL', 'REDIS_PREFIX'] as $key) {
                $previousEnvironment[$key] = [
                    'process' => getenv($key),
                    'env_exists' => array_key_exists($key, $_ENV),
                    'env' => $_ENV[$key] ?? null,
                    'server_exists' => array_key_exists($key, $_SERVER),
                    'server' => $_SERVER[$key] ?? null,
                ];
            }
            try {
                putenv("REDIS_URL={$hostileUrl}");
                putenv("REDIS_PREFIX={$prefix}");
                $_ENV['REDIS_URL'] = $hostileUrl;
                $_ENV['REDIS_PREFIX'] = $prefix;
                $_SERVER['REDIS_URL'] = $hostileUrl;
                $_SERVER['REDIS_PREFIX'] = $prefix;
                /** @var array<string, mixed> $realDbCfg */
                $realDbCfg = require base_path('config/database.php');
            } finally {
                foreach ($previousEnvironment as $key => $previous) {
                    putenv($previous['process'] === false ? $key : $key . '=' . $previous['process']);
                    if ($previous['env_exists']) {
                        $_ENV[$key] = $previous['env'];
                    } else {
                        unset($_ENV[$key]);
                    }
                    if ($previous['server_exists']) {
                        $_SERVER[$key] = $previous['server'];
                    } else {
                        unset($_SERVER[$key]);
                    }
                }
            }

            $realDbCfg['redis']['client'] = 'phpredis';
            $realDbCfg['redis']['cache']['url'] = null;
            $realDbCfg['redis']['cache']['host'] = $realHost;
            $realDbCfg['redis']['cache']['port'] = $realPort;
            $realDbCfg['redis']['cache']['database'] = 1;

            config([
                'database.redis' => $realDbCfg['redis'],
                'cache.default' => 'redis',
                'cache.prefix' => $cachePrefix,
                'subscription.hwid_observation_worker_enabled' => true,
                'subscription.hwid_async_metadata' => true,
            ]);
            $this->bindNativePhpRedisManager($realDbCfg['redis']);

            // Verify real redis-server version (not PHP simulator) & C \Redis options without destructive FLUSHALL/RESETSTAT
            /** @var \Redis $rawDefault */
            $rawDefault = Redis::connection('default')->client();
            $serverInfo = $rawDefault->info('server');
            $this->assertIsArray($serverInfo);
            $this->assertArrayHasKey('redis_version', $serverInfo);

            /** @var \Redis $rawPublish */
            $rawPublish = Redis::connection('hwid_observation_publish')->client();
            $this->assertNull($rawPublish->getPersistentID());
            $this->assertEqualsWithDelta(0.10, (float) $rawPublish->getTimeout(), 0.005);
            $this->assertEqualsWithDelta(0.10, (float) $rawPublish->getReadTimeout(), 0.005);
            $this->assertSame(0, (int) $rawPublish->getOption(\Redis::OPT_MAX_RETRIES));

            // 1. Seed 100 valid Trojan nodes (with network=tcp) and verify real redis-server commandstats delta: +1 MGET, +0 GET
            $now = time();
            for ($i = 1; $i <= 100; $i++) {
                $this->makeOnlineServer([
                    'name' => "RealRedisTrojan-{$i}",
                    'type' => \App\Models\Server::TYPE_TROJAN,
                    'host' => '198.51.100.' . (($i % 250) + 1),
                    'port' => (string) (21000 + $i),
                    'server_port' => 21000 + $i,
                    'sort' => $i,
                    'protocol_settings' => [
                        'server_name' => 'real.example.com',
                        'network' => 'tcp',
                    ],
                ], $now);
            }

            $fixture = $this->makeDistributorSubscriptionFixture(hwidLimit: 3, hwidEnabled: true);
            $delivery = $fixture['delivery'];
            $subscriber = $fixture['subscriber'];
            $device = $this->seedRegisteredHwidDevice($delivery, 'q08-real-phpredis-dev', [
                'last_seen_at' => 1_760_000_000,
            ]);
            DB::table('v2_distributor_order')->where('id', $delivery->id)->update([
                'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
                'claimed_at' => 1_760_000_000,
                'config_issued_at' => 1_760_000_000,
            ]);

            $statsBefore = $rawDefault->info('commandstats');
            $this->assertIsArray($statsBefore);
            $mgetBefore = $this->parseRedisCommandStatCalls($statsBefore, 'mget');
            $getBefore = $this->parseRedisCommandStatCalls($statsBefore, 'get');

            $available = \App\Services\ServerService::getAvailableServers($subscriber);
            $this->assertCount(100, $available);
            foreach ($available as $srvRow) {
                $this->assertSame(1, $srvRow['is_online']);
                $this->assertSame(\App\Models\Server::STATUS_ONLINE, $srvRow['available_status']);
            }

            $statsAfter = $rawDefault->info('commandstats');
            $this->assertIsArray($statsAfter);
            $this->assertSame(1, $this->parseRedisCommandStatCalls($statsAfter, 'mget') - $mgetBefore);
            $this->assertSame(0, $this->parseRedisCommandStatCalls($statsAfter, 'get') - $getBefore);

            // Full subscription GET renders all 100 Trojan proxies and pushes 1 job via real phpredis
            $okResp = $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
                'x-hwid' => 'q08-real-phpredis-dev',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ])->assertOk();
            $parsedYaml = \Symfony\Component\Yaml\Yaml::parse((string) $okResp->getContent());
            $this->assertIsArray($parsedYaml);
            $this->assertCount(100, $parsedYaml['proxies'] ?? []);

            /** @var HwidObservationRedisQueue $workerQueue */
            $workerQueue = Queue::connection('hwid_observation_worker');
            $this->assertSame(1, $workerQueue->pendingSize('hwid_observation'));
            $nativeSnapshot = $workerQueue->statusSnapshot('hwid_observation');
            $this->assertSame(1, $nativeSnapshot['ready']);
            $this->assertSame(0, $nativeSnapshot['reserved']);
            $this->assertSame(0, $nativeSnapshot['delayed']);
            $this->assertSame(1, $nativeSnapshot['total']);
            $this->assertIsInt($nativeSnapshot['oldest_created_at']);
            $this->artisan('queue:work', [
                'connection' => 'hwid_observation_worker',
                '--queue' => 'hwid_observation',
                '--once' => true,
                '--tries' => 3,
            ])->assertExitCode(0);
            $this->assertSame(0, $workerQueue->pendingSize('hwid_observation'));
            $this->assertSame([
                'ready' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0,
                'oldest_created_at' => null,
            ], $workerQueue->statusSnapshot('hwid_observation'));

            // 2. Real TCP silent blackhole (1500ms stall) against real C phpredis -> bounded < 1000ms, HTTP 200, 0 sync writes
            $blackholeProxy = $this->startTcpFaultProxy(['BLACKHOLE', '127.0.0.1:0', '1500']);
            $bhDbCfg = config('database.redis');
            $bhDbCfg['hwid_observation_publish']['host'] = $blackholeProxy['host'];
            $bhDbCfg['hwid_observation_publish']['port'] = $blackholeProxy['port'];
            $bhDbCfg['default']['url'] = null;
            $bhDbCfg['default']['host'] = $blackholeProxy['host'];
            $bhDbCfg['default']['port'] = $blackholeProxy['port'];
            config(['database.redis' => $bhDbCfg]);
            $this->bindNativePhpRedisManager($bhDbCfg);

            $seenBeforeFault = (int) $device->fresh()->last_seen_at;
            $tBh = hrtime(true);
            $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
                'x-hwid' => 'q08-real-phpredis-dev',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ])->assertOk();
            $bhElapsedMs = (hrtime(true) - $tBh) / 1_000_000;
            $this->assertLessThan(1000.0, $bhElapsedMs);
            $this->assertSame($seenBeforeFault, (int) $device->fresh()->last_seen_at);

            // 3. Real TCP connection refused against real C phpredis -> bounded < 1000ms, HTTP 200, 0 sync writes
            $closedSock = stream_socket_server('tcp://127.0.0.1:0');
            $closedName = (string) stream_socket_get_name($closedSock, false);
            fclose($closedSock);
            $closedPort = (int) substr($closedName, strrpos($closedName, ':') + 1);

            $rfDbCfg = config('database.redis');
            $rfDbCfg['hwid_observation_publish']['host'] = '127.0.0.1';
            $rfDbCfg['hwid_observation_publish']['port'] = $closedPort;
            $rfDbCfg['default']['host'] = '127.0.0.1';
            $rfDbCfg['default']['port'] = $closedPort;
            config(['database.redis' => $rfDbCfg]);
            $this->bindNativePhpRedisManager($rfDbCfg);

            $tRf = hrtime(true);
            $this->get('/api/v1/client/subscribe?token=' . $subscriber->token, [
                'x-hwid' => 'q08-real-phpredis-dev',
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ])->assertOk();
            $rfElapsedMs = (hrtime(true) - $tRf) / 1_000_000;
            $this->assertLessThan(1000.0, $rfElapsedMs);
            $this->assertSame($seenBeforeFault, (int) $device->fresh()->last_seen_at);

            // 4. Real TCP pass-through relay dropping socket AFTER forwarding RPUSH to real redis-server
            $dropProxy = $this->startTcpFaultProxy([
                'DROP_AFTER_UPSTREAM',
                '127.0.0.1:0',
                "{$realHost}:{$realPort}",
                'rpush',
            ]);
            $dropDbCfg = $realDbCfg['redis'];
            $dropDbCfg['hwid_observation_publish']['host'] = $dropProxy['host'];
            $dropDbCfg['hwid_observation_publish']['port'] = $dropProxy['port'];
            $dropDbCfg['default']['url'] = null;
            $dropDbCfg['default']['host'] = $dropProxy['host'];
            $dropDbCfg['default']['port'] = $dropProxy['port'];
            config(['database.redis' => $dropDbCfg]);
            $this->bindNativePhpRedisManager($dropDbCfg);

            $ackLostOut = app(DistributorHwidObservationPublisher::class)->publish([
                'device_id' => (int) $device->id,
                'distributor_order_id' => (int) $delivery->id,
                'hwid' => 'q08-real-phpredis-dev',
                'observed_at' => $now + 500,
                'device_model' => 'RealPhpRedisAckLostRecovered',
            ]);
            $this->assertSame(DistributorHwidObservationPublisher::RESULT_UNKNOWN, $ackLostOut['result']);
            $this->assertSame('redis_ack_lost', $ackLostOut['error_category']);

            // Restore direct real redis-server connection and prove exact 1 job landed (zero duplicate retry) and executes
            $cleanRealCfg = $realDbCfg['redis'];
            $cleanRealCfg['default']['url'] = null;
            $cleanRealCfg['default']['host'] = $realHost;
            $cleanRealCfg['default']['port'] = $realPort;
            config(['database.redis' => $cleanRealCfg]);
            $this->bindNativePhpRedisManager($cleanRealCfg);

            /** @var HwidObservationRedisQueue $restoredWorkerQueue */
            $restoredWorkerQueue = Queue::connection('hwid_observation_worker');
            $this->assertSame(1, $restoredWorkerQueue->pendingSize('hwid_observation'));

            $this->artisan('queue:work', [
                'connection' => 'hwid_observation_worker',
                '--queue' => 'hwid_observation',
                '--once' => true,
                '--tries' => 3,
            ])->assertExitCode(0);
            $this->assertSame('RealPhpRedisAckLostRecovered', $device->fresh()->device_model);

            // Clean only test-owned prefixed keys before terminating our dedicated child redis-server
            $this->deletePrefixedKeysOnly($realHost, $realPort, $prefix);
            $this->deletePrefixedKeysOnly($realHost, $realPort, $cachePrefix);
        } finally {
            $this->stopDaemonHandle($blackholeProxy);
            $this->stopDaemonHandle($dropProxy);
            $this->stopRealRedisServer($realRedis);
        }
    }

    public function test_q09_effective_redis_config_matches_real_framework_connector_parameters(): void
    {
        /**
         * Intercept real PhpRedisConnector::createClient() before opening a network socket
         * and compare its merged parameters with the validator. Predis cases below check
         * the validator's configuration view without creating a real Predis client.
         */
        $capturePhpRedisClientConfig = function (array $redisConfig, string $connectionName): array {
            $captured = [];
            $connector = new class($captured) extends \Illuminate\Redis\Connectors\PhpRedisConnector {
                /** @var array<string, mixed> */
                private array $captured;

                /**
                 * @param array<string, mixed> $captured
                 */
                public function __construct(array &$captured)
                {
                    $this->captured = &$captured;
                }

                protected function createClient(array $config)
                {
                    $persistent = (bool) ($config['persistent'] ?? false);
                    $this->captured = [
                        'raw_client_config' => $config,
                        'host' => $this->formatHost($config),
                        'port' => $config['port'] ?? 6379,
                        'database' => (int) ($config['database'] ?? 0),
                        'persistent' => $persistent,
                        'effective_persistent_id' => $persistent ? ($config['persistent_id'] ?? null) : null,
                        'effective_prefix' => !empty($config['prefix']) ? (string) $config['prefix'] : '',
                        'timeout' => (float) ($config['timeout'] ?? 0.0),
                        'read_timeout' => (float) ($config['read_timeout'] ?? 0.0),
                        'retry_interval' => (int) ($config['retry_interval'] ?? 0),
                        'max_retries' => $config['max_retries'] ?? null,
                    ];

                    return new \stdClass();
                }
            };

            $testCfg = $redisConfig;
            $testCfg['client'] = 'phpredis-capture';
            $manager = new \Illuminate\Redis\RedisManager($this->app, 'phpredis-capture', $testCfg);
            $manager->extend('phpredis-capture', static fn () => $connector);
            $manager->resolve($connectionName);

            return $captured;
        };

        // 1. Divergent top-level prefix ('producer_' vs 'consumer_') with identical nested options.prefix ('shared_')
        //    Real PhpRedisConnector applies top-level prefix OVER nested options.prefix -> MUST BE REJECTED!
        /** @var array<string, mixed> $divergentTopDb */
        $divergentTopDb = config('database');
        $divergentTopDb['redis']['client'] = 'phpredis';
        $divergentTopDb['redis']['default'] = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'consumer_',
            'options' => ['prefix' => 'shared_'],
        ];
        $divergentTopDb['redis']['hwid_observation_publish'] = HwidObservationConfigValidator::buildPublishRedisConnectionConfig([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'producer_',
            'options' => ['prefix' => 'shared_'],
        ]);

        $realPubDivergent = $capturePhpRedisClientConfig($divergentTopDb['redis'], 'hwid_observation_publish');
        $realWrkDivergent = $capturePhpRedisClientConfig($divergentTopDb['redis'], 'default');
        $this->assertSame('producer_', $realPubDivergent['effective_prefix']);
        $this->assertSame('consumer_', $realWrkDivergent['effective_prefix']);

        $validatorPubDivergent = HwidObservationConfigValidator::resolveEffectiveRedisConfigs(
            $divergentTopDb['redis']['hwid_observation_publish'],
            $divergentTopDb['redis']['options'],
            'hwid_observation_publish'
        );
        $this->assertSame($realPubDivergent['effective_prefix'], (string) ($validatorPubDivergent['merged']['prefix'] ?? ''));

        try {
            HwidObservationConfigValidator::assertQueueParity($divergentTopDb);
            $this->fail('Expected assertQueueParity() to reject divergent top-level prefixes masked by shared nested options.prefix.');
        } catch (InvalidArgumentException $expected) {
            $this->assertStringContainsString('Producer and worker Redis instance, database, and prefix must match.', $expected->getMessage());
        }

        // Same check under predis driver
        $divergentTopPredis = $divergentTopDb;
        $divergentTopPredis['redis']['client'] = 'predis';
        try {
            HwidObservationConfigValidator::assertQueueParity($divergentTopPredis);
            $this->fail('Expected assertQueueParity() under predis to reject divergent top-level prefixes.');
        } catch (InvalidArgumentException $expected) {
            $this->assertStringContainsString('Producer and worker Redis instance, database, and prefix must match.', $expected->getMessage());
        }

        // 2. Identical top-level prefix ('same_') with divergent nested options.prefix ('nested_a_' vs 'nested_b_')
        //    Real PhpRedisConnector resolves both to 'same_' -> MUST PASS!
        /** @var array<string, mixed> $sameTopDb */
        $sameTopDb = config('database');
        $sameTopDb['redis']['client'] = 'phpredis';
        $sameTopDb['redis']['default'] = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'same_',
            'options' => ['prefix' => 'nested_b_'],
        ];
        $sameTopDb['redis']['hwid_observation_publish'] = HwidObservationConfigValidator::buildPublishRedisConnectionConfig([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'prefix' => 'same_',
            'options' => ['prefix' => 'nested_a_'],
        ]);

        $realPubSame = $capturePhpRedisClientConfig($sameTopDb['redis'], 'hwid_observation_publish');
        $realWrkSame = $capturePhpRedisClientConfig($sameTopDb['redis'], 'default');
        $this->assertSame('same_', $realPubSame['effective_prefix']);
        $this->assertSame('same_', $realWrkSame['effective_prefix']);
        HwidObservationConfigValidator::assertQueueParity($sameTopDb);

        // 3. Nested options.prefix = null on one side overriding global options.prefix = 'global_' -> real prefix '' vs 'global_' -> MUST BE REJECTED!
        /** @var array<string, mixed> $nullOverrideDb */
        $nullOverrideDb = config('database');
        $nullOverrideDb['redis']['client'] = 'phpredis';
        $nullOverrideDb['redis']['options']['prefix'] = 'global_';
        $nullOverrideDb['redis']['default'] = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ];
        $nullOverrideDb['redis']['hwid_observation_publish'] = HwidObservationConfigValidator::buildPublishRedisConnectionConfig([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'options' => ['prefix' => null],
        ]);
        $realPubNull = $capturePhpRedisClientConfig($nullOverrideDb['redis'], 'hwid_observation_publish');
        $realWrkNull = $capturePhpRedisClientConfig($nullOverrideDb['redis'], 'default');
        $this->assertSame('', $realPubNull['effective_prefix']);
        $this->assertSame('global_', $realWrkNull['effective_prefix']);
        try {
            HwidObservationConfigValidator::assertQueueParity($nullOverrideDb);
            $this->fail('Expected assertQueueParity() to reject nested null prefix overriding global prefix on producer only.');
        } catch (InvalidArgumentException $expected) {
            $this->assertStringContainsString('Producer and worker Redis instance, database, and prefix must match.', $expected->getMessage());
        }

        // 4. Global database.redis.options.persistent_id set on deployment while publish connection has persistent=false
        //    (both with buildPublishRedisConnectionConfig shielding AND raw config without nested persistent_id) -> MUST PASS!
        /** @var array<string, mixed> $globalPersistentIdDb */
        $globalPersistentIdDb = config('database');
        $globalPersistentIdDb['redis']['client'] = 'phpredis';
        $globalPersistentIdDb['redis']['options']['persistent_id'] = 'global_shared_pool';
        $globalPersistentIdDb['redis']['default'] = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ];
        $globalPersistentIdDb['redis']['hwid_observation_publish'] = HwidObservationConfigValidator::buildPublishRedisConnectionConfig([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);

        $realPubGlobalPid = $capturePhpRedisClientConfig($globalPersistentIdDb['redis'], 'hwid_observation_publish');
        $this->assertFalse($realPubGlobalPid['persistent']);
        $this->assertNull($realPubGlobalPid['effective_persistent_id']);
        HwidObservationConfigValidator::assertQueueParity($globalPersistentIdDb);

        // Even if nested options did not explicitly include persistent_id=null, PhpRedis ignores persistent_id when persistent=false
        unset($globalPersistentIdDb['redis']['hwid_observation_publish']['options']['persistent_id']);
        unset($globalPersistentIdDb['redis']['hwid_observation_publish']['persistent_id']);
        $realPubNoNestedPid = $capturePhpRedisClientConfig($globalPersistentIdDb['redis'], 'hwid_observation_publish');
        $this->assertFalse($realPubNoNestedPid['persistent']);
        $this->assertNull($realPubNoNestedPid['effective_persistent_id']);
        HwidObservationConfigValidator::assertQueueParity($globalPersistentIdDb);
    }

    /**
     * @param array<string, mixed> $cmdStats
     */
    private function parseRedisCommandStatCalls(array $cmdStats, string $command): int
    {
        $raw = (string) ($cmdStats['cmdstat_' . strtolower($command)] ?? '');
        if (preg_match('/calls=(\d+)/', $raw, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function deletePrefixedKeysOnly(string $host, int $port, string $prefix): void
    {
        $client = new \Redis();
        if (!@$client->connect($host, $port, 0.5)) {
            return;
        }
        try {
            foreach ([0, 1] as $dbIndex) {
                $client->select($dbIndex);
                $iterator = null;
                while (false !== ($keys = $client->scan($iterator, $prefix . '*', 200))) {
                    if (is_array($keys) && $keys !== []) {
                        $client->del($keys);
                    }
                    if ($iterator === 0) {
                        break;
                    }
                }
            }
        } finally {
            @$client->close();
        }
    }

    /**
     * @param array<string, mixed> $redisConfig
     */
    private function bindNativePhpRedisManager(array $redisConfig): void
    {
        $manager = new \Illuminate\Redis\RedisManager($this->app, 'phpredis', $redisConfig);
        $this->app->instance('redis', $manager);
        $this->app->forgetInstance('redis.connection');
        if ($this->app->resolved('cache')) {
            $this->app['cache']->forgetDriver('redis');
        }
        $this->app->forgetInstance('queue');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('redis');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('queue');
    }

    /**
     * Spawn an isolated, test-owned ephemeral redis-server child process, or attach
     * only to a coordinator-created disposable CI Redis container verified via QA_REDIS_MARKER.
     * Never attaches to an unverified shared Redis instance.
     *
     * @return array{host: string, port: int, process: resource|null, pipes: array<int, resource>, data_dir: string}|null
     */
    private function startDedicatedRealRedisServer(): ?array
    {
        $redisBin = null;
        $candidates = ['/usr/bin/redis-server', '/usr/local/bin/redis-server'];
        $envBin = getenv('XBOARD_TEST_REDIS_SERVER_BIN');
        if (is_string($envBin) && $envBin !== '') {
            array_unshift($candidates, $envBin);
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $redisBin = $candidate;
                break;
            }
        }
        if ($redisBin === null) {
            $marker = getenv('QA_REDIS_MARKER');
            if (is_string($marker) && preg_match('/\A[a-f0-9]{32}\z/', $marker) === 1 && extension_loaded('redis')) {
                $qaHost = getenv('QA_REDIS_HOST') ?: '127.0.0.1';
                $qaPort = (int) (getenv('QA_REDIS_PORT') ?: 19449);
                if (in_array([$qaHost, $qaPort], [['127.0.0.1', 19449], ['redis', 6379]], true)) {
                    $probeClient = new \Redis();
                    if (@$probeClient->connect($qaHost, $qaPort, 1.0)) {
                        try {
                            if ($probeClient->rawCommand('GET', 'qa:diagnostics:isolated-server') === $marker) {
                                return [
                                    'host' => $qaHost,
                                    'port' => $qaPort,
                                    'process' => null,
                                    'pipes' => [],
                                    'data_dir' => '',
                                ];
                            }
                        } finally {
                            @$probeClient->close();
                        }
                    }
                }
            }

            return null;
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return null;
        }
        $probeName = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $freePort = (int) substr($probeName, strrpos($probeName, ':') + 1);

        $dataDir = storage_path('framework/testing/xboard_real_redis_' . Str::random(8));
        mkdir($dataDir, 0777, true);

        $cmd = [
            $redisBin,
            '--bind', '127.0.0.1',
            '--port', (string) $freePort,
            '--dir', $dataDir,
            '--save', '',
            '--appendonly', 'no',
            '--loglevel', 'warning',
        ];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            @rmdir($dataDir);
            return null;
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $sock = @stream_socket_client("tcp://127.0.0.1:{$freePort}", $e, $s, 0.2);
            if (is_resource($sock)) {
                fclose($sock);
                return [
                    'host' => '127.0.0.1',
                    'port' => $freePort,
                    'process' => $proc,
                    'pipes' => $pipes,
                    'data_dir' => $dataDir,
                ];
            }
            usleep(50000);
        }

        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_terminate($proc);
        @proc_close($proc);
        @rmdir($dataDir);

        return null;
    }

    /**
     * @param array{host: string, port: int, process: resource|null, pipes: array<int, resource>, data_dir: string}|null $handle
     */
    private function stopRealRedisServer(?array $handle): void
    {
        if ($handle === null) {
            return;
        }
        foreach ($handle['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (isset($handle['process']) && is_resource($handle['process'])) {
            @proc_terminate($handle['process']);
            @proc_close($handle['process']);
        }
        if ($handle['data_dir'] !== '' && is_dir($handle['data_dir'])) {
            foreach (glob($handle['data_dir'] . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($handle['data_dir']);
        }
    }

    /**
     * @param list<string> $args
     * @return array{host: string, port: int, process: resource, pipes: array<int, resource>}
     */
    private function startTcpFaultProxy(array $args): array
    {
        $script = base_path('tests/Support/tcp-fault-proxy-daemon.php');
        $cmd = array_merge([PHP_BINARY, $script], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1]);
        $this->assertIsString($line);
        $this->assertStringStartsWith('READY ', trim($line));

        $bound = trim(substr(trim($line), 6));
        $colon = (int) strrpos($bound, ':');

        return [
            'host' => substr($bound, 0, $colon),
            'port' => (int) substr($bound, $colon + 1),
            'process' => $proc,
            'pipes' => $pipes,
        ];
    }

    /**
     * @param array{host: string, port: int, process: resource, pipes: array<int, resource>}|null $handle
     */
    private function stopDaemonHandle(?array $handle): void
    {
        if ($handle === null) {
            return;
        }
        foreach ($handle['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($handle['process'])) {
            @proc_terminate($handle['process']);
            @proc_close($handle['process']);
        }
    }

    public function test_validator_rejects_whitespace_or_empty_queue_pools_and_restores_global_container(): void
    {
        $dbConfig = (array) config('database');
        $queueConfig = (array) config('queue');
        $subConfig = array_merge((array) config('subscription'), [
            'hwid_async_metadata' => true,
            'hwid_observation_worker_enabled' => true,
        ]);
        $horizonBase = (array) config('horizon');

        $trailingEmpty = $horizonBase;
        $trailingEmpty['environments']['production']['hwid-observation'] = [
            'connection' => 'hwid_observation_worker',
            'queue' => ['hwid_observation', ''],
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'tries' => 3,
            'timeout' => 45,
        ];
        try {
            HwidObservationConfigValidator::assertQueueParity($dbConfig, $queueConfig, $subConfig, $trailingEmpty, 'production');
            $this->fail('Expected trailing empty queue element to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('dedicated 1-worker contract', $e->getMessage());
        }
        $this->assertSame(
            HwidObservationConfigValidator::calculateEffectiveHorizonMinWorkers($horizonBase, 'production') + 2,
            HwidObservationConfigValidator::calculateEffectiveHorizonMinWorkers($trailingEmpty, 'production')
        );

        $spacedQueue = $horizonBase;
        $spacedQueue['environments']['production']['hwid-observation'] = [
            'connection' => 'hwid_observation_worker',
            'queue' => ' hwid_observation ',
            'balance' => 'simple',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'tries' => 3,
            'timeout' => 45,
        ];
        try {
            HwidObservationConfigValidator::assertQueueParity($dbConfig, $queueConfig, $subConfig, $spacedQueue, 'production');
            $this->fail('Expected whitespace-padded queue name to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('dedicated 1-worker contract', $e->getMessage());
        }

        $beforeInstance = \Illuminate\Container\Container::getInstance();
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xboard-container-preserve-' . uniqid('', true);
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . DIRECTORY_SEPARATOR . '.env', "APP_ENV=production\nSUBSCRIPTION_HWID_ASYNC_METADATA=false\nSUBSCRIPTION_HWID_OBSERVATION_WORKER_ENABLED=false\n");
        try {
            $resolved = HwidObservationConfigValidator::resolveAndValidateBootstrapSwitches(base_path(), $tempDir, '.env');
            $this->assertFalse($resolved['hwid_async_metadata']);
            $this->assertSame($beforeInstance, \Illuminate\Container\Container::getInstance());
        } finally {
            @unlink($tempDir . DIRECTORY_SEPARATOR . '.env');
            @rmdir($tempDir);
        }

        $rollbackScript = (string) file_get_contents(base_path('.github/scripts/rollback-xboard-live-green.sh'));
        $v2CommonScript = (string) file_get_contents(base_path('.github/scripts/v2-low-memory-common.sh'));
        $this->assertStringContainsString('hwid_observation_worker_connection', $rollbackScript);
        $this->assertStringContainsString('hwid_observation_worker_connection', $v2CommonScript);
    }
}
