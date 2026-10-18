<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Diagnostics\DiagnosticLeaseService;
use App\Services\NodeConnectionOwnership;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Deterministic in-memory Redis + Lua state machine emulator for PHPUnit Feature/Unit tests
 * on hosts without a local Redis daemon. Mirrors Redis TIME, TTL expiration, ZSETs, SETs, and Lua scripts.
 */
final class FakeDiagnosticRedis
{
    /** @var array<string, array{value: string, expires_at_ms: int|null}> */
    public array $kv = [];

    /** @var array<string, array<string, float>> */
    public array $zsets = [];

    /** @var array<string, array<string, bool>> */
    public array $sets = [];

    /** @var list<array{channel: string, payload: array<string, mixed>|string}> */
    public array $published = [];

    public int $nowMs = 1_792_317_600_000; // 2026-10-18T10:00:00Z
    public bool $unavailable = false;

    public function install(): self
    {
        $self = $this;

        Redis::shouldReceive('command')
            ->andReturnUsing(function (string $method, array $args = []) use ($self) {
                return $self->handleCommand($method, $args);
            });

        Redis::shouldReceive('get')
            ->andReturnUsing(function (string $key) use ($self) {
                $self->assertAvailable();
                return $self->rawGet($key);
            });

        Redis::shouldReceive('set')
            ->andReturnUsing(function (string $key, mixed $value) use ($self) {
                $self->assertAvailable();
                $self->rawSet($key, (string) $value, null);
                return true;
            });

        Redis::shouldReceive('setex')
            ->andReturnUsing(function (string $key, int $ttlSec, mixed $value) use ($self) {
                $self->assertAvailable();
                $self->rawSet($key, (string) $value, $ttlSec);
                return true;
            });

        Redis::shouldReceive('del')
            ->andReturnUsing(function (string ...$keys) use ($self) {
                $self->assertAvailable();
                $deleted = 0;
                foreach ($keys as $key) {
                    if ($self->rawDel($key)) {
                        $deleted++;
                    }
                }
                return $deleted;
            });

        Redis::shouldReceive('exists')
            ->andReturnUsing(function (string $key) use ($self) {
                $self->assertAvailable();
                return $self->rawGet($key) !== null ? 1 : 0;
            });

        Redis::shouldReceive('expire')
            ->andReturnUsing(function (string $key, int $ttlSec) use ($self) {
                $self->assertAvailable();
                if (isset($self->kv[$key])) {
                    $self->kv[$key]['expires_at_ms'] = $self->nowMs + ($ttlSec * 1000);
                    return 1;
                }
                return 0;
            });

        Redis::shouldReceive('zadd')
            ->andReturnUsing(function (string $key, float|int $score, string $member) use ($self) {
                $self->assertAvailable();
                $self->zsets[$key][$member] = (float) $score;
                return 1;
            });

        Redis::shouldReceive('zrange')
            ->andReturnUsing(function (string $key, int $start, int $stop) use ($self) {
                $self->assertAvailable();
                $set = $self->zsets[$key] ?? [];
                asort($set, SORT_NUMERIC);
                $members = array_keys($set);
                if ($stop < 0) {
                    $length = count($members) + $stop + 1 - $start;
                    return $length > 0 ? array_slice($members, $start, $length) : [];
                }
                return array_slice($members, $start, $stop - $start + 1);
            });

        Redis::shouldReceive('zrem')
            ->andReturnUsing(function (string $key, string $member) use ($self) {
                $self->assertAvailable();
                if (isset($self->zsets[$key][$member])) {
                    unset($self->zsets[$key][$member]);
                    return 1;
                }
                return 0;
            });

        Redis::shouldReceive('sadd')
            ->andReturnUsing(function (string $key, int|string $member) use ($self) {
                $self->assertAvailable();
                $self->sets[$key][(string) $member] = true;
                return 1;
            });

        Redis::shouldReceive('incr')
            ->andReturnUsing(function (string $key) use ($self) {
                $self->assertAvailable();
                $cur = (int) ($self->rawGet($key) ?? 0);
                $next = $cur + 1;
                $existingTtl = $self->kv[$key]['expires_at_ms'] ?? null;
                $self->kv[$key] = ['value' => (string) $next, 'expires_at_ms' => $existingTtl];
                return $next;
            });

        Redis::shouldReceive('decr')
            ->andReturnUsing(function (string $key) use ($self) {
                $self->assertAvailable();
                $cur = (int) ($self->rawGet($key) ?? 0);
                $next = $cur - 1;
                $existingTtl = $self->kv[$key]['expires_at_ms'] ?? null;
                $self->kv[$key] = ['value' => (string) $next, 'expires_at_ms' => $existingTtl];
                return $next;
            });

        Redis::shouldReceive('publish')
            ->andReturnUsing(function (string $channel, string $message) use ($self) {
                $self->assertAvailable();
                $decoded = json_decode($message, true);
                $self->published[] = [
                    'channel' => $channel,
                    'payload' => is_array($decoded) ? $decoded : $message,
                ];
                return 1;
            });

        return $this;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->nowMs += ($seconds * 1000);
    }

    private function assertAvailable(): void
    {
        if ($this->unavailable) {
            throw new RuntimeException('Simulated Redis connection failure');
        }
    }

    public function rawGet(string $key): ?string
    {
        if (!isset($this->kv[$key])) {
            return null;
        }
        $entry = $this->kv[$key];
        if ($entry['expires_at_ms'] !== null && $this->nowMs >= $entry['expires_at_ms']) {
            unset($this->kv[$key]);
            return null;
        }
        return $entry['value'];
    }

    public function rawSet(string $key, string $value, ?int $ttlSec): void
    {
        $this->kv[$key] = [
            'value' => $value,
            'expires_at_ms' => $ttlSec !== null ? ($this->nowMs + ($ttlSec * 1000)) : null,
        ];
    }

    public function rawDel(string $key): bool
    {
        $existed = isset($this->kv[$key]) || isset($this->zsets[$key]) || isset($this->sets[$key]);
        unset($this->kv[$key], $this->zsets[$key], $this->sets[$key]);
        return $existed;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function handleCommand(string $method, array $args): mixed
    {
        $this->assertAvailable();
        $lower = strtolower($method);

        if ($lower === 'time') {
            $sec = intdiv($this->nowMs, 1000);
            $usec = ($this->nowMs % 1000) * 1000;
            return [(string) $sec, (string) $usec];
        }

        if ($lower === 'eval') {
            $script = (string) ($args[0] ?? '');
            /** @var list<string> $keysAndArgs */
            $keysAndArgs = array_values(array_map('strval', (array) ($args[1] ?? [])));
            $numKeys = (int) ($args[2] ?? 0);
            $keys = array_slice($keysAndArgs, 0, $numKeys);
            $argv = array_slice($keysAndArgs, $numKeys);

            return $this->executeLuaScript($script, $keys, $argv);
        }

        throw new RuntimeException('Unsupported Redis command in FakeDiagnosticRedis: ' . $method);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function executeLuaScript(string $script, array $keys, array $argv): mixed
    {
        // NodeConnectionOwnership scripts
        if (str_contains($script, "for _, key in ipairs(KEYS) do\n    redis.call('SET', key, ARGV[1], 'EX', ARGV[2])")) {
            foreach ($keys as $k) {
                $this->rawSet($k, $argv[0], (int) $argv[1]);
            }
            return count($keys);
        }

        if (str_contains($script, "if redis.call('GET', KEYS[1]) ~= ARGV[1] then\n    return 0\nend\nreturn redis.call('DEL', KEYS[1])")) {
            if ($this->rawGet($keys[0]) !== $argv[0]) {
                return 0;
            }
            return $this->rawDel($keys[0]) ? 1 : 0;
        }

        if (str_contains($script, "for _, key in ipairs(KEYS) do\n    if redis.call('GET', key) ~= ARGV[1] then\n        return 0")) {
            foreach ($keys as $k) {
                if ($this->rawGet($k) !== $argv[0]) {
                    return 0;
                }
            }
            return 1;
        }

        // DiagnosticLeaseService scripts
        if (str_contains($script, 'max_active_sessions_exceeded')) {
            return $this->evalStartScript($keys, $argv);
        }

        if (str_contains($script, 'session.stopped_control_version = nextVersion')) {
            return $this->evalStopScript($keys, $argv);
        }

        if (str_contains($script, 'session.eligible_node_ids = cjson.decode(newEligibleNodesJson)')) {
            return $this->evalReconcileScript($keys, $argv);
        }

        if (str_contains($script, 'local bindRecord = {')) {
            return $this->evalSnapshotScript($keys, $argv);
        }

        if (str_contains($script, 'revoked_by_snapshot')) {
            return $this->evalAckScript($keys, $argv);
        }

        if (str_contains($script, "redis.call('DEL', activeKey)") && count($keys) === 2) {
            return $this->evalResetEpochScript($keys, $argv);
        }

        throw new RuntimeException('Unrecognized Lua script in FakeDiagnosticRedis');
    }

    private function purgeExpiredActiveZset(string $activeKey, int $nowMs): void
    {
        if (!isset($this->zsets[$activeKey])) {
            return;
        }
        foreach ($this->zsets[$activeKey] as $member => $score) {
            if ($score <= $nowMs) {
                unset($this->zsets[$activeKey][$member]);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalStartScript(array $keys, array $argv): string
    {
        [$controlKey, $activeKey, $subjectKey, $idempKey] = $keys;
        [
            $newEpochCandidate,
            $newSessionId,
            $diagnosticId,
            $subscriberUserId,
            $orderId,
            $adminId,
            $idempFingerprint,
            $eligibleNodesJson,
            $maxSessions,
            $leaseDurationMs,
            $retentionTtlSec,
            $forcedNowMs,
            $clockToleranceMs,
        ] = $argv;

        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;
        $controlRaw = $this->rawGet($controlKey);
        $control = $controlRaw !== null ? json_decode($controlRaw, true) : null;

        if (!is_array($control) || !isset($control['epoch'], $control['control_version'])) {
            $this->rawDel($activeKey);
            $control = [
                'epoch' => $newEpochCandidate,
                'control_version' => '0',
                'last_server_time_ms' => $nowMs,
            ];
        } else {
            $lastMs = (int) ($control['last_server_time_ms'] ?? 0);
            if ($lastMs > 0 && ($nowMs + (int) $clockToleranceMs) < $lastMs) {
                return json_encode([
                    'err' => 'clock_rollback',
                    'last_server_time_ms' => $lastMs,
                    'now_ms' => $nowMs,
                ], JSON_THROW_ON_ERROR);
            }
            if ($nowMs > $lastMs) {
                $control['last_server_time_ms'] = $nowMs;
            }
        }

        $this->purgeExpiredActiveZset($activeKey, $nowMs);

        $existingIdempRaw = $this->rawGet($idempKey);
        if ($existingIdempRaw !== null) {
            $existingIdemp = json_decode($existingIdempRaw, true);
            if (($existingIdemp['fingerprint'] ?? '') !== $idempFingerprint) {
                return json_encode(['err' => 'idempotency_conflict'], JSON_THROW_ON_ERROR);
            }
            $boundSessionId = (string) ($existingIdemp['session_id'] ?? '');
            $boundSessionRaw = $boundSessionId !== ''
                ? $this->rawGet(DiagnosticLeaseService::PREFIX_SESSION . $boundSessionId)
                : null;
            if ($boundSessionRaw !== null) {
                $boundSession = json_decode($boundSessionRaw, true);
                $inActive = isset($this->zsets[$activeKey][$boundSessionId]);
                if (
                    is_array($boundSession)
                    && ($boundSession['created_control_epoch'] ?? '') === $control['epoch']
                    && ($boundSession['enabled'] ?? false) === true
                    && (int) ($boundSession['expires_at_ms'] ?? 0) > $nowMs
                    && $inActive
                ) {
                    $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
                    return json_encode([
                        'ok' => true,
                        'reused' => true,
                        'idempotent' => true,
                        'control_epoch' => $control['epoch'],
                        'control_version' => (string) $control['control_version'],
                        'now_ms' => $nowMs,
                        'session' => $boundSession,
                    ], JSON_THROW_ON_ERROR);
                }
            }
        }

        $existingSessionId = $this->rawGet($subjectKey);
        if ($existingSessionId !== null) {
            $existingSessionRaw = $this->rawGet(DiagnosticLeaseService::PREFIX_SESSION . $existingSessionId);
            if ($existingSessionRaw !== null) {
                $existingSession = json_decode($existingSessionRaw, true);
                $inActive = isset($this->zsets[$activeKey][$existingSessionId]);
                if (
                    is_array($existingSession)
                    && ($existingSession['created_control_epoch'] ?? '') === $control['epoch']
                    && ($existingSession['enabled'] ?? false) === true
                    && (int) ($existingSession['expires_at_ms'] ?? 0) > $nowMs
                    && $inActive
                ) {
                    $this->rawSet($idempKey, json_encode([
                        'fingerprint' => $idempFingerprint,
                        'session_id' => $existingSessionId,
                        'created_at_ms' => $nowMs,
                    ], JSON_THROW_ON_ERROR), (int) $retentionTtlSec);
                    $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
                    return json_encode([
                        'ok' => true,
                        'reused' => true,
                        'idempotent' => false,
                        'control_epoch' => $control['epoch'],
                        'control_version' => (string) $control['control_version'],
                        'now_ms' => $nowMs,
                        'session' => $existingSession,
                    ], JSON_THROW_ON_ERROR);
                }
            }
        }

        $activeCount = count($this->zsets[$activeKey] ?? []);
        if ($activeCount >= (int) $maxSessions) {
            return json_encode(['err' => 'max_active_sessions_exceeded', 'active_count' => $activeCount], JSON_THROW_ON_ERROR);
        }

        $nextVersion = (string) ((int) $control['control_version'] + 1);
        $control['control_version'] = $nextVersion;
        $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);

        $expiresAtMs = $nowMs + (int) $leaseDurationMs;
        $sessionRecord = [
            'session_id' => $newSessionId,
            'diagnostic_id' => $diagnosticId,
            'subscriber_user_id' => (int) $subscriberUserId,
            'order_id' => (int) $orderId,
            'operator_id' => (int) $adminId,
            'created_at_ms' => $nowMs,
            'expires_at_ms' => $expiresAtMs,
            'max_duration_seconds' => intdiv((int) $leaseDurationMs, 1000),
            'revision' => 1,
            'enabled' => true,
            'stopped_at_ms' => null,
            'created_control_epoch' => $control['epoch'],
            'created_control_version' => $nextVersion,
            'updated_control_version' => $nextVersion,
            'eligible_node_ids' => json_decode($eligibleNodesJson, true),
        ];

        $sessionTtlSec = intdiv((int) $leaseDurationMs, 1000) + (int) $retentionTtlSec;
        $this->rawSet(DiagnosticLeaseService::PREFIX_SESSION . $newSessionId, json_encode($sessionRecord, JSON_THROW_ON_ERROR), $sessionTtlSec);
        $this->rawSet($subjectKey, $newSessionId, $sessionTtlSec);
        $this->zsets[$activeKey][$newSessionId] = (float) $expiresAtMs;
        $this->rawSet($idempKey, json_encode([
            'fingerprint' => $idempFingerprint,
            'session_id' => $newSessionId,
            'created_at_ms' => $nowMs,
        ], JSON_THROW_ON_ERROR), (int) $retentionTtlSec);

        return json_encode([
            'ok' => true,
            'reused' => false,
            'idempotent' => false,
            'control_epoch' => $control['epoch'],
            'control_version' => $nextVersion,
            'now_ms' => $nowMs,
            'session' => $sessionRecord,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalStopScript(array $keys, array $argv): string
    {
        [$controlKey, $activeKey, $subjectKey, $sessionKey, $idempKey] = $keys;
        [$newEpochCandidate, $sessionId, $expectedDiagnosticId, $idempFingerprint, $retentionTtlSec, $forcedNowMs] = $argv;

        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;
        $controlRaw = $this->rawGet($controlKey);
        $control = $controlRaw !== null ? json_decode($controlRaw, true) : null;
        if (!is_array($control) || !isset($control['epoch'], $control['control_version'])) {
            $this->rawDel($activeKey);
            $control = [
                'epoch' => $newEpochCandidate,
                'control_version' => '0',
                'last_server_time_ms' => $nowMs,
            ];
        } elseif ($nowMs > (int) ($control['last_server_time_ms'] ?? 0)) {
            $control['last_server_time_ms'] = $nowMs;
        }

        $this->purgeExpiredActiveZset($activeKey, $nowMs);

        $existingIdempRaw = $this->rawGet($idempKey);
        if ($existingIdempRaw !== null) {
            $existingIdemp = json_decode($existingIdempRaw, true);
            if (($existingIdemp['fingerprint'] ?? '') !== $idempFingerprint) {
                return json_encode(['err' => 'idempotency_conflict'], JSON_THROW_ON_ERROR);
            }
        }

        $sessionRaw = $this->rawGet($sessionKey);
        if ($sessionRaw === null) {
            return json_encode(['err' => 'session_not_found'], JSON_THROW_ON_ERROR);
        }

        $session = json_decode($sessionRaw, true);
        if (($session['diagnostic_id'] ?? '') !== $expectedDiagnosticId) {
            return json_encode(['err' => 'session_subject_mismatch'], JSON_THROW_ON_ERROR);
        }

        $stateChanged = false;
        if (($session['enabled'] ?? false) === true) {
            $nextVersion = (string) ((int) $control['control_version'] + 1);
            $control['control_version'] = $nextVersion;
            $session['enabled'] = false;
            $session['stopped_at_ms'] = $nowMs;
            $session['revision'] = (int) ($session['revision'] ?? 1) + 1;
            $session['updated_control_version'] = $nextVersion;
            $session['stopped_control_version'] = $nextVersion;
            $stateChanged = true;
        }

        $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
        unset($this->zsets[$activeKey][$sessionId]);
        $this->rawSet($sessionKey, json_encode($session, JSON_THROW_ON_ERROR), (int) $retentionTtlSec);

        if ($this->rawGet($subjectKey) === $sessionId && isset($this->kv[$subjectKey])) {
            $this->kv[$subjectKey]['expires_at_ms'] = $nowMs + ((int) $retentionTtlSec * 1000);
        }

        $this->rawSet($idempKey, json_encode([
            'fingerprint' => $idempFingerprint,
            'session_id' => $sessionId,
            'created_at_ms' => $nowMs,
        ], JSON_THROW_ON_ERROR), (int) $retentionTtlSec);

        return json_encode([
            'ok' => true,
            'state_changed' => $stateChanged,
            'control_epoch' => $control['epoch'],
            'control_version' => (string) $control['control_version'],
            'now_ms' => $nowMs,
            'session' => $session,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalReconcileScript(array $keys, array $argv): string
    {
        [$controlKey, $activeKey, $sessionKey] = $keys;
        [$sessionId, $newEligibleNodesJson, $retentionTtlSec, $forcedNowMs] = $argv;

        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;
        $controlRaw = $this->rawGet($controlKey);
        $sessionRaw = $this->rawGet($sessionKey);
        if ($controlRaw === null || $sessionRaw === null) {
            return json_encode(['changed' => false], JSON_THROW_ON_ERROR);
        }

        $control = json_decode($controlRaw, true);
        $session = json_decode($sessionRaw, true);
        $inActive = isset($this->zsets[$activeKey][$sessionId]);
        if (
            ($session['created_control_epoch'] ?? '') !== ($control['epoch'] ?? '')
            || ($session['enabled'] ?? false) !== true
            || (int) ($session['expires_at_ms'] ?? 0) <= $nowMs
            || !$inActive
        ) {
            return json_encode(['changed' => false, 'session' => $session], JSON_THROW_ON_ERROR);
        }

        $nextVersion = (string) ((int) $control['control_version'] + 1);
        $control['control_version'] = $nextVersion;
        $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);

        $session['eligible_node_ids'] = json_decode($newEligibleNodesJson, true);
        $session['revision'] = (int) ($session['revision'] ?? 1) + 1;
        $session['updated_control_version'] = $nextVersion;

        $this->rawSet($sessionKey, json_encode($session, JSON_THROW_ON_ERROR), (int) $retentionTtlSec + 900);

        return json_encode([
            'changed' => true,
            'control_epoch' => $control['epoch'],
            'control_version' => $nextVersion,
            'session' => $session,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalSnapshotScript(array $keys, array $argv): string
    {
        [$controlKey, $activeKey, $ackBindKey, $nodeRuntimeKey] = $keys;
        [
            $newEpochCandidate,
            $nodeIdStr,
            $requestId,
            $runtimeId,
            $transport,
            $authPrincipal,
            $wsConnectionId,
            $ackBindTtlSec,
            $nodeStateTtlSec,
            $forcedNowMs,
            $clockToleranceMs,
        ] = $argv;

        $nodeId = (int) $nodeIdStr;
        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;
        $controlRaw = $this->rawGet($controlKey);
        $control = $controlRaw !== null ? json_decode($controlRaw, true) : null;

        if (!is_array($control) || !isset($control['epoch'], $control['control_version'])) {
            $this->rawDel($activeKey);
            $control = [
                'epoch' => $newEpochCandidate,
                'control_version' => '0',
                'last_server_time_ms' => $nowMs,
            ];
            $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
        } else {
            $lastMs = (int) ($control['last_server_time_ms'] ?? 0);
            if ($lastMs > 0 && ($nowMs + (int) $clockToleranceMs) < $lastMs) {
                return json_encode([
                    'err' => 'clock_rollback',
                    'last_server_time_ms' => $lastMs,
                    'now_ms' => $nowMs,
                ], JSON_THROW_ON_ERROR);
            }
            if ($nowMs > $lastMs) {
                $control['last_server_time_ms'] = $nowMs;
                $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
            }
        }

        $this->purgeExpiredActiveZset($activeKey, $nowMs);
        $activeSet = $this->zsets[$activeKey] ?? [];
        asort($activeSet, SORT_NUMERIC);

        $sessions = [];
        $sessionIdList = [];
        foreach (array_keys($activeSet) as $sessionId) {
            $sRaw = $this->rawGet(DiagnosticLeaseService::PREFIX_SESSION . $sessionId);
            if ($sRaw === null) {
                unset($this->zsets[$activeKey][$sessionId]);
                continue;
            }
            $s = json_decode($sRaw, true);
            if (
                ($s['created_control_epoch'] ?? '') === $control['epoch']
                && ($s['enabled'] ?? false) === true
                && (int) ($s['expires_at_ms'] ?? 0) > $nowMs
            ) {
                $eligibleIds = array_map('intval', (array) ($s['eligible_node_ids'] ?? []));
                if (in_array($nodeId, $eligibleIds, true)) {
                    $sessions[] = [
                        'session_id' => $s['session_id'],
                        'diagnostic_id' => $s['diagnostic_id'],
                        'revision' => (int) ($s['revision'] ?? 1),
                        'issued_at_ms' => (int) $s['created_at_ms'],
                        'expires_at_ms' => (int) $s['expires_at_ms'],
                        'max_duration_seconds' => (int) ($s['max_duration_seconds'] ?? 900),
                    ];
                    $sessionIdList[] = $s['session_id'];
                }
            } elseif (($s['created_control_epoch'] ?? '') !== $control['epoch']) {
                unset($this->zsets[$activeKey][$sessionId]);
            }
        }

        $bindRecord = [
            'node_id' => $nodeId,
            'request_id' => $requestId,
            'runtime_id' => $runtimeId,
            'transport' => $transport,
            'auth_principal' => $authPrincipal,
            'ws_connection_id' => $wsConnectionId,
            'control_epoch' => $control['epoch'],
            'control_version' => (string) $control['control_version'],
            'session_ids' => $sessionIdList,
            'issued_at_ms' => $nowMs,
        ];
        $this->rawSet($ackBindKey, json_encode($bindRecord, JSON_THROW_ON_ERROR), (int) $ackBindTtlSec);

        $existingRuntimeRaw = $this->rawGet($nodeRuntimeKey);
        $existingRuntime = $existingRuntimeRaw !== null ? json_decode($existingRuntimeRaw, true) : [];
        $runtimeRecord = [
            'node_id' => $nodeId,
            'runtime_id' => $runtimeId,
            'transport' => $transport,
            'auth_principal' => $authPrincipal,
            'ws_connection_id' => ($transport === 'ws' && $wsConnectionId !== '')
                ? $wsConnectionId
                : ($existingRuntime['ws_connection_id'] ?? ''),
            'updated_at_ms' => $nowMs,
        ];
        $this->rawSet($nodeRuntimeKey, json_encode($runtimeRecord, JSON_THROW_ON_ERROR), (int) $nodeStateTtlSec);

        return json_encode([
            'ok' => true,
            'control_epoch' => $control['epoch'],
            'control_version' => (string) $control['control_version'],
            'server_time_ms' => $nowMs,
            'sessions' => $sessions,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalAckScript(array $keys, array $argv): string
    {
        [$controlKey, $ackBindKey, $nodeAcksKey] = $keys;
        [
            $nodeIdStr,
            $requestId,
            $runtimeId,
            $controlEpoch,
            $controlVersion,
            $transport,
            $authPrincipal,
            $ackSessionsJson,
            $retentionTtlSec,
            $forcedNowMs,
        ] = $argv;

        $nodeId = (int) $nodeIdStr;
        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;

        $bindRaw = $this->rawGet($ackBindKey);
        if ($bindRaw === null) {
            return json_encode(['err' => 'ack_binding_expired'], JSON_THROW_ON_ERROR);
        }

        $bind = json_decode($bindRaw, true);
        if (
            ($bind['request_id'] ?? '') !== $requestId
            || ($bind['runtime_id'] ?? '') !== $runtimeId
            || ($bind['control_epoch'] ?? '') !== $controlEpoch
            || (string) ($bind['control_version'] ?? '') !== (string) $controlVersion
            || ($bind['transport'] ?? '') !== $transport
            || ($bind['auth_principal'] ?? '') !== $authPrincipal
        ) {
            return json_encode(['err' => 'ack_binding_mismatch'], JSON_THROW_ON_ERROR);
        }

        $controlRaw = $this->rawGet($controlKey);
        if ($controlRaw === null) {
            return json_encode(['err' => 'control_epoch_missing'], JSON_THROW_ON_ERROR);
        }
        $control = json_decode($controlRaw, true);
        if (($control['epoch'] ?? '') !== $controlEpoch) {
            return json_encode(['err' => 'stale_control_epoch'], JSON_THROW_ON_ERROR);
        }

        $allowedSet = array_fill_keys((array) ($bind['session_ids'] ?? []), true);
        $ackSessions = json_decode($ackSessionsJson, true) ?: [];
        $updatedCount = 0;

        foreach ($ackSessions as $item) {
            $sid = (string) ($item['session_id'] ?? '');
            if ($sid !== '' && isset($allowedSet[$sid])) {
                $ackKey = DiagnosticLeaseService::PREFIX_ACK . $sid . ':' . $nodeId;
                $prevRaw = $this->rawGet($ackKey);
                $shouldWrite = true;
                if ($prevRaw !== null) {
                    $prev = json_decode($prevRaw, true);
                    if (($prev['control_epoch'] ?? '') === $controlEpoch && (int) ($prev['control_version'] ?? 0) > (int) $controlVersion) {
                        $shouldWrite = false;
                    }
                }
                if ($shouldWrite) {
                    $record = [
                        'session_id' => $sid,
                        'node_id' => $nodeId,
                        'request_id' => $requestId,
                        'runtime_id' => $runtimeId,
                        'control_epoch' => $controlEpoch,
                        'control_version' => (string) $controlVersion,
                        'transport' => $transport,
                        'owner' => $authPrincipal,
                        'revision' => (int) ($item['revision'] ?? 1),
                        'effective' => ($item['effective'] ?? false) === true,
                        'expires_at' => $item['expires_at'] ?? null,
                        'reason' => $item['reason'] ?? null,
                        'acked_at_ms' => $nowMs,
                    ];
                    $this->rawSet($ackKey, json_encode($record, JSON_THROW_ON_ERROR), (int) $retentionTtlSec);
                    $this->sets[$nodeAcksKey][$sid] = true;
                    $updatedCount++;
                }
            }
        }

        $prevTracked = array_keys($this->sets[$nodeAcksKey] ?? []);
        foreach ($prevTracked as $sid) {
            if (!isset($allowedSet[$sid])) {
                $ackKey = DiagnosticLeaseService::PREFIX_ACK . $sid . ':' . $nodeId;
                $prevRaw = $this->rawGet($ackKey);
                if ($prevRaw !== null) {
                    $prev = json_decode($prevRaw, true);
                    $canRevoke = (($prev['control_epoch'] ?? '') !== $controlEpoch)
                        || ((int) $controlVersion >= (int) ($prev['control_version'] ?? 0));
                    if ($canRevoke) {
                        $revoked = [
                            'session_id' => $sid,
                            'node_id' => $nodeId,
                            'request_id' => $requestId,
                            'runtime_id' => $runtimeId,
                            'control_epoch' => $controlEpoch,
                            'control_version' => (string) $controlVersion,
                            'transport' => $transport,
                            'owner' => $authPrincipal,
                            'revision' => (int) ($prev['revision'] ?? 1),
                            'effective' => false,
                            'expires_at' => $prev['expires_at'] ?? null,
                            'reason' => 'revoked_by_snapshot',
                            'acked_at_ms' => $nowMs,
                        ];
                        $this->rawSet($ackKey, json_encode($revoked, JSON_THROW_ON_ERROR), (int) $retentionTtlSec);
                        $updatedCount++;
                    }
                }
            }
        }

        return json_encode([
            'ok' => true,
            'updated_count' => $updatedCount,
            'control_epoch' => $controlEpoch,
            'control_version' => (string) $controlVersion,
            'now_ms' => $nowMs,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $keys
     * @param list<string> $argv
     */
    private function evalResetEpochScript(array $keys, array $argv): string
    {
        [$controlKey, $activeKey] = $keys;
        [$newEpoch, $forcedNowMs] = $argv;
        $nowMs = ((int) $forcedNowMs) > 0 ? (int) $forcedNowMs : $this->nowMs;

        $this->rawDel($activeKey);
        $control = [
            'epoch' => $newEpoch,
            'control_version' => '0',
            'last_server_time_ms' => $nowMs,
        ];
        $this->rawSet($controlKey, json_encode($control, JSON_THROW_ON_ERROR), null);
        return json_encode($control, JSON_THROW_ON_ERROR);
    }
}
