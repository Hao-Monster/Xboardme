<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionResetService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__ . '/../../vendor/autoload.php';
$payload = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
foreach ([
    'APP_ENV' => 'testing', 'APP_KEY' => $payload['app_key'], 'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:', 'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array',
    'CACHE_SETTINGS_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

try {
    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => $payload['db_path'],
        'database.connections.sqlite.busy_timeout' => 5000,
        'database.connections.sqlite.journal_mode' => 'WAL',
        'database.connections.sqlite.synchronous' => 'NORMAL',
    ]);
    DB::purge('sqlite');
    Bus::fake();
    Redis::shouldReceive('sadd')->andReturn(1);
    $actor = User::findOrFail($payload['distributor_id']);
    $barrier = $payload['barrier_dir'];
    $id = (int) $payload['worker_id'];
    $wait = static function (callable $condition): void {
        $deadline = microtime(true) + 8;
        while (!$condition()) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Reset concurrency barrier timed out.');
            }
            usleep(5000);
        }
    };
    touch("{$barrier}/ready-{$id}");
    $wait(static fn (): bool => count(glob("{$barrier}/ready-*") ?: []) === (int) $payload['peer_count']);

    if ($id === 0) {
        $held = false;
        DB::listen(static function (QueryExecuted $query) use (&$held, $barrier, $wait, $payload): void {
            if ($held || !str_starts_with(strtolower($query->sql), 'update "v2_user"')
                || !str_contains(strtolower($query->sql), '"token"')) {
                return;
            }
            $held = true;
            // The reset owns SQLite's write lock here. All peers must attempt their writes before release.
            touch("{$barrier}/reset-locked");
            $wait(static fn (): bool => count(glob("{$barrier}/attempt-*") ?: []) === (int) $payload['peer_count'] - 1);
            usleep(200000);
        });
    } else {
        $wait(static fn (): bool => is_file("{$barrier}/reset-locked"));
        touch("{$barrier}/attempt-{$id}");
    }

    $started = microtime(true);
    if ($payload['operation'] === 'reset') {
        $result = app(DistributorSubscriptionResetService::class)->reset(
            $actor, $payload['trade_no'], $payload['credential_version'], $payload['idempotency_key']
        );
        $result = array_intersect_key($result, array_flip(['credential_version', 'replayed', 'cleared_devices']));
    } elseif ($payload['operation'] === 'renew') {
        $renewal = app(DistributorOrderService::class)->renew(
            $actor, $payload['trade_no'], Plan::PERIOD_QUARTERLY, $payload['idempotency_key']
        );
        $result = ['expired_at' => $renewal->entitlement_expired_at_after];
    } elseif ($payload['operation'] === 'traffic') {
        (new TrafficFetchJob(
            ['id' => 1, 'name' => 'Reset race node', 'type' => 'socks', 'rate' => 1],
            [$payload['subscriber_id'] => [1024, 2048]], 'socks', time()
        ))->handle();
        $result = [];
    } else {
        throw new RuntimeException('Unknown reset test worker operation.');
    }
    echo json_encode(['status' => 200, 'data' => $result, 'elapsed_ms' => (microtime(true) - $started) * 1000], JSON_THROW_ON_ERROR);
} catch (ApiException $exception) {
    echo json_encode(['status' => $exception->getCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage());
    exit(1);
}
