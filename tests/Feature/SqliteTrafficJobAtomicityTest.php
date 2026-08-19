<?php

namespace Tests\Feature;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Support\SqliteImmediateTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
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
        config(['database.connections.sqlite.database' => $this->databasePath]);
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
