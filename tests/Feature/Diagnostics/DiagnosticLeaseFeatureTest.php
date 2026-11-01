<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Http\Middleware\InitializePlugins;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticContract;
use App\Services\Diagnostics\DiagnosticLeaseService;
use App\Services\NodeConnectionOwnership;
use App\Services\ServerMachineCredentialService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDiagnosticRedis;
use Tests\TestCase;

final class DiagnosticLeaseFeatureTest extends TestCase
{
    use RefreshDatabase;

    private FakeDiagnosticRedis $fakeRedis;
    private string $panelScope;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        Cache::flush();
        admin_setting([
            'server_token' => 'shared-server-token-123',
            'server_ws_enable' => 0,
        ]);

        $this->fakeRedis = (new FakeDiagnosticRedis())->install();

        $this->admin = $this->makeUser('admin-lease@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($this->admin);

        $this->panelScope = (string) $this->postJson($this->adminUri('diagnostics/initialize'))
            ->assertOk()
            ->json('data.panel_scope');

        config()->set('diagnostics.health_url', 'http://127.0.0.1:19428/diagnostics/health');
        $this->fakeHealthyStorage();
    }

    public function test_t09_t15_start_lease_idempotency_reuse_concurrency_limit_and_stop(): void
    {
        $plan = $this->makePlan();
        $node = $this->makeServer(['group_ids' => ['1']]);
        $order1 = $this->createStandardOrder($plan, 'sub-1@example.com', '20261018LSE0001');

        $startUri = $this->adminUri('order/diagnostics/start');
        $stopUri = $this->adminUri('order/diagnostics/stop');
        $statusUri = $this->adminUri('order/diagnostics/status');

        // Reject diagnostic_id mode on start/stop/status
        $this->postJson($startUri, [
            'order_id' => $order1->id,
            'diagnostic_id' => '0123456789abcdef01234567',
            'idempotency_key' => 'idemp-mode-err',
        ])->assertStatus(422)->assertJsonPath('error.code', 'lease_requires_order_mode');

        $this->getJson($statusUri . '?order_id=' . $order1->id . '&diagnostic_id=0123456789abcdef01234567')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'lease_requires_order_mode');

        // 1. Start new 15-minute lease
        $first = $this->postJson($startUri, [
            'order_id' => $order1->id,
            'idempotency_key' => 'idemp-start-1',
        ])->assertOk();

        $first->assertJsonPath('data.state', 'pending');
        $first->assertJsonPath('data.idempotent_replay', false);
        $first->assertJsonPath('data.reused_existing_session', false);
        $first->assertJsonPath('data.session.remaining_seconds', 900);
        $first->assertJsonPath('data.session.max_duration_seconds', 900);
        $sessionId = (string) $first->json('data.session.session_id');
        $controlEpoch = (string) $first->json('data.control_epoch');
        $controlVersion = (string) $first->json('data.control_version');
        $expiresAt = (string) $first->json('data.session.expires_at');

        // 2. Same idempotency_key replay -> idempotent_replay = true, no version increment
        $replay = $this->postJson($startUri, [
            'order_id' => $order1->id,
            'idempotency_key' => 'idemp-start-1',
        ])->assertOk();
        $replay->assertJsonPath('data.idempotent_replay', true);
        $replay->assertJsonPath('data.session.session_id', $sessionId);
        $replay->assertJsonPath('data.control_version', $controlVersion);

        // 3. Reusing same idempotency_key with different order_id -> 409 idempotency_key_conflict
        $order2 = $this->createStandardOrder($plan, 'sub-2@example.com', '20261018LSE0002');
        $this->postJson($startUri, [
            'order_id' => $order2->id,
            'idempotency_key' => 'idemp-start-1',
        ])->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_conflict');

        // 4. Advance clock by 60s and call start with a new idempotency_key for same order1 -> reuses existing session without extending expiration
        $this->fakeRedis->nowMs += 60_000;
        $reused = $this->postJson($startUri, [
            'order_id' => $order1->id,
            'idempotency_key' => 'idemp-start-1-new',
        ])->assertOk();
        $reused->assertJsonPath('data.reused_existing_session', true);
        $reused->assertJsonPath('data.session.session_id', $sessionId);
        $reused->assertJsonPath('data.session.expires_at', $expiresAt);
        $reused->assertJsonPath('data.session.remaining_seconds', 840);

        // 5. Fill up to 10 concurrent sessions across distinct subscribers
        $orders = [$order1];
        for ($i = 2; $i <= 10; $i++) {
            $o = ($i === 2) ? $order2 : $this->createStandardOrder($plan, "sub-{$i}@example.com", sprintf('20261018LSE%04d', $i));
            $orders[] = $o;
            $this->postJson($startUri, [
                'order_id' => $o->id,
                'idempotency_key' => "idemp-fill-{$i}",
            ])->assertOk();
        }

        // 11th subscriber must be rejected with 409 diagnostics_max_active_sessions_exceeded
        $order11 = $this->createStandardOrder($plan, 'sub-11@example.com', '20261018LSE0011');
        $this->postJson($startUri, [
            'order_id' => $order11->id,
            'idempotency_key' => 'idemp-fill-11',
        ])->assertStatus(409)->assertJsonPath('error.code', 'diagnostics_max_active_sessions_exceeded');

        // 6. Stop order1's lease -> frees 1 slot and transitions session to stopped
        $stopRes = $this->postJson($stopUri, [
            'order_id' => $order1->id,
            'session_id' => $sessionId,
            'idempotency_key' => 'idemp-stop-1',
        ])->assertOk();
        $stopRes->assertJsonPath('data.state', 'stopped');
        $stopRes->assertJsonPath('data.session.enabled', false);

        // Repeat stop with same idempotency_key -> idempotent_replay = true
        $stopReplay = $this->postJson($stopUri, [
            'order_id' => $order1->id,
            'session_id' => $sessionId,
            'idempotency_key' => 'idemp-stop-1',
        ])->assertOk();
        $stopReplay->assertJsonPath('data.idempotent_replay', true);

        // Now 11th subscriber can start a lease
        $this->postJson($startUri, [
            'order_id' => $order11->id,
            'idempotency_key' => 'idemp-fill-11-retry',
        ])->assertOk()->assertJsonPath('data.session.enabled', true);
    }

    public function test_t09_t10_t12_node_snapshot_ack_binding_v2_machine_auth_and_epoch_clock_recovery(): void
    {
        $plan = $this->makePlan();
        $machineA = ServerMachine::create([
            'name' => 'machine-a',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $machineB = ServerMachine::create([
            'name' => 'machine-b',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);

        $credService = app(ServerMachineCredentialService::class);
        $enrollA = $credService->createEnrollment($machineA, revokeExisting: false);
        $tokenA = $credService->exchangeEnrollment($machineA->id, $enrollA->plainTextCode);

        $nodeV1 = $this->makeServer([
            'name' => 'Legacy V1 Node',
            'group_ids' => ['1'],
            'machine_id' => null,
        ]);
        $nodeV2A = $this->makeServer([
            'name' => 'Machine A Node',
            'group_ids' => ['1'],
            'machine_id' => $machineA->id,
        ]);
        $nodeV2B = $this->makeServer([
            'name' => 'Machine B Node',
            'group_ids' => ['1'],
            'machine_id' => $machineB->id,
        ]);

        $order = $this->createStandardOrder($plan, 'sub-ack@example.com', '20261018ACK0001');

        // Start lease for order
        $startRes = $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $order->id,
            'idempotency_key' => 'idemp-ack-start',
        ])->assertOk();
        $sessionId = (string) $startRes->json('data.session.session_id');
        $diagId = (string) $startRes->json('data.session.diagnostic_id');

        // T12: V2 diagnostics endpoint MUST reject requests without machine_id BEFORE authentication (even with shared server_token)
        $this->postJson('/api/v2/server/diagnostics/snapshot', [
            'token' => 'shared-server-token-123',
            'node_id' => $nodeV2A->id,
            'request_id' => str_repeat('1', 32),
            'runtime_id' => str_repeat('a', 32),
        ])->assertStatus(422)->assertJsonPath('error.code', 'machine_id_required');

        // T12: V2 diagnostics endpoint MUST reject machine_id + shared server_token
        $this->postJson('/api/v2/server/diagnostics/snapshot', [
            'token' => 'shared-server-token-123',
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2A->id,
            'request_id' => str_repeat('1', 32),
            'runtime_id' => str_repeat('a', 32),
        ])->assertStatus(401);

        // T12: V2 diagnostics endpoint MUST reject Machine A accessing Machine B's node
        $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/snapshot', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2B->id,
            'request_id' => str_repeat('1', 32),
            'runtime_id' => str_repeat('a', 32),
        ])->assertStatus(400)->assertJsonPath('message', 'Node not found on this machine');

        // V2 valid machine snapshot pull for nodeV2A
        $reqIdV2 = '11111111111111111111111111111111';
        $runtimeV2 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $snapV2 = $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/snapshot', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2A->id,
            'request_id' => $reqIdV2,
            'runtime_id' => $runtimeV2,
            'capabilities' => [
                'supported' => true,
                'schema_version' => 1,
                'observer_state' => 'healthy',
            ],
        ])->assertOk();

        $snapV2->assertJsonPath('data.panel_scope', $this->panelScope);
        $snapV2->assertJsonPath('data.complete', true);
        $snapV2->assertJsonCount(1, 'data.sessions');
        $snapV2->assertJsonPath('data.sessions.0.session_id', $sessionId);
        $snapV2->assertJsonPath('data.sessions.0.diagnostic_id', $diagId);
        $epoch = (string) $snapV2->json('data.control_epoch');
        $version = (string) $snapV2->json('data.control_version');

        // ACK with mismatched request_id must be rejected with 409 ack_binding_mismatch
        $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/ack', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2A->id,
            'request_id' => '99999999999999999999999999999999',
            'runtime_id' => $runtimeV2,
            'control_epoch' => $epoch,
            'control_version' => $version,
            'sessions' => [
                [
                    'session_id' => $sessionId,
                    'revision' => 1,
                    'effective' => true,
                ],
            ],
        ])->assertStatus(409)->assertJsonPath('error.code', 'ack_binding_mismatch');

        // Valid ACK from nodeV2A within 60s binding TTL
        $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/ack', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2A->id,
            'request_id' => $reqIdV2,
            'runtime_id' => $runtimeV2,
            'control_epoch' => $epoch,
            'control_version' => $version,
            'sessions' => [
                [
                    'session_id' => $sessionId,
                    'revision' => 1,
                    'effective' => true,
                ],
            ],
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        // V1 legacy node snapshot & ACK for nodeV1
        $reqIdV1 = '22222222222222222222222222222222';
        $runtimeV1 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $snapV1 = $this->postJson('/api/v1/server/diagnostics/snapshot', [
            'token' => 'shared-server-token-123',
            'node_id' => $nodeV1->id,
            'node_type' => 'vless',
            'request_id' => $reqIdV1,
            'runtime_id' => $runtimeV1,
            'capabilities' => [
                'supported' => true,
                'schema_version' => 1,
            ],
        ])->assertOk();
        $snapV1->assertJsonCount(1, 'data.sessions');

        // Advance time by 61 seconds so V1 ACK binding expires -> 409 ack_binding_expired
        $this->fakeRedis->nowMs += 61_000;
        $this->postJson('/api/v1/server/diagnostics/ack', [
            'token' => 'shared-server-token-123',
            'node_id' => $nodeV1->id,
            'node_type' => 'vless',
            'request_id' => $reqIdV1,
            'runtime_id' => $runtimeV1,
            'control_epoch' => $epoch,
            'control_version' => $version,
            'sessions' => [
                [
                    'session_id' => $sessionId,
                    'revision' => 1,
                    'effective' => true,
                ],
            ],
        ])->assertStatus(409)->assertJsonPath('error.code', 'ack_binding_expired');

        // Check admin status: 1 of 3 eligible nodes effective -> state = partial
        Sanctum::actingAs($this->admin);
        $statusRes = $this->getJson($this->adminUri('order/diagnostics/status') . '?order_id=' . $order->id)->assertOk();
        $statusRes->assertJsonPath('data.state', 'partial');
        $statusRes->assertJsonPath('data.session.effective_nodes_count', 1);

        // WS ownership conflict: if nodeV1 has an active WS owner with a different runtime, REST snapshot with runtimeV1 is rejected with 409 ws_owner_runtime_conflict
        $this->fakeRedis->rawSet(NodeConnectionOwnership::ownerKey($nodeV1->id), 'ws-conn-100', 300);
        app(DiagnosticLeaseService::class)->recordNodeCapabilities(
            $nodeV1->id,
            'cccccccccccccccccccccccccccccccc',
            ['supported' => true],
            'ws-conn-100',
            true
        );

        $this->postJson('/api/v1/server/diagnostics/snapshot', [
            'token' => 'shared-server-token-123',
            'node_id' => $nodeV1->id,
            'node_type' => 'vless',
            'request_id' => '33333333333333333333333333333333',
            'runtime_id' => $runtimeV1,
        ])->assertStatus(409)->assertJsonPath('error.code', 'ws_owner_runtime_conflict');

        // T10: Redis control key loss recovery -> must generate new control_epoch and NEVER resurrect residual sessions
        unset($this->fakeRedis->kv[DiagnosticLeaseService::KEY_CONTROL]);
        $recoveredSnap = $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/snapshot', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeV2A->id,
            'request_id' => '44444444444444444444444444444444',
            'runtime_id' => $runtimeV2,
        ])->assertOk();

        $newEpoch = (string) $recoveredSnap->json('data.control_epoch');
        $this->assertNotSame($epoch, $newEpoch);
        $recoveredSnap->assertJsonPath('data.control_version', '0');
        $recoveredSnap->assertJsonCount(0, 'data.sessions');

        // After control epoch loss, status must report idle (never surface orphaned session from wiped epoch)
        Sanctum::actingAs($this->admin);
        $this->getJson($this->adminUri('order/diagnostics/status') . '?order_id=' . $order->id)
            ->assertOk()
            ->assertJsonPath('data.state', 'idle')
            ->assertJsonPath('data.session', null);

        // Replaying start with the old idempotency_key after epoch loss must create a fresh session in newEpoch and add it to diag:v1:active
        $restartedAfterLoss = $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $order->id,
            'idempotency_key' => 'idemp-ack-start',
        ])->assertOk();
        $restartedSessionId = (string) $restartedAfterLoss->json('data.session.session_id');
        $this->assertNotSame($sessionId, $restartedSessionId);
        $restartedAfterLoss->assertJsonPath('data.control_epoch', $newEpoch);
        $restartedAfterLoss->assertJsonPath('data.idempotent_replay', false);
        $restartedAfterLoss->assertJsonPath('data.reused_existing_session', false);

        // If nodeV2B is dynamically added to group 1 while admin does not open status page, snapshot pull reconciles eligible_node_ids automatically
        $nodeAddedLater = $this->makeServer([
            'name' => 'Machine A Added Later Node',
            'group_ids' => ['2'],
            'machine_id' => $machineA->id,
        ]);
        $nodeAddedLater->update(['group_ids' => ['1']]);

        $snapAddedNode = $this->withToken($tokenA)->postJson('/api/v2/server/diagnostics/snapshot', [
            'machine_id' => $machineA->id,
            'node_id' => $nodeAddedLater->id,
            'request_id' => '55555555555555555555555555555555',
            'runtime_id' => $runtimeV2,
        ])->assertOk();
        $snapAddedNode->assertJsonCount(1, 'data.sessions');
        $snapAddedNode->assertJsonPath('data.sessions.0.session_id', $restartedSessionId);

        // T10: Server clock rollback > 1000ms -> 503 diagnostics_control_clock_anomaly
        $this->fakeRedis->nowMs -= 5_000;
        Sanctum::actingAs($this->admin);
        $orderClock = $this->createStandardOrder($plan, 'sub-clock@example.com', '20261018CLK0001');
        $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $orderClock->id,
            'idempotency_key' => 'idemp-clock-rollback',
        ])->assertStatus(503)->assertJsonPath('error.code', 'diagnostics_control_clock_anomaly');
    }

    public function test_t18_storage_health_gate_blocks_new_lease_when_unhealthy_or_latched_while_stop_still_succeeds(): void
    {
        $plan = $this->makePlan();
        $this->makeServer(['group_ids' => ['1']]);
        $orderActive = $this->createStandardOrder($plan, 'sub-active@example.com', '20261018HLT0001');
        $orderBlocked = $this->createStandardOrder($plan, 'sub-blocked@example.com', '20261018HLT0002');

        // Start orderActive while storage is healthy
        $startRes = $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $orderActive->id,
            'idempotency_key' => 'idemp-hlt-active',
        ])->assertOk();
        $sessionId = (string) $startRes->json('data.session.session_id');

        // Now simulate capacity guard latched / blocked on GET /diagnostics/health
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'http://127.0.0.1:19428/diagnostics/health' => Http::response([
                'schema_version' => 1,
                'panel_scope' => $this->panelScope,
                'storage_id' => 'diag-store-primary',
                'state' => 'ready',
                'latched' => true,
                'reason_codes' => ['data_used_exceeded_20gib'],
                'checked_at_utc' => '2026-10-18T10:00:00Z',
                'check_age_seconds' => 5,
            ], 200),
        ]);

        // Starting a NEW lease for orderBlocked must fail closed with 503 diagnostics_storage_not_ready
        $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $orderBlocked->id,
            'idempotency_key' => 'idemp-hlt-blocked',
        ])->assertStatus(503)->assertJsonPath('error.code', 'diagnostics_storage_not_ready');

        // Stopping the existing active lease for orderActive MUST still succeed even when storage is blocked
        $this->postJson($this->adminUri('order/diagnostics/stop'), [
            'order_id' => $orderActive->id,
            'session_id' => $sessionId,
            'idempotency_key' => 'idemp-hlt-stop',
        ])->assertOk()->assertJsonPath('data.state', 'stopped');
    }

    public function test_native_redis_prefix_start_status_ack_stop_and_revocation(): void
    {
        $this->withNativeRedis(function (\Redis $client, string $prefix, array &$legacyKeys): void {
            $this->exerciseNativeLeaseLifecycle($client, $prefix, $legacyKeys, false);
        });
    }

    public function test_native_redis_prefix_legacy_raw_sessions_remain_controllable_without_ttl_extension(): void
    {
        $this->withNativeRedis(function (\Redis $client, string $prefix, array &$legacyKeys): void {
            $this->exerciseNativeLeaseLifecycle($client, $prefix, $legacyKeys, true);
        });
    }

    public function test_native_redis_prefix_empty_namespace_preserves_existing_behavior(): void
    {
        $this->withNativeRedis(function (\Redis $client, string $prefix, array &$legacyKeys): void {
            $this->exerciseNativeLeaseLifecycle($client, $prefix, $legacyKeys, false);
        }, false);
    }

    public function test_native_redis_prefix_isolates_shared_legacy_ids_and_rejects_wrong_bindings(): void
    {
        $this->withNativeRedis(function (\Redis $clientA, string $prefixA, array &$legacyKeysA): void {
            $plan = $this->makePlan();
            $node = $this->makeServer(['group_ids' => ['1']]);
            $order = $this->createStandardOrder($plan, 'native-isolation@example.invalid', 'QA-NATIVE-ISOLATION');
            $service = app(DiagnosticLeaseService::class);
            $first = $service->startLease($this->admin->id, $order->id, 'qa-isolation-a');
            $sid = $first['session']['session_id'];
            $diagId = $first['session']['diagnostic_id'];
            $key = DiagnosticLeaseService::PREFIX_SESSION . $sid;
            $legacyKeysA[] = $key;
            $raw = $clientA->get($key);
            $this->assertIsString($raw);
            $record = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $clientA->rawCommand('RENAME', $prefixA . $key, $key);
            $ttl = $clientA->rawCommand('PTTL', $key);

            $this->withNativeRedis(function (\Redis $clientB, string $prefixB, array &$legacyKeysB) use ($sid, $diagId, $order, $record, $key, $node): void {
                $otherService = app(DiagnosticLeaseService::class);
                $otherControl = $otherService->resetControlEpoch();
                $clientB->setex(DiagnosticLeaseService::PREFIX_SUBJECT . $diagId, 3600, $sid);
                $clientB->zadd(DiagnosticLeaseService::KEY_ACTIVE, $record['expires_at_ms'], $sid);
                $this->assertSame('idle', $otherService->getOrderStatus($order->id)['state']);
                $this->postJson($this->adminUri('order/diagnostics/stop'), [
                    'order_id' => $order->id, 'session_id' => $sid, 'idempotency_key' => 'qa-cross-stop',
                ])->assertStatus(404)->assertJsonPath('error.code', 'diagnostic_session_not_found');
                $snapshot = $otherService->buildNodeSnapshot($node,
                    ['request_id' => str_repeat('3', 32), 'runtime_id' => str_repeat('b', 32)], 'rest', 'qa-other-owner');
                $this->assertSame([], $snapshot['sessions']);
                $other = $otherService->startLease($this->admin->id, $order->id, 'qa-isolation-b');
                $this->assertNotSame($sid, $other['session']['session_id']);
                $this->assertFalse($other['reused_existing_session']);
                $legacyKeysB[] = DiagnosticLeaseService::PREFIX_SESSION . $other['session']['session_id'];

                // Even a malformed canonical value must not fall back to global raw state.
                $clientB->setex(DiagnosticLeaseService::PREFIX_SUBJECT . $diagId, 3600, $sid);
                $clientB->setex($key, 3600, '{malformed');
                $this->assertSame('idle', $otherService->getOrderStatus($order->id)['state']);
                $this->postJson($this->adminUri('order/diagnostics/stop'), [
                    'order_id' => $order->id, 'session_id' => $sid, 'idempotency_key' => 'qa-malformed-stop',
                ])->assertStatus(503);
                $tombstone = array_replace($record, ['created_control_epoch' => $otherControl['epoch'], 'enabled' => false]);
                $clientB->setex($key, 3600, json_encode($tombstone, JSON_THROW_ON_ERROR));
                $this->assertSame('stopped', $otherService->getOrderStatus($order->id)['state']);
            });
            $this->assertSame($sid, $service->getOrderStatus($order->id)['session']['session_id']);
            foreach (['created_control_epoch' => '11111111-2222-4333-8444-555555555555',
                'diagnostic_id' => str_repeat('f', 24), 'subscriber_user_id' => 999999,
                'order_id' => 999999, 'session_id' => str_repeat('e', 32)] as $field => $wrong) {
                $clientA->rawCommand('SET', $key, json_encode(array_replace($record, [$field => $wrong]), JSON_THROW_ON_ERROR), 'KEEPTTL');
                $this->assertSame('idle', $service->getOrderStatus($order->id)['state'], $field . ' must bind the legacy record');
                $this->postJson($this->adminUri('order/diagnostics/stop'), [
                    'order_id' => $order->id, 'session_id' => $sid, 'idempotency_key' => 'qa-invalid-stop-' . $field,
                ])->assertStatus(404);
            }
            $clientA->rawCommand('SET', $key, $raw, 'KEEPTTL');
            $clientA->setex(DiagnosticLeaseService::PREFIX_SUBJECT . $diagId, 3600, str_repeat('d', 32));
            $this->assertSame('idle', $service->getOrderStatus($order->id)['state']);
            $clientA->setex(DiagnosticLeaseService::PREFIX_SUBJECT . $diagId, 3600, $sid);
            $service->setForcedNowMs((int) $record['expires_at_ms'] + 1);
            $this->assertSame('expired', $service->getOrderStatus($order->id)['state']);
            $expired = $service->buildNodeSnapshot($node,
                ['request_id' => str_repeat('4', 32), 'runtime_id' => str_repeat('c', 32)], 'rest', 'qa-expiry-owner');
            $this->assertSame([], $expired['sessions']);
            $this->assertSame($raw, $clientA->rawCommand('GET', $key));
            $this->assertGreaterThan(0, $clientA->rawCommand('PTTL', $key));
            $this->assertLessThanOrEqual($ttl, $clientA->rawCommand('PTTL', $key));
            $service->resetControlEpoch();
            $this->assertSame('idle', $service->getOrderStatus($order->id)['state'], 'An epoch reset must not resurrect legacy history');
        });
    }

    private function exerciseNativeLeaseLifecycle(\Redis $client, string $prefix, array &$legacyKeys, bool $legacy): void
    {
        $plan = $this->makePlan();
        $node = $this->makeServer(['group_ids' => ['1']]);
        $order = $this->createStandardOrder($plan, 'native-prefix@example.invalid', 'QA-NATIVE-PREFIX');
        $service = app(DiagnosticLeaseService::class);
        $start = $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $order->id, 'idempotency_key' => 'qa-native-start',
        ])->assertOk()->assertJsonPath('data.state', 'pending');
        $sid = (string) $start->json('data.session.session_id');
        $expires = (string) $start->json('data.session.expires_at');
        $sessionKey = DiagnosticLeaseService::PREFIX_SESSION . $sid;
        $ackKey = DiagnosticLeaseService::PREFIX_ACK . $sid . ':' . $node->id;
        // Track legacy keys even when the original bug creates them unexpectedly.
        $legacyKeys[] = $sessionKey;
        $legacyKeys[] = $ackKey;
        $this->assertIsString($client->get($sessionKey), 'Lua must write sessions inside the actual client prefix');
        if ($prefix !== '') {
            $this->assertFalse($client->rawCommand('GET', $sessionKey), 'New leases must not create global raw session keys');
        }
        $this->getJson($this->adminUri('order/diagnostics/status') . '?order_id=' . $order->id)
            ->assertOk()->assertJsonPath('data.session.session_id', $sid)->assertJsonPath('data.state', 'pending');

        $input = ['request_id' => str_repeat('1', 32), 'runtime_id' => str_repeat('a', 32),
            'capabilities' => ['supported' => true, 'schema_version' => 1]];
        $snapshot = $service->buildNodeSnapshot($node, $input, 'rest_v2', 'qa-native-owner');
        $this->assertSame([$sid], array_column($snapshot['sessions'], 'session_id'));
        $ack = ['request_id' => $snapshot['request_id'], 'runtime_id' => $snapshot['runtime_id'],
            'control_epoch' => $snapshot['control_epoch'], 'control_version' => $snapshot['control_version'],
            'sessions' => [['session_id' => $sid, 'revision' => 1, 'effective' => true, 'expires_at' => $expires]]];
        $service->handleNodeAck($node, $ack, 'rest_v2', 'qa-native-owner');
        $this->assertIsString($client->get($ackKey), 'Lua ACK must use the actual client prefix');
        foreach ([DiagnosticLeaseService::PREFIX_NODE_CAP, DiagnosticLeaseService::PREFIX_NODE_RUNTIME,
            DiagnosticLeaseService::PREFIX_ACK_BIND, DiagnosticLeaseService::PREFIX_NODE_ACKS] as $keyPrefix) {
            $this->assertSame(1, (int) $client->rawCommand('EXISTS', $prefix . $keyPrefix . $node->id));
        }
        if ($legacy) {
            $client->rawCommand('RENAME', $prefix . $sessionKey, $sessionKey);
            $client->rawCommand('RENAME', $prefix . $ackKey, $ackKey);
        }
        $physicalSession = ($legacy ? '' : $prefix) . $sessionKey;
        $ttlBefore = (int) $client->rawCommand('PTTL', $physicalSession);
        $legacyBytes = $client->rawCommand('GET', $physicalSession);
        $this->getJson($this->adminUri('order/diagnostics/status') . '?order_id=' . $order->id)
            ->assertOk()->assertJsonPath('data.session.session_id', $sid)->assertJsonPath('data.session.effective_nodes_count', 1);
        $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $order->id, 'idempotency_key' => 'qa-native-start',
        ])->assertOk()->assertJsonPath('data.idempotent_replay', true)->assertJsonPath('data.session.expires_at', $expires);
        $this->postJson($this->adminUri('order/diagnostics/start'), [
            'order_id' => $order->id, 'idempotency_key' => 'qa-native-reuse',
        ])->assertOk()->assertJsonPath('data.reused_existing_session', true)->assertJsonPath('data.session.session_id', $sid);
        $this->assertSame($legacyBytes, $client->rawCommand('GET', $physicalSession));
        $this->assertGreaterThan(0, $client->rawCommand('PTTL', $physicalSession));
        $this->assertLessThanOrEqual($ttlBefore, $client->rawCommand('PTTL', $physicalSession));

        $this->postJson($this->adminUri('order/diagnostics/stop'), [
            'order_id' => $order->id, 'session_id' => $sid, 'idempotency_key' => 'qa-native-stop',
        ])->assertOk()->assertJsonPath('data.session.enabled', false);
        $this->postJson($this->adminUri('order/diagnostics/stop'), [
            'order_id' => $order->id, 'session_id' => $sid, 'idempotency_key' => 'qa-native-stop',
        ])->assertOk()->assertJsonPath('data.idempotent_replay', true);
        $input['request_id'] = str_repeat('2', 32);
        $empty = $service->buildNodeSnapshot($node, $input, 'rest_v2', 'qa-native-owner');
        $this->assertSame([], $empty['sessions']);
        $service->handleNodeAck($node, ['request_id' => $empty['request_id'], 'runtime_id' => $empty['runtime_id'],
            'control_epoch' => $empty['control_epoch'], 'control_version' => $empty['control_version'], 'sessions' => []],
            'rest_v2', 'qa-native-owner');
        $this->getJson($this->adminUri('order/diagnostics/status') . '?order_id=' . $order->id)
            ->assertOk()->assertJsonPath('data.state', 'stopped');
        if ($legacy) {
            $this->assertSame($legacyBytes, $client->rawCommand('GET', $sessionKey), 'Legacy raw records remain untouched');
            $this->assertFalse(json_decode((string) $client->get($sessionKey), true)['enabled']);
        }
    }

    /** Only a coordinator-created disposable Redis server with a matching marker is allowed. */
    private function withNativeRedis(\Closure $run, bool $withPrefix = true): void
    {
        $marker = getenv('QA_REDIS_MARKER');
        if (!is_string($marker) || $marker === '') {
            $this->markTestSkipped('Set QA_REDIS_MARKER for the isolated native Redis prefix regression');
        }
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $marker);
        $this->assertTrue(extension_loaded('redis'), 'Native prefix regression requires phpredis');
        $host = getenv('QA_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('QA_REDIS_PORT') ?: 19449);
        $this->assertContains([$host, $port], [['127.0.0.1', 19449], ['redis', 6379]]);
        $prefix = $withPrefix ? 'qa-diag-' . bin2hex(random_bytes(16)) . ':' : '';
        $original = Redis::getFacadeRoot();
        $manager = new RedisManager($this->app, 'phpredis', [
            'options' => ['prefix' => $prefix],
            'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2, 'read_timeout' => 2],
        ]);
        $connection = $manager->connection();
        $client = $connection->client();
        $this->assertSame($marker, $client->rawCommand('GET', 'qa:diagnostics:isolated-server'));
        $pattern = $prefix . 'diag:v1:*';
        $this->assertSame([], $client->rawCommand('KEYS', $pattern), 'Native test must own a fresh namespace');
        $legacyKeys = [];
        Redis::swap($manager);
        try {
            $run($client, $prefix, $legacyKeys);
        } finally {
            try {
                foreach (array_unique(array_merge($client->rawCommand('KEYS', $pattern), $legacyKeys)) as $key) {
                    $this->assertTrue(str_starts_with($key, $prefix . 'diag:v1:') || in_array($key, $legacyKeys, true));
                    $client->rawCommand('DEL', $key);
                }
            } finally {
                Redis::swap($original);
                $connection->disconnect();
            }
        }
    }

    private function fakeHealthyStorage(): void
    {
        $scope = $this->panelScope;
        Http::fake([
            'http://127.0.0.1:19428/diagnostics/health' => Http::response([
                'schema_version' => 1,
                'panel_scope' => $scope,
                'storage_id' => 'diag-store-primary',
                'state' => 'ready',
                'latched' => false,
                'reason_codes' => [],
                'checked_at_utc' => '2026-10-18T10:00:00Z',
                'check_age_seconds' => 3,
                'data_used_bytes' => 1073741824,
                'data_available_bytes' => 28991029248,
                'root_available_bytes' => 10737418240,
            ], 200),
        ]);
    }

    private function createStandardOrder(Plan $plan, string $email, string $tradeNo): Order
    {
        $user = $this->makeUser($email, false, [
            'group_id' => 1,
            'plan_id' => $plan->id,
            'transfer_enable' => 10737418240,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
        ]);

        return Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);
    }

    private function adminUri(string $path): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . ltrim((string) $prefix, '/') . '/' . ltrim($path, '/');
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'group_id' => 1,
            'transfer_enable' => 30,
            'name' => 'Diagnostics Lease Plan',
            'speed_limit' => 100,
            'device_limit' => 2,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 30,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'Lease Test Node',
            'type' => Server::TYPE_VLESS,
            'host' => '198.51.100.20',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeUser(string $email, bool $isDistributor = false, array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $isDistributor,
            'distributor_name' => $isDistributor ? '测试渠道商' : null,
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ], $attributes));
    }
}
