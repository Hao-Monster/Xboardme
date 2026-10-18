<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeConnectionOwnership;
use App\Services\NodeSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class DiagnosticLeaseService
{
    public const KEY_CONTROL = 'diag:v1:control';
    public const KEY_ACTIVE = 'diag:v1:active';
    public const PREFIX_SUBJECT = 'diag:v1:subject:';
    public const PREFIX_SESSION = 'diag:v1:session:';
    public const PREFIX_ACK = 'diag:v1:ack:';
    public const PREFIX_NODE_ACKS = 'diag:v1:node_acks:';
    public const PREFIX_ACK_BIND = 'diag:v1:ack_bind:';
    public const PREFIX_IDEMPOTENCY = 'diag:v1:idempotency:';
    public const PREFIX_NODE_CAP = 'diag:v1:node_cap:';
    public const PREFIX_NODE_RUNTIME = 'diag:v1:node_runtime:';

    public const LEASE_DURATION_SECONDS = DiagnosticContract::LEASE_TTL_SECONDS;
    public const LEASE_DURATION_MS = 900_000;
    public const RETENTION_TTL_SECONDS = 86_400;
    public const ACK_BIND_TTL_SECONDS = 60;
    public const NODE_STATE_TTL_SECONDS = 86_400;
    public const MAX_CONCURRENT_SESSIONS = DiagnosticContract::MAX_ACTIVE_LEASES_PER_PANEL;
    public const CLOCK_ROLLBACK_TOLERANCE_MS = 1_000;

    private const START_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local activeKey = KEYS[2]
local subjectKey = KEYS[3]
local idempKey = KEYS[4]

local newEpochCandidate = ARGV[1]
local newSessionId = ARGV[2]
local diagnosticId = ARGV[3]
local subscriberUserId = tonumber(ARGV[4])
local orderId = tonumber(ARGV[5])
local adminId = tonumber(ARGV[6])
local idempFingerprint = ARGV[7]
local eligibleNodesJson = ARGV[8]
local maxSessions = tonumber(ARGV[9])
local leaseDurationMs = tonumber(ARGV[10])
local retentionTtlSec = tonumber(ARGV[11])
local forcedNowMs = tonumber(ARGV[12])
local clockToleranceMs = tonumber(ARGV[13])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

local controlRaw = redis.call('GET', controlKey)
local control = nil
if controlRaw then
    control = cjson.decode(controlRaw)
end

if not control or type(control) ~= 'table' or not control.epoch or not control.control_version then
    -- Control metadata missing: never reconstruct active leases from residual sessions
    redis.call('DEL', activeKey)
    control = {
        epoch = newEpochCandidate,
        control_version = '0',
        last_server_time_ms = nowMs
    }
else
    local lastMs = tonumber(control.last_server_time_ms or 0)
    if lastMs > 0 and (nowMs + clockToleranceMs) < lastMs then
        return cjson.encode({ err = 'clock_rollback', last_server_time_ms = lastMs, now_ms = nowMs })
    end
    if nowMs > lastMs then
        control.last_server_time_ms = nowMs
    end
end

-- Clean expired sessions from active ZSET
redis.call('ZREMRANGEBYSCORE', activeKey, '-inf', nowMs)

-- 1. Check idempotency key
local existingIdempRaw = redis.call('GET', idempKey)
if existingIdempRaw then
    local existingIdemp = cjson.decode(existingIdempRaw)
    if existingIdemp.fingerprint ~= idempFingerprint then
        return cjson.encode({ err = 'idempotency_conflict' })
    end
    local boundSessionKey = 'diag:v1:session:' .. existingIdemp.session_id
    local boundSessionRaw = redis.call('GET', boundSessionKey)
    if boundSessionRaw then
        local boundSession = cjson.decode(boundSessionRaw)
        local inActive = redis.call('ZSCORE', activeKey, existingIdemp.session_id)
        if boundSession.created_control_epoch == control.epoch
            and boundSession.enabled == true
            and tonumber(boundSession.expires_at_ms) > nowMs
            and inActive
        then
            redis.call('SET', controlKey, cjson.encode(control))
            return cjson.encode({
                ok = true,
                reused = true,
                idempotent = true,
                control_epoch = control.epoch,
                control_version = tostring(control.control_version),
                now_ms = nowMs,
                session = boundSession
            })
        end
    end
end

-- 2. Check existing active session for subject (do not extend expiration)
local existingSessionId = redis.call('GET', subjectKey)
if existingSessionId then
    local existingSessionKey = 'diag:v1:session:' .. existingSessionId
    local existingSessionRaw = redis.call('GET', existingSessionKey)
    if existingSessionRaw then
        local existingSession = cjson.decode(existingSessionRaw)
        local inActive = redis.call('ZSCORE', activeKey, existingSessionId)
        if existingSession.created_control_epoch == control.epoch
            and existingSession.enabled == true
            and tonumber(existingSession.expires_at_ms) > nowMs
            and inActive
        then
            redis.call('SET', idempKey, cjson.encode({
                fingerprint = idempFingerprint,
                session_id = existingSessionId,
                created_at_ms = nowMs
            }), 'EX', retentionTtlSec)
            redis.call('SET', controlKey, cjson.encode(control))
            return cjson.encode({
                ok = true,
                reused = true,
                idempotent = false,
                control_epoch = control.epoch,
                control_version = tostring(control.control_version),
                now_ms = nowMs,
                session = existingSession
            })
        end
    end
end

-- 3. Enforce max concurrent active sessions
local activeCount = tonumber(redis.call('ZCARD', activeKey) or 0)
if activeCount >= maxSessions then
    return cjson.encode({ err = 'max_active_sessions_exceeded', active_count = activeCount })
end

-- 4. Create new session and advance control_version
local nextVersion = tostring(tonumber(control.control_version) + 1)
control.control_version = nextVersion
redis.call('SET', controlKey, cjson.encode(control))

local expiresAtMs = nowMs + leaseDurationMs
local sessionRecord = {
    session_id = newSessionId,
    diagnostic_id = diagnosticId,
    subscriber_user_id = subscriberUserId,
    order_id = orderId,
    operator_id = adminId,
    created_at_ms = nowMs,
    expires_at_ms = expiresAtMs,
    max_duration_seconds = math.floor(leaseDurationMs / 1000),
    revision = 1,
    enabled = true,
    stopped_at_ms = nil,
    created_control_epoch = control.epoch,
    created_control_version = nextVersion,
    updated_control_version = nextVersion,
    eligible_node_ids = cjson.decode(eligibleNodesJson)
}

local sessionTtlSec = math.ceil(leaseDurationMs / 1000) + retentionTtlSec
local sessionKey = 'diag:v1:session:' .. newSessionId
redis.call('SET', sessionKey, cjson.encode(sessionRecord), 'EX', sessionTtlSec)
redis.call('SET', subjectKey, newSessionId, 'EX', sessionTtlSec)
redis.call('ZADD', activeKey, expiresAtMs, newSessionId)
redis.call('SET', idempKey, cjson.encode({
    fingerprint = idempFingerprint,
    session_id = newSessionId,
    created_at_ms = nowMs
}), 'EX', retentionTtlSec)

return cjson.encode({
    ok = true,
    reused = false,
    idempotent = false,
    control_epoch = control.epoch,
    control_version = nextVersion,
    now_ms = nowMs,
    session = sessionRecord
})
LUA;

    private const STOP_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local activeKey = KEYS[2]
local subjectKey = KEYS[3]
local sessionKey = KEYS[4]
local idempKey = KEYS[5]

local newEpochCandidate = ARGV[1]
local sessionId = ARGV[2]
local expectedDiagnosticId = ARGV[3]
local idempFingerprint = ARGV[4]
local retentionTtlSec = tonumber(ARGV[5])
local forcedNowMs = tonumber(ARGV[6])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

local controlRaw = redis.call('GET', controlKey)
local control = nil
if controlRaw then
    control = cjson.decode(controlRaw)
end
if not control or type(control) ~= 'table' or not control.epoch or not control.control_version then
    redis.call('DEL', activeKey)
    control = {
        epoch = newEpochCandidate,
        control_version = '0',
        last_server_time_ms = nowMs
    }
elseif nowMs > tonumber(control.last_server_time_ms or 0) then
    control.last_server_time_ms = nowMs
end

redis.call('ZREMRANGEBYSCORE', activeKey, '-inf', nowMs)

local existingIdempRaw = redis.call('GET', idempKey)
if existingIdempRaw then
    local existingIdemp = cjson.decode(existingIdempRaw)
    if existingIdemp.fingerprint ~= idempFingerprint then
        return cjson.encode({ err = 'idempotency_conflict' })
    end
end

local sessionRaw = redis.call('GET', sessionKey)
if not sessionRaw then
    return cjson.encode({ err = 'session_not_found' })
end

local session = cjson.decode(sessionRaw)
if session.diagnostic_id ~= expectedDiagnosticId then
    return cjson.encode({ err = 'session_subject_mismatch' })
end

local stateChanged = false
if session.enabled == true then
    local nextVersion = tostring(tonumber(control.control_version) + 1)
    control.control_version = nextVersion
    session.enabled = false
    session.stopped_at_ms = nowMs
    session.revision = tonumber(session.revision or 1) + 1
    session.updated_control_version = nextVersion
    session.stopped_control_version = nextVersion
    stateChanged = true
end

redis.call('SET', controlKey, cjson.encode(control))
redis.call('ZREM', activeKey, sessionId)
redis.call('SET', sessionKey, cjson.encode(session), 'EX', retentionTtlSec)

-- Only update subject tombstone TTL if subject still points to this sessionId
local currentSubjectSessionId = redis.call('GET', subjectKey)
if currentSubjectSessionId == sessionId then
    redis.call('EXPIRE', subjectKey, retentionTtlSec)
end

redis.call('SET', idempKey, cjson.encode({
    fingerprint = idempFingerprint,
    session_id = sessionId,
    created_at_ms = nowMs
}), 'EX', retentionTtlSec)

return cjson.encode({
    ok = true,
    state_changed = stateChanged,
    control_epoch = control.epoch,
    control_version = tostring(control.control_version),
    now_ms = nowMs,
    session = session
})
LUA;

    private const RECONCILE_ELIGIBLE_NODES_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local activeKey = KEYS[2]
local sessionKey = KEYS[3]

local sessionId = ARGV[1]
local newEligibleNodesJson = ARGV[2]
local retentionTtlSec = tonumber(ARGV[3])
local forcedNowMs = tonumber(ARGV[4])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

local controlRaw = redis.call('GET', controlKey)
if not controlRaw then
    return cjson.encode({ changed = false })
end
local control = cjson.decode(controlRaw)

local sessionRaw = redis.call('GET', sessionKey)
if not sessionRaw then
    return cjson.encode({ changed = false })
end
local session = cjson.decode(sessionRaw)

local inActive = redis.call('ZSCORE', activeKey, sessionId)
if session.created_control_epoch ~= control.epoch or session.enabled ~= true or tonumber(session.expires_at_ms) <= nowMs or not inActive then
    return cjson.encode({ changed = false, session = session })
end

local nextVersion = tostring(tonumber(control.control_version) + 1)
control.control_version = nextVersion
if nowMs > tonumber(control.last_server_time_ms or 0) then
    control.last_server_time_ms = nowMs
end
redis.call('SET', controlKey, cjson.encode(control))

session.eligible_node_ids = cjson.decode(newEligibleNodesJson)
session.revision = tonumber(session.revision or 1) + 1
session.updated_control_version = nextVersion

local remainingSec = math.max(1, math.ceil((tonumber(session.expires_at_ms) - nowMs) / 1000))
redis.call('SET', sessionKey, cjson.encode(session), 'EX', remainingSec + retentionTtlSec)

return cjson.encode({
    changed = true,
    control_epoch = control.epoch,
    control_version = nextVersion,
    session = session
})
LUA;

    private const SNAPSHOT_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local activeKey = KEYS[2]
local ackBindKey = KEYS[3]
local nodeRuntimeKey = KEYS[4]

local newEpochCandidate = ARGV[1]
local nodeId = tonumber(ARGV[2])
local requestId = ARGV[3]
local runtimeId = ARGV[4]
local transport = ARGV[5]
local authPrincipal = ARGV[6]
local wsConnectionId = ARGV[7]
local ackBindTtlSec = tonumber(ARGV[8])
local nodeStateTtlSec = tonumber(ARGV[9])
local forcedNowMs = tonumber(ARGV[10])
local clockToleranceMs = tonumber(ARGV[11])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

local controlRaw = redis.call('GET', controlKey)
local control = nil
if controlRaw then
    control = cjson.decode(controlRaw)
end

if not control or type(control) ~= 'table' or not control.epoch or not control.control_version then
    redis.call('DEL', activeKey)
    control = {
        epoch = newEpochCandidate,
        control_version = '0',
        last_server_time_ms = nowMs
    }
    redis.call('SET', controlKey, cjson.encode(control))
else
    local lastMs = tonumber(control.last_server_time_ms or 0)
    if lastMs > 0 and (nowMs + clockToleranceMs) < lastMs then
        return cjson.encode({ err = 'clock_rollback', last_server_time_ms = lastMs, now_ms = nowMs })
    end
    if nowMs > lastMs then
        control.last_server_time_ms = nowMs
        redis.call('SET', controlKey, cjson.encode(control))
    end
end

redis.call('ZREMRANGEBYSCORE', activeKey, '-inf', nowMs)

local activeSessionIds = redis.call('ZRANGE', activeKey, 0, -1)
local sessions = {}
local sessionIdList = {}

for _, sessionId in ipairs(activeSessionIds) do
    local sRaw = redis.call('GET', 'diag:v1:session:' .. sessionId)
    if sRaw then
        local s = cjson.decode(sRaw)
        if s.created_control_epoch == control.epoch and s.enabled == true and tonumber(s.expires_at_ms) > nowMs then
            local eligible = false
            if type(s.eligible_node_ids) == 'table' then
                for _, nid in ipairs(s.eligible_node_ids) do
                    if tonumber(nid) == nodeId then
                        eligible = true
                        break
                    end
                end
            end
            if eligible then
                table.insert(sessions, {
                    session_id = s.session_id,
                    diagnostic_id = s.diagnostic_id,
                    revision = tonumber(s.revision or 1),
                    issued_at_ms = tonumber(s.created_at_ms),
                    expires_at_ms = tonumber(s.expires_at_ms),
                    max_duration_seconds = tonumber(s.max_duration_seconds or 900)
                })
                table.insert(sessionIdList, s.session_id)
            end
        elseif s.created_control_epoch ~= control.epoch then
            redis.call('ZREM', activeKey, sessionId)
        end
    else
        redis.call('ZREM', activeKey, sessionId)
    end
end

local bindRecord = {
    node_id = nodeId,
    request_id = requestId,
    runtime_id = runtimeId,
    transport = transport,
    auth_principal = authPrincipal,
    ws_connection_id = wsConnectionId,
    control_epoch = control.epoch,
    control_version = tostring(control.control_version),
    session_ids = sessionIdList,
    issued_at_ms = nowMs
}
redis.call('SET', ackBindKey, cjson.encode(bindRecord), 'EX', ackBindTtlSec)

local existingRuntimeRaw = redis.call('GET', nodeRuntimeKey)
local existingRuntime = existingRuntimeRaw and cjson.decode(existingRuntimeRaw) or {}
local runtimeRecord = {
    node_id = nodeId,
    runtime_id = runtimeId,
    transport = transport,
    auth_principal = authPrincipal,
    ws_connection_id = (transport == 'ws' and wsConnectionId ~= '') and wsConnectionId or (existingRuntime.ws_connection_id or ''),
    updated_at_ms = nowMs
}
redis.call('SET', nodeRuntimeKey, cjson.encode(runtimeRecord), 'EX', nodeStateTtlSec)

return cjson.encode({
    ok = true,
    control_epoch = control.epoch,
    control_version = tostring(control.control_version),
    server_time_ms = nowMs,
    sessions = sessions
})
LUA;

    private const ACK_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local ackBindKey = KEYS[2]
local nodeAcksKey = KEYS[3]

local nodeId = tonumber(ARGV[1])
local requestId = ARGV[2]
local runtimeId = ARGV[3]
local controlEpoch = ARGV[4]
local controlVersion = ARGV[5]
local transport = ARGV[6]
local authPrincipal = ARGV[7]
local ackSessionsJson = ARGV[8]
local retentionTtlSec = tonumber(ARGV[9])
local forcedNowMs = tonumber(ARGV[10])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

local bindRaw = redis.call('GET', ackBindKey)
if not bindRaw then
    return cjson.encode({ err = 'ack_binding_expired' })
end

local bind = cjson.decode(bindRaw)
if bind.request_id ~= requestId
    or bind.runtime_id ~= runtimeId
    or bind.control_epoch ~= controlEpoch
    or tostring(bind.control_version) ~= tostring(controlVersion)
    or bind.transport ~= transport
    or bind.auth_principal ~= authPrincipal
then
    return cjson.encode({ err = 'ack_binding_mismatch' })
end

local controlRaw = redis.call('GET', controlKey)
if not controlRaw then
    return cjson.encode({ err = 'control_epoch_missing' })
end
local control = cjson.decode(controlRaw)
if control.epoch ~= controlEpoch then
    return cjson.encode({ err = 'stale_control_epoch' })
end

local allowedSet = {}
if type(bind.session_ids) == 'table' then
    for _, sid in ipairs(bind.session_ids) do
        allowedSet[sid] = true
    end
end

local ackSessions = cjson.decode(ackSessionsJson)
local ackedInPayload = {}
local updatedCount = 0

for _, item in ipairs(ackSessions) do
    local sid = item.session_id
    if sid and allowedSet[sid] then
        ackedInPayload[sid] = true
        local ackKey = 'diag:v1:ack:' .. sid .. ':' .. tostring(nodeId)
        local prevRaw = redis.call('GET', ackKey)
        local shouldWrite = true
        if prevRaw then
            local prev = cjson.decode(prevRaw)
            if prev.control_epoch == controlEpoch and tonumber(prev.control_version or 0) > tonumber(controlVersion) then
                shouldWrite = false
            end
        end
        if shouldWrite then
            local record = {
                session_id = sid,
                node_id = nodeId,
                request_id = requestId,
                runtime_id = runtimeId,
                control_epoch = controlEpoch,
                control_version = tostring(controlVersion),
                transport = transport,
                owner = authPrincipal,
                revision = tonumber(item.revision or 1),
                effective = item.effective == true,
                expires_at = item.expires_at,
                reason = item.reason,
                acked_at_ms = nowMs
            }
            redis.call('SET', ackKey, cjson.encode(record), 'EX', retentionTtlSec)
            redis.call('SADD', nodeAcksKey, sid)
            redis.call('EXPIRE', nodeAcksKey, retentionTtlSec)
            updatedCount = updatedCount + 1
        end
    end
end

-- Mark any previously ACKed session on this node that is no longer in snapshot as revoked
local prevTracked = redis.call('SMEMBERS', nodeAcksKey)
for _, sid in ipairs(prevTracked) do
    if not allowedSet[sid] then
        local ackKey = 'diag:v1:ack:' .. sid .. ':' .. tostring(nodeId)
        local prevRaw = redis.call('GET', ackKey)
        if prevRaw then
            local prev = cjson.decode(prevRaw)
            local canRevoke = (prev.control_epoch ~= controlEpoch)
                or (tonumber(controlVersion) >= tonumber(prev.control_version or 0))
            if canRevoke then
                local revokedRecord = {
                    session_id = sid,
                    node_id = nodeId,
                    request_id = requestId,
                    runtime_id = runtimeId,
                    control_epoch = controlEpoch,
                    control_version = tostring(controlVersion),
                    transport = transport,
                    owner = authPrincipal,
                    revision = tonumber(prev.revision or 1),
                    effective = false,
                    expires_at = prev.expires_at,
                    reason = 'revoked_by_snapshot',
                    acked_at_ms = nowMs
                }
                redis.call('SET', ackKey, cjson.encode(revokedRecord), 'EX', retentionTtlSec)
                updatedCount = updatedCount + 1
            end
        end
    end
end

return cjson.encode({
    ok = true,
    updated_count = updatedCount,
    control_epoch = controlEpoch,
    control_version = tostring(controlVersion),
    now_ms = nowMs
})
LUA;

    private const RESET_EPOCH_SCRIPT = <<<'LUA'
local controlKey = KEYS[1]
local activeKey = KEYS[2]
local newEpoch = ARGV[1]
local forcedNowMs = tonumber(ARGV[2])

local nowMs = forcedNowMs
if not nowMs or nowMs <= 0 then
    local t = redis.call('TIME')
    nowMs = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
end

redis.call('DEL', activeKey)
local control = {
    epoch = newEpoch,
    control_version = '0',
    last_server_time_ms = nowMs
}
redis.call('SET', controlKey, cjson.encode(control))

return cjson.encode(control)
LUA;

    private ?int $forcedNowMs = null;

    public function __construct(
        private readonly OrderDiagnosticIdentityService $identityService,
        private readonly DiagnosticStorageHealthClient $healthClient,
        private readonly NodeConnectionOwnership $ownership
    ) {
    }

    /**
     * Test helper to deterministically advance or skew server clock in unit/feature tests.
     */
    public function setForcedNowMs(?int $nowMs): void
    {
        $this->forcedNowMs = $nowMs;
    }

    /**
     * Start or idempotently return a 15-minute detail diagnostic session for an order's subscriber.
     *
     * @return array<string, mixed>
     */
    public function startLease(int $adminId, int $orderId, string $idempotencyKey): array
    {
        $normalizedKey = $this->validateIdempotencyKey($idempotencyKey);
        $resolved = $this->identityService->resolveOrderFromInput(['order_id' => $orderId]);
        $panelScope = $resolved['panel_scope'];
        $diagnosticId = $resolved['diagnostic_id'];
        $subscriber = $resolved['subscriber'];
        $subscriberUserId = $resolved['subscriber_user_id'];

        $eligibleNodes = $this->identityService->resolveEligibleNodesForSubscriber($subscriber);
        $eligibleNodeIds = $eligibleNodes->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        $fingerprint = $this->buildIdempotencyFingerprint('start', $orderId, $diagnosticId, null);
        $idempRedisKey = self::PREFIX_IDEMPOTENCY . $adminId . ':' . hash('sha256', $normalizedKey);
        $subjectRedisKey = self::PREFIX_SUBJECT . $diagnosticId;

        // Pre-check whether an idempotent or already-active session exists before calling storage health guard.
        // Existing sessions return without extending expiration and do not require creating a new lease.
        $needsNewLease = $this->checkNeedsNewLease($idempRedisKey, $subjectRedisKey, $fingerprint);
        if ($needsNewLease) {
            $this->healthClient->assertReadyForNewLease($panelScope);
        }

        $newEpochCandidate = strtolower((string) Str::uuid());
        $newSessionId = bin2hex(random_bytes(16));

        try {
            $rawResult = Redis::command('eval', [
                self::START_SCRIPT,
                [
                    self::KEY_CONTROL,
                    self::KEY_ACTIVE,
                    $subjectRedisKey,
                    $idempRedisKey,
                    $newEpochCandidate,
                    $newSessionId,
                    $diagnosticId,
                    (string) $subscriberUserId,
                    (string) $orderId,
                    (string) $adminId,
                    $fingerprint,
                    json_encode($eligibleNodeIds, JSON_THROW_ON_ERROR),
                    (string) self::MAX_CONCURRENT_SESSIONS,
                    (string) self::LEASE_DURATION_MS,
                    (string) self::RETENTION_TTL_SECONDS,
                    (string) ($this->forcedNowMs ?? 0),
                    (string) self::CLOCK_ROLLBACK_TOLERANCE_MS,
                ],
                4,
            ]);
        } catch (\Throwable $e) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断租约控制存储暂时不可用，请稍后重试',
                503
            );
        }

        $decoded = is_string($rawResult) ? json_decode($rawResult, true) : null;
        if (!is_array($decoded)) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断租约控制响应异常',
                503
            );
        }

        if (isset($decoded['err'])) {
            $this->throwForLuaError((string) $decoded['err'], $decoded);
        }

        /** @var array<string, mixed> $session */
        $session = $decoded['session'];
        $controlEpoch = (string) $decoded['control_epoch'];
        $controlVersion = (string) $decoded['control_version'];
        $nowMs = (int) $decoded['now_ms'];

        if (!($decoded['reused'] ?? false)) {
            $this->notifyNodesDiagnosticsChanged($eligibleNodeIds, $controlEpoch, $controlVersion);
        }

        return $this->formatSessionStatusResponse(
            $session,
            $eligibleNodes,
            $panelScope,
            $controlEpoch,
            $controlVersion,
            $nowMs,
            (bool) ($decoded['idempotent'] ?? false),
            (bool) ($decoded['reused'] ?? false)
        );
    }

    /**
     * Stop an active or pending 15-minute diagnostic session.
     * Does not depend on storage health endpoint availability.
     *
     * @return array<string, mixed>
     */
    public function stopLease(int $adminId, int $orderId, string $sessionId, string $idempotencyKey): array
    {
        $normalizedKey = $this->validateIdempotencyKey($idempotencyKey);
        $normalizedSessionId = strtolower(trim($sessionId));
        if (!DiagnosticContract::isValidHexId($normalizedSessionId, 32)) {
            throw new DiagnosticDomainException(
                'invalid_session_id',
                'session_id 必须为 32 位小写十六进制字符串',
                422
            );
        }

        $resolved = $this->identityService->resolveOrderFromInput(['order_id' => $orderId]);
        $panelScope = $resolved['panel_scope'];
        $diagnosticId = $resolved['diagnostic_id'];
        $subscriber = $resolved['subscriber'];
        $eligibleNodes = $this->identityService->resolveEligibleNodesForSubscriber($subscriber);

        $fingerprint = $this->buildIdempotencyFingerprint('stop', $orderId, $diagnosticId, $normalizedSessionId);
        $idempRedisKey = self::PREFIX_IDEMPOTENCY . $adminId . ':' . hash('sha256', $normalizedKey);
        $subjectRedisKey = self::PREFIX_SUBJECT . $diagnosticId;
        $sessionRedisKey = self::PREFIX_SESSION . $normalizedSessionId;
        $newEpochCandidate = strtolower((string) Str::uuid());

        try {
            $rawResult = Redis::command('eval', [
                self::STOP_SCRIPT,
                [
                    self::KEY_CONTROL,
                    self::KEY_ACTIVE,
                    $subjectRedisKey,
                    $sessionRedisKey,
                    $idempRedisKey,
                    $newEpochCandidate,
                    $normalizedSessionId,
                    $diagnosticId,
                    $fingerprint,
                    (string) self::RETENTION_TTL_SECONDS,
                    (string) ($this->forcedNowMs ?? 0),
                ],
                5,
            ]);
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断租约控制存储暂时不可用，请稍后重试',
                503
            );
        }

        $decoded = is_string($rawResult) ? json_decode($rawResult, true) : null;
        if (!is_array($decoded)) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断租约控制响应异常',
                503
            );
        }

        if (isset($decoded['err'])) {
            $this->throwForLuaError((string) $decoded['err'], $decoded);
        }

        /** @var array<string, mixed> $session */
        $session = $decoded['session'];
        $controlEpoch = (string) $decoded['control_epoch'];
        $controlVersion = (string) $decoded['control_version'];
        $nowMs = (int) $decoded['now_ms'];

        $notifyNodeIds = array_values(array_unique(array_merge(
            array_map('intval', $session['eligible_node_ids'] ?? []),
            $eligibleNodes->pluck('id')->map(static fn ($id): int => (int) $id)->all()
        )));

        if ($decoded['state_changed'] ?? false) {
            $this->notifyNodesDiagnosticsChanged($notifyNodeIds, $controlEpoch, $controlVersion);
        }

        return $this->formatSessionStatusResponse(
            $session,
            $eligibleNodes,
            $panelScope,
            $controlEpoch,
            $controlVersion,
            $nowMs,
            !($decoded['state_changed'] ?? false),
            false
        );
    }

    /**
     * Get diagnostic lease status, node capabilities, ACKs, and storage health for an order.
     * Also reconciles eligible node set if user group or node group membership changed.
     *
     * @return array<string, mixed>
     */
    public function getOrderStatus(int $orderId): array
    {
        $resolved = $this->identityService->resolveOrderFromInput(['order_id' => $orderId]);
        $panelScope = $resolved['panel_scope'];
        $diagnosticId = $resolved['diagnostic_id'];
        $subscriber = $resolved['subscriber'];
        $eligibleNodes = $this->identityService->resolveEligibleNodesForSubscriber($subscriber);
        $currentEligibleIds = $eligibleNodes->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        $nowMs = $this->readRedisTimeMs();
        $control = $this->readControlState($nowMs);
        $controlEpoch = $control['epoch'];
        $controlVersion = $control['control_version'];

        $subjectKey = self::PREFIX_SUBJECT . $diagnosticId;
        $session = null;

        try {
            $sessionId = Redis::get($subjectKey);
            if (is_string($sessionId) && $sessionId !== '') {
                $sessionRaw = Redis::get(self::PREFIX_SESSION . $sessionId);
                if (is_string($sessionRaw) && $sessionRaw !== '') {
                    $decoded = json_decode($sessionRaw, true);
                    if (is_array($decoded) && ($decoded['created_control_epoch'] ?? '') === $controlEpoch) {
                        if (($decoded['enabled'] ?? false) === true && (int) ($decoded['expires_at_ms'] ?? 0) > $nowMs) {
                            $activeMembers = Redis::zrange(self::KEY_ACTIVE, 0, -1);
                            if (is_array($activeMembers) && in_array($sessionId, $activeMembers, true)) {
                                $session = $decoded;
                            }
                        } else {
                            $session = $decoded;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断租约控制存储暂时不可用',
                503
            );
        }

        if ($session !== null && ($session['enabled'] ?? false) === true && (int) ($session['expires_at_ms'] ?? 0) > $nowMs) {
            $previousIds = array_values(array_map('intval', $session['eligible_node_ids'] ?? []));
            sort($previousIds);
            $sortedCurrent = $currentEligibleIds;
            sort($sortedCurrent);

            if ($previousIds !== $sortedCurrent) {
                $reconciled = $this->reconcileSessionEligibleNodes(
                    (string) $session['session_id'],
                    $currentEligibleIds,
                    $previousIds,
                    $nowMs
                );
                if ($reconciled !== null) {
                    $session = $reconciled['session'];
                    $controlEpoch = $reconciled['control_epoch'];
                    $controlVersion = $reconciled['control_version'];
                }
            }
        }

        $statusPayload = $this->formatSessionStatusResponse(
            $session,
            $eligibleNodes,
            $panelScope,
            $controlEpoch,
            $controlVersion,
            $nowMs,
            false,
            false
        );

        $statusPayload['storage_health'] = $this->healthClient->fetchHealth($panelScope);
        return $statusPayload;
    }

    /**
     * Build a complete, authoritative per-node lease snapshot for WS (`request.diagnostics`) or REST (`/snapshot`).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function buildNodeSnapshot(
        Server $node,
        array $input,
        string $transport,
        string $authPrincipal,
        ?string $wsConnectionId = null
    ): array {
        $nodeId = (int) $node->id;
        $requestId = isset($input['request_id']) && is_string($input['request_id'])
            ? strtolower(trim($input['request_id']))
            : '';
        $runtimeId = isset($input['runtime_id']) && is_string($input['runtime_id'])
            ? strtolower(trim($input['runtime_id']))
            : '';

        if (!DiagnosticContract::isValidHexId($requestId, 32)) {
            throw new DiagnosticDomainException(
                'invalid_request_id',
                'request_id 必须为 32 位小写十六进制字符串',
                422
            );
        }

        if (!DiagnosticContract::isValidHexId($runtimeId, 32)) {
            throw new DiagnosticDomainException(
                'invalid_runtime_id',
                'runtime_id 必须为 32 位小写十六进制字符串',
                422
            );
        }

        $this->verifyTransportAndRuntimeOwnership($nodeId, $transport, $runtimeId, $wsConnectionId, true);

        if (isset($input['capabilities']) && is_array($input['capabilities'])) {
            $this->recordNodeCapabilities($nodeId, $runtimeId, $input['capabilities'], $wsConnectionId, false);
        }

        $this->reconcileActiveSessionsEligibility();

        $panelScope = $this->identityService->getPanelScope() ?? '';
        $newEpochCandidate = strtolower((string) Str::uuid());

        try {
            $rawResult = Redis::command('eval', [
                self::SNAPSHOT_SCRIPT,
                [
                    self::KEY_CONTROL,
                    self::KEY_ACTIVE,
                    self::PREFIX_ACK_BIND . $nodeId,
                    self::PREFIX_NODE_RUNTIME . $nodeId,
                    $newEpochCandidate,
                    (string) $nodeId,
                    $requestId,
                    $runtimeId,
                    $transport,
                    $authPrincipal,
                    (string) ($wsConnectionId ?? ''),
                    (string) self::ACK_BIND_TTL_SECONDS,
                    (string) self::NODE_STATE_TTL_SECONDS,
                    (string) ($this->forcedNowMs ?? 0),
                    (string) self::CLOCK_ROLLBACK_TOLERANCE_MS,
                ],
                4,
            ]);
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '无法读取节点诊断租约快照',
                503
            );
        }

        $decoded = is_string($rawResult) ? json_decode($rawResult, true) : null;
        if (!is_array($decoded)) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断快照读取响应异常',
                503
            );
        }

        if (isset($decoded['err'])) {
            $this->throwForLuaError((string) $decoded['err'], $decoded);
        }

        $serverTimeMs = (int) $decoded['server_time_ms'];
        $sessions = [];
        foreach (($decoded['sessions'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sessions[] = [
                'session_id' => (string) $s['session_id'],
                'diagnostic_id' => (string) $s['diagnostic_id'],
                'node_id' => $nodeId,
                'revision' => (int) ($s['revision'] ?? 1),
                'enabled' => true,
                'created_at' => self::formatUtcIso8601((int) $s['issued_at_ms']),
                'issued_at' => self::formatUtcIso8601((int) $s['issued_at_ms']),
                'expires_at' => self::formatUtcIso8601((int) $s['expires_at_ms']),
                'max_duration_seconds' => (int) ($s['max_duration_seconds'] ?? self::LEASE_DURATION_SECONDS),
            ];
        }

        return [
            'schema_version' => DiagnosticContract::SCHEMA_VERSION,
            'node_id' => $nodeId,
            'request_id' => $requestId,
            'runtime_id' => $runtimeId,
            'panel_scope' => $panelScope,
            'control_epoch' => (string) $decoded['control_epoch'],
            'control_version' => (string) $decoded['control_version'],
            'complete' => true,
            'server_time' => self::formatUtcIso8601($serverTimeMs),
            'sessions' => $sessions,
        ];
    }

    /**
     * Handle node `diagnostics.ack` from WS or REST (`/ack`).
     * Validates the 60s ACK binding (`auth_principal`, `transport`, `request_id`, `runtime_id`, `control_epoch`, `control_version`).
     * Never registers a new runtime from an ACK.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function handleNodeAck(
        Server $node,
        array $input,
        string $transport,
        string $authPrincipal,
        ?string $wsConnectionId = null
    ): array {
        $nodeId = (int) $node->id;
        $requestId = isset($input['request_id']) && is_string($input['request_id'])
            ? strtolower(trim($input['request_id']))
            : '';
        $runtimeId = isset($input['runtime_id']) && is_string($input['runtime_id'])
            ? strtolower(trim($input['runtime_id']))
            : '';
        $controlEpoch = isset($input['control_epoch']) && is_string($input['control_epoch'])
            ? strtolower(trim($input['control_epoch']))
            : '';
        $controlVersion = isset($input['control_version']) && (is_string($input['control_version']) || is_int($input['control_version']))
            ? trim((string) $input['control_version'])
            : '';

        if (!DiagnosticContract::isValidHexId($requestId, 32)) {
            throw new DiagnosticDomainException('invalid_request_id', 'request_id 格式无效', 422);
        }
        if (!DiagnosticContract::isValidHexId($runtimeId, 32)) {
            throw new DiagnosticDomainException('invalid_runtime_id', 'runtime_id 格式无效', 422);
        }
        if (!DiagnosticContract::isValidPanelScope($controlEpoch)) {
            throw new DiagnosticDomainException('invalid_control_epoch', 'control_epoch 格式无效', 422);
        }
        if (preg_match('/\A(?:0|[1-9]\d*)\z/', $controlVersion) !== 1) {
            throw new DiagnosticDomainException('invalid_control_version', 'control_version 格式无效', 422);
        }
        if (!isset($input['sessions']) || !is_array($input['sessions'])) {
            throw new DiagnosticDomainException('invalid_ack_sessions', 'sessions 必须为数组', 422);
        }

        // Verify ownership without allowing ACK to register a new runtime
        $this->verifyTransportAndRuntimeOwnership($nodeId, $transport, $runtimeId, $wsConnectionId, false);

        $normalizedAckSessions = [];
        foreach ($input['sessions'] as $item) {
            if (!is_array($item)) {
                throw new DiagnosticDomainException('invalid_ack_sessions', 'sessions 项必须为对象', 422);
            }
            $sid = isset($item['session_id']) && is_string($item['session_id'])
                ? strtolower(trim($item['session_id']))
                : '';
            if (!DiagnosticContract::isValidHexId($sid, 32)) {
                throw new DiagnosticDomainException('invalid_ack_sessions', 'ACK session_id 格式无效', 422);
            }
            $normalizedAckSessions[] = [
                'session_id' => $sid,
                'revision' => isset($item['revision']) && is_numeric($item['revision']) ? (int) $item['revision'] : 1,
                'effective' => (bool) ($item['effective'] ?? false),
                'expires_at' => isset($item['expires_at']) && is_string($item['expires_at']) ? trim($item['expires_at']) : null,
                'reason' => isset($item['reason']) && is_string($item['reason']) ? trim($item['reason']) : null,
            ];
        }

        try {
            $rawResult = Redis::command('eval', [
                self::ACK_SCRIPT,
                [
                    self::KEY_CONTROL,
                    self::PREFIX_ACK_BIND . $nodeId,
                    self::PREFIX_NODE_ACKS . $nodeId,
                    (string) $nodeId,
                    $requestId,
                    $runtimeId,
                    $controlEpoch,
                    $controlVersion,
                    $transport,
                    $authPrincipal,
                    json_encode($normalizedAckSessions, JSON_THROW_ON_ERROR),
                    (string) self::RETENTION_TTL_SECONDS,
                    (string) ($this->forcedNowMs ?? 0),
                ],
                3,
            ]);
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '无法写入节点诊断 ACK',
                503
            );
        }

        $decoded = is_string($rawResult) ? json_decode($rawResult, true) : null;
        if (!is_array($decoded)) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '诊断 ACK 处理响应异常',
                503
            );
        }

        if (isset($decoded['err'])) {
            $this->throwForLuaError((string) $decoded['err'], $decoded);
        }

        return [
            'node_id' => $nodeId,
            'request_id' => $requestId,
            'runtime_id' => $runtimeId,
            'control_epoch' => (string) $decoded['control_epoch'],
            'control_version' => (string) $decoded['control_version'],
            'updated_count' => (int) ($decoded['updated_count'] ?? 0),
        ];
    }

    /**
     * Record node diagnostic capabilities & health metrics (from `diagnostics.capabilities` or `node.status`).
     * Capability advertisement alone does NOT overwrite an active runtime binding unless `$registerWsRuntime` is true on WS owner.
     *
     * @param array<string, mixed> $data
     */
    public function recordNodeCapabilities(
        int $nodeId,
        string $runtimeId,
        array $data,
        ?string $wsConnectionId = null,
        bool $registerWsRuntime = false
    ): void {
        if ($nodeId <= 0 || !DiagnosticContract::isValidHexId($runtimeId, 32)) {
            return;
        }

        $nowMs = $this->readRedisTimeMs();
        $capRecord = [
            'node_id' => $nodeId,
            'runtime_id' => $runtimeId,
            'supported' => (bool) ($data['supported'] ?? true),
            'schema_version' => (int) ($data['schema_version'] ?? DiagnosticContract::SCHEMA_VERSION),
            'observer_state' => is_string($data['observer_state'] ?? null) ? $data['observer_state'] : 'healthy',
            'queue_depth' => isset($data['queue_depth']) && is_numeric($data['queue_depth']) ? (int) $data['queue_depth'] : 0,
            'queue_bytes' => isset($data['queue_bytes']) && is_numeric($data['queue_bytes']) ? (int) $data['queue_bytes'] : 0,
            'dropped_total' => isset($data['dropped_total']) && is_numeric($data['dropped_total']) ? (int) $data['dropped_total'] : 0,
            'dropped_detail' => isset($data['dropped_detail']) && is_numeric($data['dropped_detail']) ? (int) $data['dropped_detail'] : 0,
            'dropped_baseline' => isset($data['dropped_baseline']) && is_numeric($data['dropped_baseline']) ? (int) $data['dropped_baseline'] : 0,
            'updated_at_ms' => $nowMs,
        ];

        try {
            Redis::setex(
                self::PREFIX_NODE_CAP . $nodeId,
                self::NODE_STATE_TTL_SECONDS,
                json_encode($capRecord, JSON_THROW_ON_ERROR)
            );

            if ($registerWsRuntime && $wsConnectionId !== null && $wsConnectionId !== '') {
                $runtimeRecord = [
                    'node_id' => $nodeId,
                    'runtime_id' => $runtimeId,
                    'transport' => 'ws',
                    'auth_principal' => 'ws:' . $wsConnectionId,
                    'ws_connection_id' => $wsConnectionId,
                    'updated_at_ms' => $nowMs,
                ];
                Redis::setex(
                    self::PREFIX_NODE_RUNTIME . $nodeId,
                    self::NODE_STATE_TTL_SECONDS,
                    json_encode($runtimeRecord, JSON_THROW_ON_ERROR)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[Diagnostics] Failed to record node capabilities.', [
                'node_id' => $nodeId,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Read recorded capability and health metrics for a list of nodes.
     *
     * @param list<int> $nodeIds
     * @return array<int, array<string, mixed>>
     */
    public function getNodesCapabilityMap(array $nodeIds): array
    {
        $result = [];
        foreach ($nodeIds as $nodeId) {
            $nodeId = (int) $nodeId;
            if ($nodeId <= 0) {
                continue;
            }
            try {
                $raw = Redis::get(self::PREFIX_NODE_CAP . $nodeId);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $result[$nodeId] = $decoded;
                        continue;
                    }
                }
            } catch (\Throwable) {
                // Fall through to unsupported/unknown
            }
            $result[$nodeId] = [
                'node_id' => $nodeId,
                'runtime_id' => null,
                'supported' => false,
                'observer_state' => 'unknown',
                'queue_depth' => null,
                'queue_bytes' => null,
                'dropped_total' => null,
                'updated_at_ms' => null,
            ];
        }

        return $result;
    }

    /**
     * Explicitly reset control epoch and clear active control references (for Redis rollback / recovery).
     *
     * @return array{epoch: string, control_version: string, last_server_time_ms: int}
     */
    public function resetControlEpoch(): array
    {
        $newEpoch = strtolower((string) Str::uuid());
        $raw = Redis::command('eval', [
            self::RESET_EPOCH_SCRIPT,
            [
                self::KEY_CONTROL,
                self::KEY_ACTIVE,
                $newEpoch,
                (string) ($this->forcedNowMs ?? 0),
            ],
            2,
        ]);

        $decoded = is_string($raw) ? json_decode($raw, true) : [];
        return [
            'epoch' => (string) ($decoded['epoch'] ?? $newEpoch),
            'control_version' => (string) ($decoded['control_version'] ?? '0'),
            'last_server_time_ms' => (int) ($decoded['last_server_time_ms'] ?? 0),
        ];
    }

    /**
     * Verify WS ownership or REST fallback runtime rules without claiming WS ownership from REST.
     */
    private function verifyTransportAndRuntimeOwnership(
        int $nodeId,
        string $transport,
        string $runtimeId,
        ?string $wsConnectionId,
        bool $isSnapshotRequest
    ): void {
        if ($transport === 'ws') {
            if ($wsConnectionId === null || $wsConnectionId === '' || !$this->ownership->ownsAll([$nodeId], $wsConnectionId)) {
                throw new DiagnosticDomainException(
                    'ws_owner_mismatch',
                    '当前连接不是该节点的有效 WebSocket owner',
                    409
                );
            }
            return;
        }

        // REST mode: never call NodeConnectionOwnership::claim.
        // When an active WS owner exists, only accept fallback requests matching that owner's registered runtime_id.
        $activeWsOwner = null;
        try {
            $activeWsOwner = Redis::get(NodeConnectionOwnership::ownerKey($nodeId));
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '无法校验节点连接所有权状态',
                503
            );
        }

        $registeredRuntime = null;
        try {
            $rawRuntime = Redis::get(self::PREFIX_NODE_RUNTIME . $nodeId);
            if (is_string($rawRuntime) && $rawRuntime !== '') {
                $decoded = json_decode($rawRuntime, true);
                if (is_array($decoded)) {
                    $registeredRuntime = $decoded;
                }
            }
        } catch (\Throwable) {
            // Ignore read error
        }

        if (is_string($activeWsOwner) && $activeWsOwner !== '') {
            $registeredRuntimeId = is_string($registeredRuntime['runtime_id'] ?? null)
                ? $registeredRuntime['runtime_id']
                : '';
            $registeredWsConn = is_string($registeredRuntime['ws_connection_id'] ?? null)
                ? $registeredRuntime['ws_connection_id']
                : '';

            if ($registeredRuntimeId === '' || $registeredRuntimeId !== $runtimeId || ($registeredWsConn !== '' && $registeredWsConn !== $activeWsOwner)) {
                throw new DiagnosticDomainException(
                    'ws_owner_runtime_conflict',
                    '节点当前由活动 WebSocket 连接持有，拒绝未绑定或不一致的 REST runtime 请求',
                    409
                );
            }
            return;
        }

        // Pure REST mode: an ACK cannot register a new runtime if it doesn't match the current registered runtime
        if (!$isSnapshotRequest && $registeredRuntime !== null) {
            $registeredRuntimeId = is_string($registeredRuntime['runtime_id'] ?? null)
                ? $registeredRuntime['runtime_id']
                : '';
            if ($registeredRuntimeId !== '' && $registeredRuntimeId !== $runtimeId) {
                throw new DiagnosticDomainException(
                    'stale_ack_runtime_conflict',
                    '迟到的 ACK 不能覆盖或重新登记当前节点诊断 runtime',
                    409
                );
            }
        }
    }

    private function checkNeedsNewLease(string $idempRedisKey, string $subjectRedisKey, string $expectedFingerprint): bool
    {
        try {
            $nowMs = $this->readRedisTimeMs();
            $controlRaw = Redis::get(self::KEY_CONTROL);
            $control = is_string($controlRaw) && $controlRaw !== '' ? json_decode($controlRaw, true) : null;
            $controlEpoch = is_array($control) && isset($control['epoch']) ? (string) $control['epoch'] : '';
            $activeMembers = $controlEpoch !== '' ? Redis::zrange(self::KEY_ACTIVE, 0, -1) : [];
            $activeSet = is_array($activeMembers) ? array_fill_keys(array_map('strval', $activeMembers), true) : [];

            $idempRaw = Redis::get($idempRedisKey);
            if (is_string($idempRaw) && $idempRaw !== '') {
                $idemp = json_decode($idempRaw, true);
                if (is_array($idemp)) {
                    if (($idemp['fingerprint'] ?? '') !== $expectedFingerprint) {
                        throw new DiagnosticDomainException(
                            'idempotency_key_conflict',
                            '相同的 idempotency_key 不能用于不同参数的请求',
                            409
                        );
                    }
                    $boundSid = (string) ($idemp['session_id'] ?? '');
                    if ($boundSid !== '' && $controlEpoch !== '' && isset($activeSet[$boundSid])) {
                        $boundRaw = Redis::get(self::PREFIX_SESSION . $boundSid);
                        if (is_string($boundRaw) && $boundRaw !== '') {
                            $boundSession = json_decode($boundRaw, true);
                            if (
                                is_array($boundSession)
                                && ($boundSession['created_control_epoch'] ?? '') === $controlEpoch
                                && ($boundSession['enabled'] ?? false) === true
                                && (int) ($boundSession['expires_at_ms'] ?? 0) > $nowMs
                            ) {
                                return false;
                            }
                        }
                    }
                }
            }

            $existingSid = Redis::get($subjectRedisKey);
            if (is_string($existingSid) && $existingSid !== '' && $controlEpoch !== '' && isset($activeSet[$existingSid])) {
                $sRaw = Redis::get(self::PREFIX_SESSION . $existingSid);
                if (is_string($sRaw) && $sRaw !== '') {
                    $s = json_decode($sRaw, true);
                    if (
                        is_array($s)
                        && ($s['created_control_epoch'] ?? '') === $controlEpoch
                        && ($s['enabled'] ?? false) === true
                        && (int) ($s['expires_at_ms'] ?? 0) > $nowMs
                    ) {
                        return false;
                    }
                }
            }
        } catch (DiagnosticDomainException $e) {
            throw $e;
        } catch (\Throwable) {
            // Let the Lua script handle Redis availability errors uniformly
        }

        return true;
    }

    /**
     * Reconcile eligible_node_ids across all active diagnostic sessions (bounded to at most 10 sessions).
     * Invoked during node snapshot construction and user/group/node sync operations so eligibility
     * changes take effect even when no admin has the order status page open.
     */
    public function reconcileActiveSessionsEligibility(?int $nowMs = null): void
    {
        try {
            $activeIds = Redis::zrange(self::KEY_ACTIVE, 0, -1);
            if (!is_array($activeIds) || $activeIds === []) {
                return;
            }
            $controlRaw = Redis::get(self::KEY_CONTROL);
            $control = is_string($controlRaw) && $controlRaw !== '' ? json_decode($controlRaw, true) : null;
            if (!is_array($control) || !isset($control['epoch'])) {
                return;
            }
            $controlEpoch = (string) $control['epoch'];
            $effectiveNowMs = $nowMs ?? $this->readRedisTimeMs();

            foreach ($activeIds as $sessionId) {
                $sessionId = (string) $sessionId;
                if ($sessionId === '') {
                    continue;
                }
                $sessionRaw = Redis::get(self::PREFIX_SESSION . $sessionId);
                if (!is_string($sessionRaw) || $sessionRaw === '') {
                    continue;
                }
                $session = json_decode($sessionRaw, true);
                if (
                    !is_array($session)
                    || ($session['created_control_epoch'] ?? '') !== $controlEpoch
                    || ($session['enabled'] ?? false) !== true
                    || (int) ($session['expires_at_ms'] ?? 0) <= $effectiveNowMs
                ) {
                    continue;
                }

                $subscriberUserId = (int) ($session['subscriber_user_id'] ?? 0);
                $subscriber = $subscriberUserId > 0 ? User::find($subscriberUserId) : null;
                $currentEligibleIds = $subscriber !== null
                    ? $this->identityService->resolveEligibleNodesForSubscriber($subscriber)
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->values()
                        ->all()
                    : [];

                $previousIds = array_values(array_map('intval', $session['eligible_node_ids'] ?? []));
                sort($previousIds);
                $sortedCurrent = $currentEligibleIds;
                sort($sortedCurrent);

                if ($previousIds !== $sortedCurrent) {
                    $this->reconcileSessionEligibleNodes(
                        $sessionId,
                        $currentEligibleIds,
                        $previousIds,
                        $effectiveNowMs
                    );
                }
            }
        } catch (\Throwable) {
            // Best-effort reconciliation; do not block snapshot or node sync if DB/Redis is transiently unavailable.
        }
    }

    /**
     * @param list<int> $currentEligibleIds
     * @param list<int> $previousIds
     * @return array{session: array<string, mixed>, control_epoch: string, control_version: string}|null
     */
    private function reconcileSessionEligibleNodes(
        string $sessionId,
        array $currentEligibleIds,
        array $previousIds,
        int $nowMs
    ): ?array {
        try {
            $raw = Redis::command('eval', [
                self::RECONCILE_ELIGIBLE_NODES_SCRIPT,
                [
                    self::KEY_CONTROL,
                    self::KEY_ACTIVE,
                    self::PREFIX_SESSION . $sessionId,
                    $sessionId,
                    json_encode($currentEligibleIds, JSON_THROW_ON_ERROR),
                    (string) self::RETENTION_TTL_SECONDS,
                    (string) $nowMs,
                ],
                3,
            ]);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded) || !($decoded['changed'] ?? false)) {
                return null;
            }

            $allAffected = array_values(array_unique(array_merge($previousIds, $currentEligibleIds)));
            $this->notifyNodesDiagnosticsChanged(
                $allAffected,
                (string) $decoded['control_epoch'],
                (string) $decoded['control_version']
            );

            return [
                'session' => $decoded['session'],
                'control_epoch' => (string) $decoded['control_epoch'],
                'control_version' => (string) $decoded['control_version'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $session
     * @param Collection<int, Server> $eligibleNodes
     * @return array<string, mixed>
     */
    private function formatSessionStatusResponse(
        ?array $session,
        Collection $eligibleNodes,
        string $panelScope,
        string $controlEpoch,
        string $controlVersion,
        int $nowMs,
        bool $idempotent,
        bool $reused
    ): array {
        $eligibleNodeIds = $eligibleNodes->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $capMap = $this->getNodesCapabilityMap($eligibleNodeIds);

        if ($session === null) {
            $nodeStatuses = [];
            foreach ($eligibleNodes as $node) {
                $nid = (int) $node->id;
                $cap = $capMap[$nid] ?? [];
                $supported = (bool) ($cap['supported'] ?? false);
                $online = NodeSyncService::isNodeOnline($nid);
                $nodeStatuses[] = [
                    'node_id' => $nid,
                    'node_name' => (string) $node->name,
                    'online' => $online,
                    'capability' => $supported ? 'supported' : 'unsupported',
                    'state' => $supported ? 'idle' : 'unsupported',
                    'effective' => false,
                    'ack' => null,
                    'observer_state' => $cap['observer_state'] ?? 'unknown',
                    'queue_bytes' => $cap['queue_bytes'] ?? null,
                    'dropped_total' => $cap['dropped_total'] ?? null,
                ];
            }

            return [
                'panel_scope' => $panelScope,
                'control_epoch' => $controlEpoch,
                'control_version' => $controlVersion,
                'server_time' => self::formatUtcIso8601($nowMs),
                'state' => 'idle',
                'session' => null,
                'nodes' => $nodeStatuses,
            ];
        }

        $sessionId = (string) $session['session_id'];
        $enabled = (bool) ($session['enabled'] ?? false);
        $expiresAtMs = (int) ($session['expires_at_ms'] ?? 0);
        $createdAtMs = (int) ($session['created_at_ms'] ?? 0);
        $stoppedControlVersion = isset($session['stopped_control_version'])
            ? (int) $session['stopped_control_version']
            : null;
        $isExpired = $nowMs >= $expiresAtMs;

        $nodeStatuses = [];
        $supportedCount = 0;
        $effectiveCount = 0;
        $pendingStopConfirmationCount = 0;

        foreach ($eligibleNodes as $node) {
            $nid = (int) $node->id;
            $cap = $capMap[$nid] ?? [];
            $supported = (bool) ($cap['supported'] ?? false);
            $online = NodeSyncService::isNodeOnline($nid);

            $ack = null;
            try {
                $ackRaw = Redis::get(self::PREFIX_ACK . $sessionId . ':' . $nid);
                if (is_string($ackRaw) && $ackRaw !== '') {
                    $decodedAck = json_decode($ackRaw, true);
                    if (is_array($decodedAck)) {
                        $ack = $decodedAck;
                    }
                }
            } catch (\Throwable) {
                $ack = null;
            }

            if ($ack !== null && ($ack['supported'] ?? true) === true) {
                $supported = true;
            }

            if ($supported) {
                $supportedCount++;
            }

            $ackEffective = $ack !== null
                && ($ack['effective'] ?? false) === true
                && ($ack['control_epoch'] ?? '') === $controlEpoch;

            $ackVersion = $ack !== null ? (int) ($ack['control_version'] ?? 0) : -1;

            if (!$supported) {
                $nodeState = 'unsupported';
                $nodeMessage = '节点版本不支持动态连接诊断';
            } elseif ($isExpired) {
                $nodeState = 'expired';
                $nodeMessage = '诊断租约已到期自动关闭';
            } elseif (!$enabled) {
                $confirmedStop = ($ack === null)
                    || ($ackEffective === false && ($stoppedControlVersion === null || $ackVersion >= $stoppedControlVersion));
                if ($confirmedStop) {
                    $nodeState = 'stopped';
                    $nodeMessage = '已确认停止详细诊断';
                } else {
                    $nodeState = 'stopping';
                    $pendingStopConfirmationCount++;
                    $nodeMessage = $online
                        ? '等待节点确认停止'
                        : '待确认停止，最晚按原租约到期';
                }
            } else {
                if ($ackEffective) {
                    $nodeState = 'active';
                    $effectiveCount++;
                    $nodeMessage = '详细诊断已生效';
                } elseif ($ack !== null && ($ack['effective'] ?? false) === false && ($ack['reason'] ?? null) !== null) {
                    $nodeState = 'rejected';
                    $nodeMessage = '节点拒绝启用: ' . (string) $ack['reason'];
                } else {
                    $nodeState = 'pending';
                    $nodeMessage = '等待节点确认';
                }
            }

            $nodeStatuses[] = [
                'node_id' => $nid,
                'node_name' => (string) $node->name,
                'online' => $online,
                'capability' => $supported ? 'supported' : 'unsupported',
                'state' => $nodeState,
                'message' => $nodeMessage,
                'effective' => $enabled && !$isExpired && $ackEffective,
                'ack' => $ack !== null ? [
                    'request_id' => $ack['request_id'] ?? null,
                    'runtime_id' => $ack['runtime_id'] ?? null,
                    'control_epoch' => $ack['control_epoch'] ?? null,
                    'control_version' => $ack['control_version'] ?? null,
                    'revision' => $ack['revision'] ?? null,
                    'effective' => (bool) ($ack['effective'] ?? false),
                    'expires_at' => $ack['expires_at'] ?? null,
                    'reason' => $ack['reason'] ?? null,
                    'acked_at' => isset($ack['acked_at_ms']) ? self::formatUtcIso8601((int) $ack['acked_at_ms']) : null,
                ] : null,
                'observer_state' => $cap['observer_state'] ?? 'unknown',
                'queue_bytes' => $cap['queue_bytes'] ?? null,
                'dropped_total' => $cap['dropped_total'] ?? null,
            ];
        }

        if ($isExpired) {
            $overallState = $enabled ? 'expired' : 'stopped';
        } elseif (!$enabled) {
            $overallState = $pendingStopConfirmationCount > 0 ? 'stopping' : 'stopped';
        } else {
            // Start never prematurely returns 'active' before ACKs arrive
            $totalEligible = count($eligibleNodeIds);
            if ($effectiveCount === 0) {
                $overallState = 'pending';
            } elseif ($effectiveCount === $totalEligible && $supportedCount === $totalEligible) {
                $overallState = 'active';
            } else {
                $overallState = 'partial';
            }
        }

        $remainingSeconds = $enabled && !$isExpired
            ? max(0, (int) ceil(($expiresAtMs - $nowMs) / 1000))
            : 0;

        return [
            'panel_scope' => $panelScope,
            'control_epoch' => $controlEpoch,
            'control_version' => $controlVersion,
            'server_time' => self::formatUtcIso8601($nowMs),
            'state' => $overallState,
            'idempotent_replay' => $idempotent,
            'reused_existing_session' => $reused,
            'session' => [
                'session_id' => $sessionId,
                'diagnostic_id' => (string) $session['diagnostic_id'],
                'order_id' => (int) ($session['order_id'] ?? 0),
                'revision' => (int) ($session['revision'] ?? 1),
                'enabled' => $enabled && !$isExpired,
                'issued_at' => self::formatUtcIso8601($createdAtMs),
                'expires_at' => self::formatUtcIso8601($expiresAtMs),
                'stopped_at' => isset($session['stopped_at_ms']) && is_numeric($session['stopped_at_ms'])
                    ? self::formatUtcIso8601((int) $session['stopped_at_ms'])
                    : null,
                'remaining_seconds' => $remainingSeconds,
                'max_duration_seconds' => (int) ($session['max_duration_seconds'] ?? self::LEASE_DURATION_SECONDS),
                'effective_nodes_count' => $effectiveCount,
                'supported_nodes_count' => $supportedCount,
                'eligible_nodes_count' => count($eligibleNodeIds),
            ],
            'nodes' => $nodeStatuses,
        ];
    }

    /**
     * Notify online nodes that diagnostic control_epoch / control_version has changed.
     * WS only sends a lightweight notification (`sync.diagnostics`); nodes pull the full snapshot.
     *
     * @param list<int> $nodeIds
     */
    private function notifyNodesDiagnosticsChanged(array $nodeIds, string $controlEpoch, string $controlVersion): void
    {
        foreach (array_values(array_unique($nodeIds)) as $nodeId) {
            $nodeId = (int) $nodeId;
            if ($nodeId <= 0 || !NodeSyncService::isNodeOnline($nodeId)) {
                continue;
            }
            NodeSyncService::push($nodeId, 'sync.diagnostics', [
                'control_epoch' => $controlEpoch,
                'control_version' => $controlVersion,
            ]);
        }
    }

    private function validateIdempotencyKey(string $idempotencyKey): string
    {
        $trimmed = trim($idempotencyKey);
        if (
            $trimmed === ''
            || strlen($trimmed) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $trimmed) === 1
        ) {
            throw new DiagnosticDomainException(
                'invalid_idempotency_key',
                'idempotency_key 必须为 1 至 128 字符的非空字符串且不含控制字符',
                422
            );
        }

        return $trimmed;
    }

    private function buildIdempotencyFingerprint(
        string $action,
        int $orderId,
        string $diagnosticId,
        ?string $sessionId
    ): string {
        return hash('sha256', implode('|', [
            $action,
            (string) $orderId,
            $diagnosticId,
            (string) ($sessionId ?? ''),
        ]));
    }

    private function readRedisTimeMs(): int
    {
        if ($this->forcedNowMs !== null && $this->forcedNowMs > 0) {
            return $this->forcedNowMs;
        }

        try {
            $time = Redis::command('time', []);
            if (is_array($time) && isset($time[0], $time[1])) {
                return ((int) $time[0]) * 1000 + intdiv((int) $time[1], 1000);
            }
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '无法读取 Redis 控制时钟',
                503
            );
        }

        return (int) round(microtime(true) * 1000);
    }

    /**
     * @return array{epoch: string, control_version: string}
     */
    private function readControlState(int $nowMs): array
    {
        try {
            $raw = Redis::get(self::KEY_CONTROL);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['epoch'], $decoded['control_version'])) {
                    return [
                        'epoch' => (string) $decoded['epoch'],
                        'control_version' => (string) $decoded['control_version'],
                    ];
                }
            }
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_control_store_unavailable',
                '无法读取诊断控制元数据',
                503
            );
        }

        return $this->resetControlEpoch();
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function throwForLuaError(string $err, array $decoded): never
    {
        match ($err) {
            'idempotency_conflict' => throw new DiagnosticDomainException(
                'idempotency_key_conflict',
                '相同的 idempotency_key 不能用于不同参数的请求',
                409
            ),
            'max_active_sessions_exceeded' => throw new DiagnosticDomainException(
                'diagnostics_max_active_sessions_exceeded',
                '当前面板同时开启的 15 分钟详细诊断客户数已达上限（10 个）',
                409,
                ['active_count' => $decoded['active_count'] ?? self::MAX_CONCURRENT_SESSIONS]
            ),
            'clock_rollback' => throw new DiagnosticDomainException(
                'diagnostics_control_clock_anomaly',
                '检测到诊断控制时钟回退异常，已暂停新建详细诊断租约',
                503,
                [
                    'last_server_time_ms' => $decoded['last_server_time_ms'] ?? null,
                    'now_ms' => $decoded['now_ms'] ?? null,
                ]
            ),
            'session_not_found' => throw new DiagnosticDomainException(
                'diagnostic_session_not_found',
                '指定的诊断租约会话不存在或已过期清理',
                404
            ),
            'session_subject_mismatch' => throw new DiagnosticDomainException(
                'diagnostic_session_subject_mismatch',
                '诊断租约会话不属于当前订单对应的订阅客户',
                409
            ),
            'ack_binding_expired' => throw new DiagnosticDomainException(
                'ack_binding_expired',
                '节点快照 ACK 绑定不存在或已超过 60 秒有效期',
                409
            ),
            'ack_binding_mismatch' => throw new DiagnosticDomainException(
                'ack_binding_mismatch',
                '节点 ACK 与最近一次下发快照的 request_id、runtime_id、transport、认证主体或版本不匹配',
                409
            ),
            'control_epoch_missing', 'stale_control_epoch' => throw new DiagnosticDomainException(
                'stale_control_epoch',
                '节点 ACK 的 control_epoch 已过期，请重新拉取权威快照',
                409
            ),
            default => throw new DiagnosticDomainException(
                'diagnostics_control_error',
                '诊断租约控制操作失败: ' . $err,
                500
            ),
        };
    }

    public static function formatUtcIso8601(int $epochMs): string
    {
        $seconds = intdiv($epochMs, 1000);
        return gmdate('Y-m-d\TH:i:s\Z', max(0, $seconds));
    }
}
