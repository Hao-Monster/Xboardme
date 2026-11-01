<?php

namespace Tests\Feature\Subscription;

use App\Queue\HwidObservationRedisQueue;
use App\Support\HwidObservationConfigValidator;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;
use UnexpectedValueException;

class SubscriptionQueueSafetyTest extends TestCase
{
    public function test_drain_check_rejects_a_job_missed_by_interleaved_legacy_reads(): void
    {
        // Model a delayed -> ready migration between the old separate reads.
        $queue = Mockery::mock(HwidObservationRedisQueue::class);
        $queue->shouldReceive('pendingSize', 'reservedSize', 'delayedSize')->andReturn(0);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')->andReturn(time());
        $queue->shouldReceive('statusSnapshot')->once()->andReturn([
            'ready' => 1, 'reserved' => 0, 'delayed' => 0, 'total' => 1,
            'oldest_created_at' => time(),
        ]);
        Queue::shouldReceive('connection')->with('hwid_observation_worker')->andReturn($queue);

        $exit = Artisan::call('subscription:hwid-queue-status', [
            '--require-empty' => true, '--samples' => 1, '--interval' => 0,
        ]);
        $result = json_decode(trim(Artisan::output()), true, 16, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('not_empty', $result['status']);
        $this->assertSame(1, $result['ready']);
        $this->assertSame(1, $result['total']);
    }

    #[DataProvider('snapshotResponses')]
    public function test_snapshot_uses_one_transaction_and_rejects_invalid_metadata(mixed $response, ?array $expected): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('isCluster')->once()->andReturn(false);
        $connection->shouldReceive('command')->once()->with('multi')->ordered();
        $connection->shouldReceive('command')->once()->with('llen', ['queues:hwid_observation'])->ordered();
        $connection->shouldReceive('command')->once()->with('zcard', ['queues:hwid_observation:reserved'])->ordered();
        $connection->shouldReceive('command')->once()->with('zcard', ['queues:hwid_observation:delayed'])->ordered();
        $connection->shouldReceive('command')->once()->with('lindex', ['queues:hwid_observation', 0])->ordered();
        $connection->shouldReceive('command')->once()->with('exec')->ordered()->andReturn($response);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('default')->twice()->andReturn($connection);
        $queue = new HwidObservationRedisQueue($factory, 'hwid_observation', 'default', 90);

        if ($expected === null) {
            $this->expectException(UnexpectedValueException::class);
        }
        $this->assertSame($expected, $queue->statusSnapshot());
    }

    public static function snapshotResponses(): array
    {
        return [
            'all sets' => [[1, 2, 3, '{"createdAt":1760000000}'], ['ready' => 1, 'reserved' => 2, 'delayed' => 3, 'total' => 6, 'oldest_created_at' => 1760000000]],
            'empty' => [[0, 0, 0, false], ['ready' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0, 'oldest_created_at' => null]],
            'reserved and delayed' => [[0, 1, 2, null], ['ready' => 0, 'reserved' => 1, 'delayed' => 2, 'total' => 3, 'oldest_created_at' => null]],
            'corrupt head' => [[1, 0, 0, 'PRIVATE_BAD_PAYLOAD'], null],
            'missing timestamp' => [[1, 0, 0, '{}'], null],
            'failed command' => [false, null],
        ];
    }

    public function test_snapshot_discards_an_interrupted_transaction_without_masking_the_error(): void
    {
        $original = new \RuntimeException('Original read failure');
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('isCluster')->once()->andReturn(false);
        $connection->shouldReceive('command')->once()->with('multi')->ordered();
        $connection->shouldReceive('command')->once()->with('llen', ['queues:hwid_observation'])->ordered();
        $connection->shouldReceive('command')->once()->with('zcard', ['queues:hwid_observation:reserved'])->ordered()->andThrow($original);
        $connection->shouldReceive('command')->once()->with('discard')->ordered()->andThrow(new \RuntimeException('Connection unavailable'));
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->with('default')->twice()->andReturn($connection);
        $queue = new HwidObservationRedisQueue($factory, 'hwid_observation', 'default', 90);

        try {
            $queue->statusSnapshot();
            $this->fail('Interrupted inspection must not return a zero snapshot.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($original, $exception);
        }
    }

    public function test_snapshot_integration_reads_counts_and_head_from_the_owned_redis_server(): void
    {
        $server = new LoopbackRespRedisServer();
        $server->start();
        try {
            $server->configureApplication($this->app, 'snapshot_test_');
            $redis = Redis::connection('default');
            $redis->rpush('queues:hwid_observation', '{"createdAt":1760000000}');
            $redis->zadd('queues:hwid_observation:reserved', time(), 'reserved');
            $redis->zadd('queues:hwid_observation:delayed', time(), 'delayed');
            $queue = Queue::connection('hwid_observation_worker');
            $server->resetCommandLog();

            $this->assertSame([
                'ready' => 1, 'reserved' => 1, 'delayed' => 1, 'total' => 3,
                'oldest_created_at' => 1760000000,
            ], $queue->statusSnapshot());
            $commands = $server->commandLog();
            $this->assertCount(1, array_filter($commands, static fn ($line) => preg_match('/^DB:\d+ LLEN /', $line) === 1));
            $this->assertCount(2, array_filter($commands, static fn ($line) => preg_match('/^DB:\d+ ZCARD /', $line) === 1));
            $this->assertCount(1, array_filter($commands, static fn ($line) => preg_match('/^DB:\d+ LINDEX /', $line) === 1));
        } finally {
            $server->stop();
        }
    }

    #[DataProvider('horizonPlans')]
    public function test_horizon_requires_the_effective_consumer_plan_only_when_enabled(array $horizon, bool $workerEnabled, bool $valid): void
    {
        config(['app.env' => 'production']);
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        $this->validateHorizon($horizon, $workerEnabled);
        $this->addToAssertionCount(1);
    }

    public static function horizonPlans(): array
    {
        $supervisor = self::supervisor();
        return [
            'missing plan' => [['environments' => []], true, false],
            'missing consumer' => [['environments' => ['production' => ['business' => []]]], true, false],
            'consumer only in another environment' => [['environments' => ['local' => ['hwid-observation' => $supervisor], 'production' => []]], true, false],
            'explicit environment' => [['environments' => ['production' => ['hwid-observation' => $supervisor]]], true, true],
            'wildcard environment' => [['environments' => ['*' => ['hwid-observation' => $supervisor]]], true, true],
            'first wildcard shadows later exact environment' => [['environments' => ['*' => ['business' => []], 'production' => ['hwid-observation' => $supervisor]]], true, false],
            'defaults are merged' => [['defaults' => ['hwid-observation' => $supervisor], 'environments' => ['production' => ['hwid-observation' => []]]], true, true],
            'defaults cannot invent a matching environment' => [['defaults' => ['hwid-observation' => $supervisor], 'environments' => ['local' => []]], true, false],
            'horizon env overrides app env' => [['env' => 'local', 'environments' => ['production' => [], 'local' => ['hwid-observation' => $supervisor]]], true, true],
            'horizon env points to missing consumer' => [['env' => 'local', 'environments' => ['production' => ['hwid-observation' => $supervisor], 'local' => []]], true, false],
            'stage A does not require a consumer' => [['environments' => ['production' => []]], false, true],
        ];
    }

    #[DataProvider('horizonCommandPlans')]
    public function test_horizon_command_environment_and_child_fallback_are_both_validated(bool $defaultPresent, bool $overridePresent, bool $valid): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', 'horizon', '--environment=staging'];
        config(['app.env' => 'production']);
        try {
            if (!$valid) {
                $this->expectException(InvalidArgumentException::class);
            }
            $this->validateHorizon([
                'env' => 'production',
                'environments' => [
                    'production' => $defaultPresent ? ['hwid-observation' => self::supervisor()] : [],
                    'staging' => $overridePresent ? ['hwid-observation' => self::supervisor()] : [],
                ],
            ], true);
            $this->addToAssertionCount(1);
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }

    public static function horizonCommandPlans(): array
    {
        return [
            'override missing consumer' => [true, false, false],
            'master and children have a consumer' => [true, true, true],
            'override cannot hide missing child fallback' => [false, true, false],
        ];
    }

    #[DataProvider('horizonSupervisorAliasCases')]
    public function test_horizon_supervisor_aliases_and_normalized_options_are_enforced(
        array $defaults,
        array $supervisorOverrides,
        bool $valid
    ): void {
        config(['app.env' => 'production']);
        if (!$valid) {
            $this->expectException(InvalidArgumentException::class);
        }

        $base = self::supervisor();
        foreach (array_keys($supervisorOverrides) as $key) {
            if ($supervisorOverrides[$key] === '__UNSET__') {
                unset($base[$key], $supervisorOverrides[$key]);
            }
        }

        $this->validateHorizon([
            'defaults' => $defaults,
            'environments' => [
                'production' => [
                    'hwid-observation' => array_replace($base, $supervisorOverrides),
                ],
            ],
        ], true);
        $this->addToAssertionCount(1);
    }

    public static function horizonSupervisorAliasCases(): array
    {
        return [
            'processes=0 overriding maxProcesses=1' => [[], ['processes' => 0], false],
            'standalone processes=0 without maxProcesses' => [[], ['maxProcesses' => '__UNSET__', 'processes' => 0], false],
            'standalone max_processes=0 without maxProcesses' => [[], ['maxProcesses' => '__UNSET__', 'max_processes' => 0], false],
            'conflicting maxProcesses=1 and max_processes=2' => [[], ['max_processes' => 2], false],
            'conflicting minProcesses=1 and min_processes=2' => [[], ['min_processes' => 2], false],
            'min_processes=0 rejected by Horizon normalization' => [[], ['minProcesses' => '__UNSET__', 'min_processes' => 0], false],
            'defaults maxProcesses=1 shadowed by environment processes=0' => [
                ['hwid-observation' => ['maxProcesses' => 1]],
                ['maxProcesses' => '__UNSET__', 'processes' => 0],
                false,
            ],
            'conflicting tries and maxTries aliases' => [[], ['tries' => 3, 'maxTries' => 1], false],
            'clean single processes=1 alias without maxProcesses' => [[], ['maxProcesses' => '__UNSET__', 'processes' => 1], true],
            'string queue normalized identically to Horizon' => [[], ['queue' => 'hwid_observation'], true],
        ];
    }

    public function test_effective_horizon_min_workers_counts_queue_pools_instead_of_supervisor_keys(): void
    {
        $productionMultiPool = [
            'environments' => [
                'production' => [
                    'data-pipeline' => [
                        'connection' => 'redis',
                        'queue' => ['traffic_fetch', 'stat', 'user_alive_sync'],
                        'balance' => 'auto',
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'business' => [
                        'connection' => 'redis',
                        'queue' => ['default', 'order_handle'],
                        'balance' => 'simple',
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'notification' => [
                        'connection' => 'redis',
                        'queue' => ['send_email', 'send_telegram', 'send_email_mass', 'node_sync'],
                        'balance' => 'auto',
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'hwid-observation' => self::supervisor(),
                ],
            ],
        ];

        $this->assertSame(
            10,
            HwidObservationConfigValidator::calculateEffectiveHorizonMinWorkers($productionMultiPool, 'production')
        );

        $productionSimpleScaled = $productionMultiPool;
        $productionSimpleScaled['environments']['production']['business']['maxProcesses'] = 3;
        $this->assertSame(
            11,
            HwidObservationConfigValidator::calculateEffectiveHorizonMinWorkers($productionSimpleScaled, 'production')
        );

        $singlePoolTopology = [
            'environments' => [
                'production' => [
                    'data-pipeline' => [
                        'connection' => 'redis',
                        'queue' => ['traffic_fetch', 'stat', 'user_alive_sync'],
                        'balance' => false,
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'business' => [
                        'connection' => 'redis',
                        'queue' => ['default', 'order_handle'],
                        'balance' => false,
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'notification' => [
                        'connection' => 'redis',
                        'queue' => ['send_email', 'send_telegram', 'send_email_mass', 'node_sync'],
                        'balance' => false,
                        'minProcesses' => 1,
                        'maxProcesses' => 1,
                    ],
                    'disabled-pool' => [
                        'connection' => 'redis',
                        'queue' => ['extra'],
                        'balance' => 'auto',
                        'minProcesses' => 1,
                        'processes' => 0,
                    ],
                    'hwid-observation' => self::supervisor(),
                ],
            ],
        ];

        $this->assertSame(
            4,
            HwidObservationConfigValidator::calculateEffectiveHorizonMinWorkers($singlePoolTopology, 'production')
        );
    }

    private static function supervisor(): array
    {
        return ['connection' => 'hwid_observation_worker', 'queue' => ['hwid_observation'], 'balance' => 'simple', 'minProcesses' => 1, 'maxProcesses' => 1, 'timeout' => 45];
    }

    private function validateHorizon(array $horizon, bool $workerEnabled): void
    {
        $endpoint = ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0];
        $database = ['redis' => ['client' => 'phpredis', 'default' => $endpoint,
            'hwid_observation_publish' => HwidObservationConfigValidator::buildPublishRedisConnectionConfig($endpoint)]];
        HwidObservationConfigValidator::assertQueueParity($database, (array) config('queue'), [
            'hwid_async_metadata' => $workerEnabled, 'hwid_observation_worker_enabled' => $workerEnabled,
        ], $horizon);
    }
}
