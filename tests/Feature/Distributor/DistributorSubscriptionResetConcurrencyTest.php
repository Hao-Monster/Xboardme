<?php

namespace Tests\Feature\Distributor;

use App\Jobs\NodeUserSyncJob;
use App\Models\Order;
use App\Services\DistributorSubscriptionResetService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\InteractsWithIsolatedSubscriptionSqlite;
use Tests\TestCase;

class DistributorSubscriptionResetConcurrencyTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use InteractsWithIsolatedSubscriptionSqlite;

    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = $this->bootIsolatedFileSqliteWal();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->cleanupIsolatedFileSqliteWal();
        parent::tearDown();
    }

    public function test_same_key_waiter_replays_one_committed_reset(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'old-device-concurrent');
        $key = (string) Str::uuid();
        $results = $this->workers($fixture, [
            ['operation' => 'reset', 'idempotency_key' => $key],
            ['operation' => 'reset', 'idempotency_key' => $key],
        ]);

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertFalse($results[0]['data']['replayed']);
        $this->assertTrue($results[1]['data']['replayed']);
        $this->assertSame($results[0]['data']['credential_version'], $results[1]['data']['credential_version']);
        $this->assertGreaterThanOrEqual(150, $results[1]['elapsed_ms']);
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 1);
        $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());
        $this->assertSame($results[0]['data']['credential_version'], DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']->fresh()));
    }

    public function test_only_a_committed_reset_dispatches_node_sync_and_replay_does_not_dispatch_again(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        Bus::fake();
        $service = app(DistributorSubscriptionResetService::class);
        $version = DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']);
        $key = (string) Str::uuid();
        $service->reset($fixture['distributor'], $fixture['order']->trade_no, $version, $key);
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 1);
        $service->reset($fixture['distributor'], $fixture['order']->trade_no, $version, $key);
        Bus::assertDispatchedTimes(NodeUserSyncJob::class, 1);

        Bus::fake();
        DB::unprepared("CREATE TRIGGER fail_reset_sync_audit BEFORE INSERT ON v2_distributor_subscription_reset BEGIN SELECT RAISE(ABORT, 'reset sync fixture failure'); END");
        try {
            $service->reset($fixture['distributor'], $fixture['order']->trade_no,
                DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']->fresh()), (string) Str::uuid());
            $this->fail('Audit failure must roll back reset before any node sync is queued.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('reset sync fixture failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER fail_reset_sync_audit');
        }
        Bus::assertNotDispatched(NodeUserSyncJob::class);
    }

    public function test_different_key_waiter_cannot_reset_the_winners_new_credentials(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $results = $this->workers($fixture, [
            ['operation' => 'reset'], ['operation' => 'reset'],
        ]);
        $this->assertSame([200, 409], array_column($results, 'status'));
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 1);
        $this->assertSame($results[0]['data']['credential_version'], DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']->fresh()));
    }

    public function test_reset_traffic_report_and_renewal_preserve_every_committed_update(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $subscriber = $fixture['subscriber'];
        DB::table('v2_user')->where('id', $subscriber->id)->update(['u' => 111, 'd' => 222]);
        $subscriber->refresh();
        $oldUuid = $subscriber->uuid;
        $oldQuota = $subscriber->transfer_enable;
        $expectedExpiry = Carbon::createFromTimestamp($subscriber->expired_at, config('app.timezone'))->addMonthsNoOverflow(3)->timestamp;
        $results = $this->workers($fixture, [
            ['operation' => 'reset'], ['operation' => 'traffic'], ['operation' => 'renew'],
        ]);

        $this->assertSame([200, 200, 200], array_column($results, 'status'));
        $subscriber->refresh();
        $this->assertSame(111 + 1024, $subscriber->u);
        $this->assertSame(222 + 2048, $subscriber->d);
        $this->assertSame($oldQuota, $subscriber->transfer_enable);
        $this->assertSame($expectedExpiry, $subscriber->expired_at);
        $this->assertNotSame($oldUuid, $subscriber->uuid);
        $this->assertSame($results[0]['data']['credential_version'], DistributorSubscriptionResetService::credentialVersion($subscriber));
        $this->assertSame(2, Order::where('distributor_order_id', $fixture['delivery']->id)->count());
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 1);
    }

    private function workers(array $fixture, array $operations): array
    {
        $dir = dirname($this->dbPath);
        $barrier = $dir . DIRECTORY_SEPARATOR . 'reset-barrier';
        mkdir($barrier);
        $processes = [];
        try {
            foreach ($operations as $id => $operation) {
                $config = array_merge([
                    'db_path' => $this->dbPath, 'app_key' => config('app.key'),
                    'barrier_dir' => $barrier, 'worker_id' => $id, 'peer_count' => count($operations),
                    'distributor_id' => $fixture['distributor']->id, 'subscriber_id' => $fixture['subscriber']->id,
                    'trade_no' => $fixture['order']->trade_no,
                    'credential_version' => DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']),
                    'idempotency_key' => (string) Str::uuid(),
                ], $operation);
                $configPath = "{$dir}/reset-worker-{$id}.json";
                file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR));
                $stdout = "{$dir}/reset-worker-{$id}.out";
                $stderr = "{$dir}/reset-worker-{$id}.err";
                $process = proc_open([PHP_BINARY, base_path('tests/Support/sqlite-distributor-reset-worker.php'), $configPath], [
                    0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w'],
                ], $pipes, base_path());
                if (!is_resource($process)) {
                    throw new RuntimeException('Unable to start reset concurrency worker.');
                }
                fclose($pipes[0]);
                $processes[] = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
            }
            $results = [];
            $deadline = microtime(true) + 20;
            foreach ($processes as $entry) {
                while (proc_get_status($entry['process'])['running']) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Reset concurrency worker deadline exceeded.');
                    }
                    usleep(10000);
                }
                $error = (string) file_get_contents($entry['stderr']);
                $this->assertSame('', $error, 'Worker failed: ' . $error);
                $results[] = json_decode((string) file_get_contents($entry['stdout']), true, 32, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($processes as $entry) {
                if (is_resource($entry['process'])) {
                    if (proc_get_status($entry['process'])['running']) {
                        proc_terminate($entry['process']);
                    }
                    proc_close($entry['process']);
                }
            }
        }
    }
}
