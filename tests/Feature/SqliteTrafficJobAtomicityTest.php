<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\DistributorHwidService;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionNameService;
use App\Support\SqliteImmediateTransaction;
use App\Utils\Helper;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use TypeError;

class SqliteTrafficJobAtomicityTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $databasePath = tempnam(sys_get_temp_dir(), 'xboard-queue-test-');
        if ($databasePath === false) {
            $this->fail('Unable to create the isolated SQLite test database.');
        }

        $this->databasePath = $databasePath;
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);
        DB::purge('sqlite');

        $this->artisan('migrate:fresh', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');

        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_immediate_transaction_reserves_the_sqlite_writer_before_the_first_query(): void
    {
        SqliteImmediateTransaction::run(function (): void {
            $competitor = new PDO('sqlite:' . $this->databasePath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $competitor->exec('PRAGMA busy_timeout = 0');

            try {
                $competitor->exec('BEGIN IMMEDIATE TRANSACTION');
                $this->fail('A competing writer must not enter while the batch owns the write reservation.');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('database is locked', $exception->getMessage());
            }
        });
    }

    public function test_immediate_transaction_synchronizes_connection_level_and_supports_nested_savepoints(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        SqliteImmediateTransaction::run(function (): void {
            $this->assertSame(1, DB::transactionLevel());
            $this->createUser('outer-kept@example.test');

            try {
                DB::transaction(function (): void {
                    $this->assertSame(2, DB::transactionLevel());
                    $this->createUser('savepoint-rolled-back@example.test');
                    throw new RuntimeException('abort inner savepoint only');
                });
                $this->fail('The inner savepoint exception must be thrown.');
            } catch (RuntimeException) {
                $this->assertSame(1, DB::transactionLevel());
            }

            SqliteImmediateTransaction::write(function (): void {
                $this->assertSame(2, DB::transactionLevel());
                $this->createUser('savepoint-committed@example.test');
            });

            $this->assertSame(1, DB::transactionLevel());
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('v2_user', ['email' => 'outer-kept@example.test']);
        $this->assertDatabaseMissing('v2_user', ['email' => 'savepoint-rolled-back@example.test']);
        $this->assertDatabaseHas('v2_user', ['email' => 'savepoint-committed@example.test']);

        try {
            SqliteImmediateTransaction::run(function (): void {
                DB::transaction(function (): void {
                    $this->createUser('outer-rollback-clears-inner@example.test');
                });
                throw new RuntimeException('abort outer immediate transaction');
            });
            $this->fail('The outer immediate transaction exception must be thrown.');
        } catch (RuntimeException) {
            $this->assertSame(0, DB::transactionLevel());
        }

        $this->assertDatabaseMissing('v2_user', ['email' => 'outer-rollback-clears-inner@example.test']);
    }

    public function test_distributor_write_paths_reserve_sqlite_writer_before_first_select_and_recover_savepoint_collisions(): void
    {
        DB::connection()->getPdo()->exec('PRAGMA journal_mode = WAL');
        DB::connection()->getPdo()->exec('PRAGMA busy_timeout = 3000');

        $dealer = User::create([
            'email' => 'sqlite-wal-dealer@example.test',
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => true,
            'distributor_name' => '并发商户',
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ]);
        $plan = Plan::create([
            'group_id' => 1,
            'transfer_enable' => 50,
            'name' => 'WAL Plan',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'distributor_hwid_limit' => 2,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);

        $firstOrder = app(DistributorOrderService::class)->create($dealer, $plan, Plan::PERIOD_MONTHLY, '首单客户');
        $existingCode = (string) $firstOrder->distributorOrder()->value('subscription_code');
        $this->assertNotSame('', $existingCode);
        $this->assertSame(0, DB::transactionLevel());

        // Force a unique short-code collision on the first savepoint attempt inside a top-level
        // SQLite IMMEDIATE order transaction, then succeed on the second attempt.
        $calls = 0;
        $this->app->bind(DistributorSubscriptionNameService::class, function () use ($existingCode, &$calls) {
            return new class($existingCode, $calls) extends DistributorSubscriptionNameService {
                private int $calls;

                public function __construct(
                    private readonly string $collidingCode,
                    int &$calls
                ) {
                    $this->calls = &$calls;
                }

                protected function generateCode(): string
                {
                    $this->calls++;

                    return $this->calls === 1 ? $this->collidingCode : 'WAL999';
                }
            };
        });

        $lockCheckedDuringOrderSelect = false;
        DB::listen(function ($query) use (&$lockCheckedDuringOrderSelect): void {
            if ($lockCheckedDuringOrderSelect || DB::transactionLevel() === 0) {
                return;
            }
            if (!preg_match('/^select\s+\*\s+from\s+["`]?v2_user["`]?/i', trim($query->sql))) {
                return;
            }

            $lockCheckedDuringOrderSelect = true;
            $competitor = new PDO('sqlite:' . $this->databasePath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $competitor->exec('PRAGMA busy_timeout = 0');

            try {
                $competitor->exec('BEGIN IMMEDIATE TRANSACTION');
                $this->fail('DistributorOrderService::create must hold the SQLite write lock before its first SELECT.');
            } catch (PDOException $exception) {
                $this->assertStringContainsString('database is locked', $exception->getMessage());
            }
        });

        $secondOrder = app(DistributorOrderService::class)->create($dealer, $plan, Plan::PERIOD_MONTHLY, '次单客户');
        $this->assertTrue($lockCheckedDuringOrderSelect);
        $this->assertSame(2, $calls);
        $this->assertSame('WAL999', $secondOrder->distributorOrder()->value('subscription_code'));
        $this->assertSame(0, DB::transactionLevel());

        $subscriber = $secondOrder->distributorOrder()->firstOrFail()->subscriber;
        $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], [
            'HTTP_X_HWID' => 'wal-device-hwid-0001',
            'HTTP_USER_AGENT' => 'FlClash/0.8.92',
        ]);
        $result = app(DistributorHwidService::class)->authorizeSubscription($subscriber, $request);
        $this->assertTrue($result['allowed']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_stat_user_sqlite_batch_rolls_back_every_user_when_one_payload_is_invalid(): void
    {
        $job = new StatUserJob(
            ['rate' => 1],
            [101 => [10, 20], 102 => ['invalid', 30]],
            'shadowsocks'
        );

        try {
            $job->handle();
            $this->fail('The invalid payload must fail the complete statistics batch.');
        } catch (TypeError) {
            // The first user's write must be rolled back with the failed batch.
        }

        $this->assertSame(0, DB::table('v2_stat_user')->count());
    }

    public function test_stat_user_sqlite_batch_creates_then_atomically_increments_existing_rows(): void
    {
        $job = new StatUserJob(['rate' => 2], [101 => [10, 20]], 'shadowsocks');

        $job->handle();
        $job->handle();

        $this->assertDatabaseCount('v2_stat_user', 1);
        $this->assertDatabaseHas('v2_stat_user', [
            'user_id' => 101,
            'server_rate' => 2,
            'u' => 40,
            'd' => 80,
            'record_type' => 'd',
        ]);
    }

    public function test_stat_server_sqlite_rolls_back_the_stat_when_server_total_update_fails(): void
    {
        $serverId = $this->createServer();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_server_traffic_update
            BEFORE UPDATE OF u, d ON v2_server
            BEGIN
                SELECT RAISE(ABORT, 'forced server traffic failure');
            END
            SQL);

        $job = new StatServerJob(['id' => $serverId], [101 => [10, 20]], 'shadowsocks');

        try {
            $job->handle();
            $this->fail('The forced server update failure must fail the complete statistics batch.');
        } catch (QueryException) {
            // The statistics row and server totals are one SQLite transaction.
        }

        $this->assertSame(0, DB::table('v2_stat_server')->count());
        $this->assertDatabaseHas('v2_server', ['id' => $serverId, 'u' => 0, 'd' => 0]);
    }

    public function test_traffic_fetch_sqlite_batch_rolls_back_prior_user_increments(): void
    {
        $firstUser = $this->createUser('first@example.test');
        $secondUser = $this->createUser('second@example.test');
        $job = new TrafficFetchJob(
            ['id' => 1, 'rate' => 1],
            [$firstUser => [10, 20], $secondUser => ['invalid', 30]],
            'shadowsocks',
            time()
        );

        try {
            $job->handle();
            $this->fail('The invalid payload must fail the complete traffic batch.');
        } catch (TypeError) {
            // The first user's increment must be rolled back with the failed batch.
        }

        $this->assertDatabaseHas('v2_user', ['id' => $firstUser, 'u' => 0, 'd' => 0]);
        $this->assertDatabaseHas('v2_user', ['id' => $secondUser, 'u' => 0, 'd' => 0]);
    }

    public function test_report_jobs_apply_each_report_chunk_exactly_once(): void
    {
        Redis::shouldReceive('sadd')->twice();

        $userId = $this->createUser('idempotent@example.test');
        $serverId = $this->createServer();
        $reportId = fake()->uuid();
        $server = ['id' => $serverId, 'name' => 'Idempotent Node', 'rate' => 1];
        $traffic = [$userId => [10, 20]];

        $trafficJob = new TrafficFetchJob($server, $traffic, 'shadowsocks', time(), $reportId, 0);
        $statUserJob = new StatUserJob($server, $traffic, 'shadowsocks', 'd', $reportId, 0);
        $statServerJob = new StatServerJob($server, $traffic, 'shadowsocks', 'd', $reportId, 0);

        $trafficJob->handle();
        $trafficJob->handle();
        $statUserJob->handle();
        $statUserJob->handle();
        $statServerJob->handle();
        $statServerJob->handle();

        $this->assertDatabaseHas('v2_user', [
            'id' => $userId,
            'u' => 10,
            'd' => 20,
        ]);
        $this->assertDatabaseHas('v2_stat_user', [
            'user_id' => $userId,
            'u' => 10,
            'd' => 20,
        ]);
        $this->assertDatabaseHas('v2_stat_server', [
            'server_id' => $serverId,
            'u' => 10,
            'd' => 20,
        ]);
        $this->assertDatabaseHas('v2_server', [
            'id' => $serverId,
            'u' => 10,
            'd' => 20,
        ]);
        $this->assertDatabaseCount('v2_server_report_receipt', 3);
    }

    public function test_traffic_fetch_batches_user_increments_instead_of_one_update_per_user(): void
    {
        Redis::shouldReceive('sadd')->once();
        $firstUser = $this->createUser('batch-first@example.test');
        $secondUser = $this->createUser('batch-second@example.test');
        $userUpdateQueries = 0;
        DB::listen(function ($query) use (&$userUpdateQueries): void {
            if (preg_match('/^update\s+["`]?v2_user["`]?\s+set/i', trim($query->sql))) {
                $userUpdateQueries++;
            }
        });

        (new TrafficFetchJob(
            ['id' => 1, 'name' => 'Batch Node', 'rate' => 2],
            [$firstUser => [10, 20], $secondUser => [30, 40]],
            'shadowsocks',
            time()
        ))->handle();

        $this->assertSame(1, $userUpdateQueries);
        $this->assertDatabaseHas('v2_user', ['id' => $firstUser, 'u' => 20, 'd' => 40]);
        $this->assertDatabaseHas('v2_user', ['id' => $secondUser, 'u' => 60, 'd' => 80]);
    }

    private function createUser(string $email): int
    {
        return (int) DB::table('v2_user')->insertGetId([
            'email' => $email,
            'password' => 'test-password',
            'uuid' => fake()->uuid(),
            'token' => bin2hex(random_bytes(16)),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createServer(): int
    {
        return (int) DB::table('v2_server')->insertGetId([
            'type' => 'shadowsocks',
            'code' => 'atomicity-test',
            'name' => 'Atomicity Test',
            'rate' => 1,
            'host' => '127.0.0.1',
            'port' => '443',
            'server_port' => 443,
            'show' => false,
            'u' => 0,
            'd' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
