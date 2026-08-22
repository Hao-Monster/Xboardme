<?php

namespace App\Console\Commands;

use App\Support\RuntimeRole;
use App\Utils\CacheKey;
use App\WebSocket\NodeWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RuntimeHealth extends Command
{
    protected $signature = 'runtime:health {role? : legacy | web | ws | horizon | scheduler | maintenance}';

    protected $description = 'Report role-aware runtime dependency health as JSON';

    public function handle(): int
    {
        $role = RuntimeRole::normalize((string) ($this->argument('role') ?: config('app.runtime_role')));
        $checks = [
            'database' => $this->databaseHealthy(),
            'redis' => $this->redisHealthy(),
            'release_identity' => $this->releaseIdentityHealthy(),
        ];

        if ($role === RuntimeRole::WS) {
            $checks['ws_process'] = $this->freshCacheTimestamp(NodeWorker::HEARTBEAT_CACHE_KEY, 30);
            $checks['ws_redis_subscription'] = $this->freshCacheTimestamp(NodeWorker::REDIS_READY_CACHE_KEY, 30);
        }

        if ($role === RuntimeRole::SCHEDULER) {
            $checks['scheduler_tick'] = $this->freshCacheTimestamp(
                CacheKey::get('SCHEDULE_LAST_CHECK_AT', null),
                120
            );
        }

        $payload = [
            'healthy' => !in_array(false, $checks, true),
            'role' => $role,
            'revision' => (string) config('app.version'),
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ];

        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $payload['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    private function databaseHealthy(): bool
    {
        try {
            DB::select('SELECT 1 AS runtime_health');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisHealthy(): bool
    {
        try {
            $pong = Redis::connection('default')->ping();

            return in_array($pong, [true, 'PONG', '+PONG'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseIdentityHealthy(): bool
    {
        $revision = trim((string) config('app.version'));

        return $revision !== '' && !str_contains(strtolower($revision), 'unknown');
    }

    private function freshCacheTimestamp(string $key, int $maximumAge): bool
    {
        try {
            $timestamp = Cache::get($key);
            if (!is_numeric($timestamp)) {
                return false;
            }

            $age = time() - (int) $timestamp;

            return $age >= 0 && $age <= $maximumAge;
        } catch (Throwable) {
            return false;
        }
    }
}
