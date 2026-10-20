<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TrafficResetService;
use App\Support\SqliteImmediateTransaction;
use App\Utils\Helper;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\TestCase;

class TransactionRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh', ['--database' => 'sqlite', '--force' => true])
            ->assertExitCode(0);

        $pdo = DB::connection()->getPdo();
        config([
            'database.default' => 'transaction_retry_test',
            'database.connections.transaction_retry_test' => ['driver' => 'mysql'],
        ]);
        // Keep real, isolated SQLite storage and SQL grammar, but exercise the
        // helper's non-SQLite branch and Laravel's actual transaction retry loop.
        DB::extend('transaction_retry_test', static fn (array $config, string $name) =>
            new SQLiteConnection($pdo, ':memory:', '', $config + ['name' => $name])
        );

        Queue::fake();
        Log::spy();
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        DB::purge('transaction_retry_test');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_failed_traffic_reset_does_not_retry_a_mutated_user_model(): void
    {
        $user = $this->createUser();
        $logAttempts = 0;
        DB::connection()->beforeExecuting(function (string $sql) use ($user, &$logAttempts): void {
            if (!str_starts_with($sql, 'insert into "v2_traffic_reset_logs"')) {
                return;
            }

            $logAttempts++;
            if ($logAttempts === 1) {
                // The user UPDATE has really executed before the injected failure.
                $this->assertDatabaseHas('v2_user', [
                    'id' => $user->id, 'u' => 0, 'd' => 0, 'reset_count' => 5,
                ]);
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }
        });

        $this->assertFalse(app(TrafficResetService::class)->performReset($user));

        $this->assertSame(1, $logAttempts);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('v2_user', [
            'id' => $user->id,
            'u' => 120,
            'd' => 340,
            'reset_count' => 4,
            'last_reset_at' => null,
        ]);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_successful_traffic_reset_clears_usage_and_records_one_reset(): void
    {
        $user = $this->createUser();

        $this->assertTrue(app(TrafficResetService::class)->performReset($user));

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('v2_user', [
            'id' => $user->id, 'u' => 0, 'd' => 0, 'reset_count' => 5,
        ]);
        $this->assertDatabaseCount('v2_traffic_reset_logs', 1);
        $this->assertDatabaseHas('v2_traffic_reset_logs', [
            'user_id' => $user->id,
            'old_upload' => 120,
            'old_download' => 340,
            'old_total' => 460,
            'new_total' => 0,
        ]);
        Log::shouldNotHaveReceived('error');
    }

    public function test_explicit_three_attempts_roll_back_failures_and_commit_the_third_attempt(): void
    {
        $user = $this->createUser();
        $attempts = 0;

        $result = SqliteImmediateTransaction::write(function () use ($user, &$attempts): string {
            $attempts++;
            DB::table('v2_user')->where('id', $user->id)->increment('reset_count');
            if ($attempts < 3) {
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }

            return 'committed';
        }, 3);

        $this->assertSame('committed', $result);
        $this->assertSame(3, $attempts);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseHas('v2_user', [
            'id' => $user->id, 'u' => 120, 'd' => 340, 'reset_count' => 5,
        ]);
    }

    private function createUser(): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => 'qa-transaction-retry@example.test',
            'password' => 'test-password',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'u' => 120,
            'd' => 340,
            'reset_count' => 4,
        ]));
    }
}
