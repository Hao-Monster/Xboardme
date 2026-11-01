<?php

declare(strict_types=1);

/**
 * Child-process worker for multi-process SQLite WAL concurrency and lock-contention tests.
 *
 * Modes:
 *   1. hold_write_lock <jsonConfigPath>
 *      Opens an independent PDO SQLite connection, executes BEGIN IMMEDIATE,
 *      prints "LOCKED\n" on STDOUT, and holds the write transaction until
 *      STDIN receives "RELEASE" or hold_ms elapses.
 *
 *   2. subscribe_request <jsonConfigPath>
 *      Bootstraps Laravel against the isolated file-backed SQLite WAL database,
 *      synchronizes at configured barriers with sibling worker processes,
 *      dispatches an HTTP request through the HTTP Kernel, and prints a single
 *      JSON result object on STDOUT.
 */

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? '';
$configFile = $argv[2] ?? '';

if ($mode === '' || $configFile === '' || !is_file($configFile)) {
    fwrite(STDERR, "Usage: php sqlite-subscription-worker.php <mode> <jsonConfigPath>\n");
    exit(2);
}

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($configFile), true, 16, JSON_THROW_ON_ERROR);
$dbPath = (string) ($payload['db_path'] ?? '');

if ($mode === 'hold_write_lock') {
    $holdMs = max(100, (int) ($payload['hold_ms'] ?? 1500));
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('BEGIN IMMEDIATE');

    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);

    stream_set_blocking(STDIN, false);
    $deadline = microtime(true) + ($holdMs / 1000.0);
    while (microtime(true) < $deadline) {
        $line = fgets(STDIN);
        if (is_string($line) && str_contains($line, 'RELEASE')) {
            break;
        }
        usleep(5_000);
    }

    $pdo->exec('ROLLBACK');
    fwrite(STDOUT, "RELEASED\n");
    fflush(STDOUT);
    exit(0);
}

if ($mode === 'subscribe_request') {
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE=:memory:');
    putenv('CACHE_STORE=array');
    putenv('CACHE_DRIVER=array');
    putenv('CACHE_SETTINGS_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');

    $_ENV['APP_ENV'] = 'testing';
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_ENV['DB_DATABASE'] = ':memory:';
    $_ENV['CACHE_STORE'] = 'array';
    $_ENV['CACHE_DRIVER'] = 'array';
    $_ENV['CACHE_SETTINGS_STORE'] = 'array';
    $_ENV['SESSION_DRIVER'] = 'array';
    $_ENV['QUEUE_CONNECTION'] = 'sync';
    $_SERVER['APP_ENV'] = 'testing';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_DATABASE'] = ':memory:';
    $_SERVER['CACHE_STORE'] = 'array';
    $_SERVER['CACHE_DRIVER'] = 'array';
    $_SERVER['CACHE_SETTINGS_STORE'] = 'array';
    $_SERVER['SESSION_DRIVER'] = 'array';
    $_SERVER['QUEUE_CONNECTION'] = 'sync';

    require __DIR__ . '/../../vendor/autoload.php';
    $app = require __DIR__ . '/../../bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    $app['config']->set('database.default', 'sqlite');
    $app['config']->set('database.connections.sqlite.database', $dbPath);
    $app['config']->set('database.connections.sqlite.busy_timeout', 5000);
    $app['config']->set('database.connections.sqlite.journal_mode', 'WAL');
    $app['config']->set('database.connections.sqlite.synchronous', 'NORMAL');

    $asyncMetadata = (bool) ($payload['hwid_async_metadata'] ?? false);
    $app['config']->set('subscription.hwid_observation_worker_enabled', $asyncMetadata);
    $app['config']->set('subscription.hwid_async_metadata', $asyncMetadata);
    $app['config']->set('subscription.timing_log_enabled', false);
    $app['config']->set('horizon.environments.testing.hwid-observation', [
        'connection' => 'hwid_observation_worker', 'queue' => ['hwid_observation'],
        'balance' => 'simple', 'minProcesses' => 1, 'maxProcesses' => 1, 'timeout' => 45,
    ]);

    if (!empty($payload['redis_host']) && !empty($payload['redis_port'])) {
        $prefix = (string) ($payload['redis_prefix'] ?? 'xboard_test_database_');
        $app['config']->set('database.redis.client', extension_loaded('redis') ? 'phpredis' : 'loopback-resp');
        $app['config']->set('database.redis.options.prefix', $prefix);
        foreach (['default', 'cache', 'hwid_observation_publish'] as $connName) {
            $app['config']->set("database.redis.{$connName}.url", null);
            $app['config']->set("database.redis.{$connName}.host", (string) $payload['redis_host']);
            $app['config']->set("database.redis.{$connName}.port", (int) $payload['redis_port']);
            $app['config']->set("database.redis.{$connName}.password", null);
            $app['config']->set("database.redis.{$connName}.username", null);
        }
        $app['config']->set('database.redis.default.database', 0);
        $app['config']->set('database.redis.hwid_observation_publish.database', 0);
        $app['config']->set('database.redis.cache.database', 1);
        if (class_exists(\Laravel\Horizon\Horizon::class)) {
            $app['config']->set('horizon.prefix', rtrim($prefix, '_') . '_horizon:');
            \Laravel\Horizon\Horizon::use((string) $app['config']->get('horizon.use', 'default'));
        }
        \Tests\Support\LoopbackRespRedisServer::rebindRedisManager($app);
    }

    DB::purge('sqlite');
    $pdo = DB::connection('sqlite')->getPdo();
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    $barrierDir = (string) ($payload['barrier_dir'] ?? '');
    $workerId = (string) ($payload['worker_id'] ?? '1');
    $peerCount = max(1, (int) ($payload['peer_count'] ?? 2));
    $barriers = is_array($payload['barriers'] ?? null) ? $payload['barriers'] : [];

    $waitBarrier = static function (string $stage, int $afterBarrierSleepMs = 0) use ($barrierDir, $workerId, $peerCount): void {
        if ($barrierDir === '' || !is_dir($barrierDir)) {
            return;
        }
        $marker = $barrierDir . DIRECTORY_SEPARATOR . "{$stage}_worker_{$workerId}.ready";
        file_put_contents($marker, (string) microtime(true));

        $deadline = microtime(true) + 4.0;
        while (microtime(true) < $deadline) {
            $ready = 0;
            for ($i = 1; $i <= $peerCount; $i++) {
                if (is_file($barrierDir . DIRECTORY_SEPARATOR . "{$stage}_worker_{$i}.ready")) {
                    $ready++;
                }
            }
            if ($ready >= $peerCount) {
                break;
            }
            usleep(2_000);
        }

        if ($afterBarrierSleepMs > 0) {
            usleep($afterBarrierSleepMs * 1_000);
        }
    };

    $queries = [];
    $claimBarrierDone = false;
    $issueBarrierDone = false;
    $hwidReadBarrierDone = false;

    // Rendezvous before the delivery transaction reserves SQLite's writer lock.
    // Waiting at UPDATE would make the first worker hold that lock while its
    // peer is blocked before reaching the same barrier.
    DB::connection()->beforeStartingTransaction(static function () use (
        $barriers,
        $waitBarrier,
        &$claimBarrierDone,
        &$issueBarrierDone
    ): void {
        $attributes = [];
        foreach (debug_backtrace(0, 16) as $frame) {
            if (($frame['class'] ?? '') === \App\Http\Controllers\V1\Client\ClientController::class
                && ($frame['function'] ?? '') === 'writeDeliveryForCurrentCredentials') {
                $attributes = $frame['args'][2] ?? [];
                break;
            }
        }
        if (!$claimBarrierDone
            && isset($barriers['before_delivery_claim'])
            && array_key_exists('claimed_at', $attributes)
        ) {
            $claimBarrierDone = true;
            $waitBarrier('before_delivery_claim', (int) ($barriers['before_delivery_claim']['sleep_ms'] ?? 0));
        }

        if (!$issueBarrierDone
            && isset($barriers['before_delivery_issue'])
            && array_key_exists('config_issued_at', $attributes)
        ) {
            $issueBarrierDone = true;
            $waitBarrier('before_delivery_issue', (int) ($barriers['before_delivery_issue']['sleep_ms'] ?? 0));
        }
    });

    DB::listen(static function (QueryExecuted $event) use (
        &$queries,
        $barriers,
        $waitBarrier,
        &$hwidReadBarrierDone
    ): void {
        $queries[] = $event->sql;
        $lower = strtolower($event->sql);
        if (!$hwidReadBarrierDone
            && isset($barriers['after_hwid_left_join'])
            && str_contains($lower, 'select')
            && str_contains($lower, '_matched_hwid_device_id')
        ) {
            $hwidReadBarrierDone = true;
            $waitBarrier('after_hwid_left_join', (int) ($barriers['after_hwid_left_join']['sleep_ms'] ?? 0));
        }
    });

    $uri = (string) ($payload['uri'] ?? '/api/v1/client/subscribe');
    $method = strtoupper((string) ($payload['method'] ?? 'GET'));
    $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
    $remoteAddr = (string) ($payload['remote_addr'] ?? '127.0.0.1');

    $server = ['REMOTE_ADDR' => $remoteAddr];
    foreach ($headers as $name => $val) {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $name));
        $server[$normalized] = (string) $val;
        if (strtolower((string) $name) === 'content-type') {
            $server['CONTENT_TYPE'] = (string) $val;
        }
    }

    /** @var HttpKernel $httpKernel */
    $httpKernel = $app->make(HttpKernel::class);
    $request = Request::create($uri, $method, [], [], [], $server);

    $startedNs = hrtime(true);
    $response = $httpKernel->handle($request);
    $elapsedMs = round((hrtime(true) - $startedNs) / 1_000_000, 3);
    $httpKernel->terminate($request, $response);

    $out = [
        'worker_id' => $workerId,
        'status' => $response->getStatusCode(),
        'elapsed_ms' => $elapsedMs,
        'body_bytes' => strlen((string) $response->getContent()),
        'headers' => [
            'x-hwid-limit' => $response->headers->get('x-hwid-limit'),
            'x-hwid-max-devices-reached' => $response->headers->get('x-hwid-max-devices-reached'),
            'x-hwid-not-supported' => $response->headers->get('x-hwid-not-supported'),
            'x-order-no' => $response->headers->get('x-order-no'),
            'etag' => $response->headers->get('ETag'),
        ],
        'queries' => $queries,
    ];

    DB::disconnect('sqlite');

    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fflush(STDOUT);
    exit(0);
}

fwrite(STDERR, "Unknown worker mode: {$mode}\n");
exit(2);
