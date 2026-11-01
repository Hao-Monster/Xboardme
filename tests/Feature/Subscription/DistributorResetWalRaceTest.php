<?php

namespace Tests\Feature\Subscription;

use App\Models\DistributorOrder;
use App\Services\DistributorSubscriptionResetService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\InteractsWithIsolatedSubscriptionSqlite;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class DistributorResetWalRaceTest extends TestCase
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

    public static function deviceStates(): array
    {
        return ['already-registered' => [true, 403], 'new-device' => [false, 404]];
    }

    #[DataProvider('deviceStates')]
    public function test_request_authenticated_in_another_process_cannot_survive_reset(bool $registered, int $expectedStatus): void
    {
        $dbPath = $this->bootIsolatedFileSqliteWal();
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->makeOnlineServer();
        $fixture['delivery']->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'claimed_at' => 1700000000,
            'config_issued_at' => 1700000001,
        ]);
        if ($registered) {
            $this->seedRegisteredHwidDevice($fixture['delivery'], 'wal-reset-old-device');
        }

        $barrierDir = dirname($dbPath) . DIRECTORY_SEPARATOR . 'reset-barriers';
        mkdir($barrierDir);
        $configPath = dirname($dbPath) . DIRECTORY_SEPARATOR . 'reset-request.json';
        file_put_contents($configPath, json_encode([
            'db_path' => $dbPath,
            'redis_host' => self::$redisServer->host(),
            'redis_port' => self::$redisServer->port(),
            'hwid_async_metadata' => true,
            'uri' => '/api/v1/client/subscribe?token=' . $fixture['subscriber']->token,
            'headers' => ['X-HWID' => 'wal-reset-old-device', 'User-Agent' => 'ClashMeta/ResetRace'],
            'barrier_dir' => $barrierDir,
            'worker_id' => '1',
            'peer_count' => 2,
            'barriers' => ['after_hwid_left_join' => ['sleep_ms' => 0]],
        ], JSON_THROW_ON_ERROR));

        $process = proc_open([
            PHP_BINARY, base_path('tests/Support/sqlite-subscription-worker.php'), 'subscribe_request', $configPath,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);

        try {
            $readyPath = $barrierDir . DIRECTORY_SEPARATOR . 'after_hwid_left_join_worker_1.ready';
            $deadline = microtime(true) + 3;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(2000);
            }
            $this->assertFileExists($readyPath, 'The old request must authenticate and read its HWID before reset.');

            app(DistributorSubscriptionResetService::class)->reset(
                $fixture['distributor'],
                $fixture['order']->trade_no,
                DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']),
                bin2hex(random_bytes(16))
            );
            touch($barrierDir . DIRECTORY_SEPARATOR . 'after_hwid_left_join_worker_2.ready');
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $process = null;
            $this->assertSame(0, $exit, $stderr);
            $result = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($expectedStatus, $result['status']);
            $this->assertSame(0, $result['body_bytes']);
            $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());
            $this->assertSame(1700000001, $fixture['delivery']->fresh()->config_issued_at);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach (array_slice($pipes, 1) as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($process);
            }
        }
    }
}
