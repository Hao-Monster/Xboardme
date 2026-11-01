<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait InteractsWithIsolatedSubscriptionSqlite
{
    private ?string $isolatedSqliteDir = null;

    private ?string $isolatedSqlitePath = null;

    private ?string $previousDefaultDbConnection = null;

    /** @var list<array{proc: resource, pipes: array<int, resource>}> */
    private array $activeChildProcesses = [];

    protected function bootIsolatedFileSqliteWal(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xboard_sub_wal_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create isolated SQLite temp dir: {$dir}");
        }

        $dbPath = $dir . DIRECTORY_SEPARATOR . 'test.sqlite';
        touch($dbPath);

        $this->isolatedSqliteDir = $dir;
        $this->isolatedSqlitePath = $dbPath;
        $this->previousDefaultDbConnection = (string) config('database.default');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $dbPath,
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.journal_mode' => 'WAL',
            'database.connections.sqlite.synchronous' => 'NORMAL',
        ]);

        DB::purge('sqlite');
        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        return $dbPath;
    }

    protected function cleanupIsolatedFileSqliteWal(): void
    {
        foreach ($this->activeChildProcesses as $entry) {
            foreach ($entry['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if (is_resource($entry['proc'])) {
                @proc_terminate($entry['proc']);
                @proc_close($entry['proc']);
            }
        }
        $this->activeChildProcesses = [];

        if ($this->isolatedSqliteDir === null) {
            return;
        }

        try {
            DB::disconnect('sqlite');
            DB::purge('sqlite');
        } catch (\Throwable) {
        }

        if (is_dir($this->isolatedSqliteDir)) {
            $items = scandir($this->isolatedSqliteDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $this->isolatedSqliteDir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($full)) {
                    $subItems = scandir($full) ?: [];
                    foreach ($subItems as $sub) {
                        if ($sub !== '.' && $sub !== '..') {
                            @unlink($full . DIRECTORY_SEPARATOR . $sub);
                        }
                    }
                    @rmdir($full);
                } else {
                    @unlink($full);
                }
            }
            @rmdir($this->isolatedSqliteDir);
        }

        $this->isolatedSqliteDir = null;
        $this->isolatedSqlitePath = null;
        if ($this->previousDefaultDbConnection !== null && $this->previousDefaultDbConnection !== '') {
            config(['database.default' => $this->previousDefaultDbConnection]);
        }
        $this->previousDefaultDbConnection = null;
    }

    /**
     * @return array{proc: resource, pipes: array<int, resource>}
     */
    protected function spawnSqliteWriteLockHolder(string $dbPath, int $holdMs = 1800): array
    {
        $cfgPath = $this->writeWorkerConfig([
            'db_path' => $dbPath,
            'hold_ms' => $holdMs,
        ]);

        $workerScript = base_path('tests/Support/sqlite-subscription-worker.php');
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([PHP_BINARY, $workerScript, 'hold_write_lock', $cfgPath], $descriptors, $pipes, base_path());
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to spawn SQLite write-lock holder process.');
        }

        stream_set_timeout($pipes[1], 5);
        $line = fgets($pipes[1]);
        if (!is_string($line) || trim($line) !== 'LOCKED') {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            @proc_terminate($proc);
            @proc_close($proc);
            throw new RuntimeException("SQLite write-lock holder did not acquire lock: {$stderr}");
        }

        $handle = ['proc' => $proc, 'pipes' => $pipes];
        $this->activeChildProcesses[] = $handle;

        return $handle;
    }

    /**
     * @param array{proc: resource, pipes: array<int, resource>} $handle
     */
    protected function releaseSqliteWriteLockHolder(array $handle): void
    {
        if (isset($handle['pipes'][0]) && is_resource($handle['pipes'][0])) {
            @fwrite($handle['pipes'][0], "RELEASE\n");
            @fflush($handle['pipes'][0]);
            @fclose($handle['pipes'][0]);
        }
        if (isset($handle['pipes'][1]) && is_resource($handle['pipes'][1])) {
            @stream_get_contents($handle['pipes'][1]);
            @fclose($handle['pipes'][1]);
        }
        if (isset($handle['pipes'][2]) && is_resource($handle['pipes'][2])) {
            @fclose($handle['pipes'][2]);
        }
        if (is_resource($handle['proc'])) {
            @proc_close($handle['proc']);
        }
    }

    /**
     * @param list<array<string, mixed>> $workerConfigs
     * @return list<array<string, mixed>>
     */
    protected function runConcurrentSubscribeWorkers(array $workerConfigs): array
    {
        $barrierDir = ($this->isolatedSqliteDir ?? sys_get_temp_dir())
            . DIRECTORY_SEPARATOR . 'barrier_' . bin2hex(random_bytes(6));
        if (!mkdir($barrierDir, 0777, true) && !is_dir($barrierDir)) {
            throw new RuntimeException("Unable to create barrier directory: {$barrierDir}");
        }

        $peerCount = count($workerConfigs);
        $workerScript = base_path('tests/Support/sqlite-subscription-worker.php');
        $spawned = [];

        foreach ($workerConfigs as $idx => $cfg) {
            $cfg['barrier_dir'] = $barrierDir;
            $cfg['worker_id'] = (string) ($idx + 1);
            $cfg['peer_count'] = $peerCount;
            $cfgPath = $this->writeWorkerConfig($cfg);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open([PHP_BINARY, $workerScript, 'subscribe_request', $cfgPath], $descriptors, $pipes, base_path());
            if (!is_resource($proc)) {
                throw new RuntimeException('Failed to spawn subscribe_request worker.');
            }
            fclose($pipes[0]);
            $spawned[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($spawned as $entry) {
            $stdout = stream_get_contents($entry['pipes'][1]) ?: '';
            $stderr = stream_get_contents($entry['pipes'][2]) ?: '';
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            $exitCode = proc_close($entry['proc']);

            if ($exitCode !== 0 || trim($stdout) === '') {
                throw new RuntimeException("Concurrent subscribe worker failed (exit {$exitCode}): {$stderr} | stdout: {$stdout}");
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode(trim($stdout), true, 32, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeWorkerConfig(array $config): string
    {
        $baseDir = $this->isolatedSqliteDir ?? sys_get_temp_dir();
        $path = $baseDir . DIRECTORY_SEPARATOR . 'worker_cfg_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
