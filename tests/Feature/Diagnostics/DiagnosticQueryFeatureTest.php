<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Http\Middleware\InitializePlugins;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticContract;
use App\Services\Diagnostics\DiagnosticLeaseService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDiagnosticRedis;
use Tests\TestCase;

final class DiagnosticQueryFeatureTest extends TestCase
{
    use RefreshDatabase;

    private FakeDiagnosticRedis $fakeRedis;
    private string $panelScope;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Cache::flush();

        $this->fakeRedis = (new FakeDiagnosticRedis())->install();

        $this->admin = $this->makeUser('admin-query@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($this->admin);

        $this->panelScope = (string) $this->postJson($this->adminUri('diagnostics/initialize'))
            ->assertOk()
            ->json('data.panel_scope');

        config()->set('diagnostics.query_url', 'http://127.0.0.1:19428/select/logsql/query');
        config()->set('diagnostics.health_url', 'http://127.0.0.1:19428/diagnostics/health');
        config()->set('diagnostics.storage_generation', 1);
    }

    public function test_t12_t24_query_input_validation_raw_logsql_rejection_and_readonly_diagnostic_id_mode(): void
    {
        $plan = $this->makePlan();
        $order = $this->createStandardOrder($plan, 'sub-qval@example.com', '20261018QVL0001');
        $queryUri = $this->adminUri('order/diagnostics/query');

        // Reject specifying both order_id and diagnostic_id or neither
        $this->postJson($queryUri, [])->assertStatus(422)->assertJsonPath('error.code', 'invalid_query_subject');
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'diagnostic_id' => '0123456789abcdef01234567',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_query_subject');

        // Reject raw LogsQL or custom storage endpoint injection
        foreach (['query', 'logsql', 'raw_query', 'endpoint', 'storage_url'] as $forbiddenFilter) {
            $this->postJson($queryUri, [
                'order_id' => $order->id,
                'filters' => [$forbiddenFilter => '* | limit 10'],
            ])->assertStatus(422)->assertJsonPath('error.code', 'raw_logsql_forbidden');
        }

        // Reject invalid domain with control characters
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'filters' => ['domain' => "api.example.com\n_time:1h"],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_domain_filter');

        // Read-only diagnostic_id mode works for query and reports diagnostic_id_unbound_order in coverage
        $diagId = DiagnosticContract::computeDiagnosticId($this->panelScope, (int) $order->user_id);
        Http::fake([
            'http://127.0.0.1:19428/select/logsql/query' => Http::response('', 200),
            'http://127.0.0.1:19428/diagnostics/health' => Http::response($this->healthyPayload(), 200),
        ]);

        $res = $this->postJson($queryUri, [
            'diagnostic_id' => $diagId,
        ])->assertOk();

        $res->assertJsonPath('data.mode', 'diagnostic_id');
        $res->assertJsonPath('data.identity_card.order_bound', false);
        $res->assertJsonPath('data.coverage.state', 'unknown');
        $this->assertContains('diagnostic_id_unbound_order', (array) $res->json('data.coverage.reasons'));
        $res->assertJsonPath('data.empty_message', '所选范围没有收到匹配记录');
    }

    public function test_t13_t21_trace_id_pushdown_deduplication_udp_target_aggregation_cursor_pagination_and_generation_invalidation(): void
    {
        $plan = $this->makePlan();
        $node = $this->makeServer(['group_ids' => ['1']]);
        $order = $this->createStandardOrder($plan, 'sub-qagg@example.com', '20261018QAG0001');
        $diagId = DiagnosticContract::computeDiagnosticId($this->panelScope, (int) $order->user_id);

        app(DiagnosticLeaseService::class)->recordNodeCapabilities(
            $node->id,
            str_repeat('a', 32),
            [
                'supported' => true,
                'schema_version' => 1,
                'observer_state' => 'healthy',
                'queue_bytes' => 1024,
                'dropped_total' => 0,
            ]
        );

        $runtimeId = str_repeat('a', 32);
        $connTcp = str_repeat('1', 32);
        $connUdp = str_repeat('2', 32);
        $traceTcp = str_repeat('b', 32);
        $traceUdp1 = str_repeat('c', 32);
        $traceUdp2 = str_repeat('d', 32);

        $evTcpOpen = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connTcp, 1),
            'event_time' => '2026-10-18T09:55:00Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.connection.open',
            'connection_id' => $connTcp,
            'target_id' => 0,
            'seq' => 1,
            'trace_id' => $traceTcp,
            'span_id' => '1111111111111111',
            'protocol' => 'vless',
            'network' => 'tcp',
            'observed_domain' => 'api.openai.com',
            'requested_port' => 443,
            'stage' => 'inbound',
            'span_status' => 'ok',
        ];

        $evTcpDnsErr = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connTcp, 2),
            'event_time' => '2026-10-18T09:55:01Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.dns.result',
            'connection_id' => $connTcp,
            'target_id' => 0,
            'seq' => 2,
            'trace_id' => $traceTcp,
            'span_id' => '2222222222222222',
            'parent_span_id' => '1111111111111111',
            'protocol' => 'vless',
            'network' => 'tcp',
            'observed_domain' => 'api.openai.com',
            'requested_port' => 443,
            'stage' => 'dns',
            'span_status' => 'error',
            'reason_code' => 'dns_timeout',
            'has_error' => true,
        ];

        $evTcpClose = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connTcp, 3),
            'event_time' => '2026-10-18T09:55:02Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.connection.close',
            'connection_id' => $connTcp,
            'target_id' => 0,
            'seq' => 3,
            'trace_id' => $traceTcp,
            'span_id' => '3333333333333333',
            'parent_span_id' => '1111111111111111',
            'protocol' => 'vless',
            'network' => 'tcp',
            'observed_domain' => 'api.openai.com',
            'requested_port' => 443,
            'outbound_tag' => 'proxy-out',
            'stage' => 'outbound_connect',
            'span_status' => 'error',
            'reason_code' => 'tls_handshake_failed',
            'has_error' => true,
            'exception' => [
                'type' => 'tls.RecordHeaderError',
                'message' => 'tls: oversized record received',
                'stacktrace' => 'crypto/tls.(*Conn).Handshake (tls.go:100)',
            ],
        ];

        // UDP connection with two distinct targets (target_id=1 and target_id=2) PLUS a connection-level close (target_id=0)
        $evUdpTarget1 = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connUdp, 1),
            'event_time' => '2026-10-18T09:56:00Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.target.summary',
            'connection_id' => $connUdp,
            'target_id' => 1,
            'seq' => 2,
            'trace_id' => $traceUdp1,
            'span_id' => '4444444444444444',
            'protocol' => 'hysteria2',
            'network' => 'udp',
            'observed_domain' => 'turn.cloudflare.com',
            'requested_port' => 3478,
            'stage' => 'close',
            'span_status' => 'ok',
        ];

        $evUdpTarget2 = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connUdp, 2),
            'event_time' => '2026-10-18T09:56:05Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.target.summary',
            'connection_id' => $connUdp,
            'target_id' => 2,
            'seq' => 3,
            'trace_id' => $traceUdp2,
            'span_id' => '5555555555555555',
            'protocol' => 'hysteria2',
            'network' => 'udp',
            'observed_domain' => 'stun.l.google.com',
            'requested_port' => 19302,
            'stage' => 'close',
            'span_status' => 'ok',
        ];

        $evUdpConnCloseTarget0 = [
            'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connUdp, 3),
            'event_time' => '2026-10-18T09:56:10Z',
            'panel_scope' => $this->panelScope,
            'diagnostic_id' => $diagId,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'event_family' => 'connection',
            'event' => 'diag.connection.close',
            'connection_id' => $connUdp,
            'target_id' => 0,
            'seq' => 4,
            'trace_id' => $traceUdp1,
            'span_id' => '6666666666666666',
            'protocol' => 'hysteria2',
            'network' => 'udp',
            'stage' => 'close',
            'span_status' => 'ok',
        ];

        // Include duplicate evTcpClose to verify deduplication by event_id
        $ndjson = implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            [$evUdpConnCloseTarget0, $evUdpTarget2, $evUdpTarget1, $evTcpClose, $evTcpClose, $evTcpDnsErr, $evTcpOpen]
        ));

        $capturedQueries = [];
        Http::fake([
            'http://127.0.0.1:19428/select/logsql/query' => function (HttpClientRequest $request) use (&$capturedQueries, $ndjson) {
                $capturedQueries[] = $request->data();
                return Http::response($ndjson, 200);
            },
            'http://127.0.0.1:19428/diagnostics/health' => Http::response($this->healthyPayload(), 200),
        ]);

        $queryUri = $this->adminUri('order/diagnostics/query');
        $fromTs = time() - 600;
        $toTs = time() - 10;

        // 1. Verify exact trace_id pushdown into LogsQL before limit
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'filters' => [
                'trace_id' => $traceTcp,
            ],
        ])->assertOk();

        $this->assertNotEmpty($capturedQueries);
        $firstLogsQl = (string) ($capturedQueries[0]['query'] ?? '');
        $this->assertStringContainsString('trace_id:"' . $traceTcp . '"', $firstLogsQl);

        // 2. Query with limit=2 to test target aggregation (3 targets: TCP target 0, UDP target 1, UDP target 2; UDP target 0 is excluded!) and cursor pagination
        $page1 = $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'limit' => 2,
        ])->assertOk();

        $page1->assertJsonPath('data.coverage.state', 'complete');
        $page1->assertJsonCount(2, 'data.items');
        $cursor = (string) $page1->json('data.next_cursor');
        $this->assertNotSame('', $cursor);

        // Fetch page 2 using cursor (must NOT issue another VictoriaLogs HTTP query)
        $queryCountBeforePage2 = count($capturedQueries);
        $page2 = $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'limit' => 2,
            'cursor' => $cursor,
        ])->assertOk();

        $this->assertSame($queryCountBeforePage2, count($capturedQueries));
        $page2->assertJsonCount(1, 'data.items');
        $this->assertNull($page2->json('data.next_cursor'));

        $allItems = array_merge((array) $page1->json('data.items'), (array) $page2->json('data.items'));
        $this->assertCount(3, $allItems);

        // Find TCP summary item and verify cumulative error_stages & reason_codes & deduplicated event_count=3
        $tcpSummary = collect($allItems)->firstWhere('connection_id', $connTcp);
        $this->assertIsArray($tcpSummary);
        $this->assertSame(3, $tcpSummary['event_count']);
        $this->assertTrue((bool) $tcpSummary['start_observed']);
        $this->assertTrue((bool) $tcpSummary['has_exception_stack']);
        $this->assertEqualsCanonicalizing(['dns', 'outbound_connect'], $tcpSummary['error_stages']);
        $this->assertEqualsCanonicalizing(['dns_timeout', 'tls_handshake_failed'], $tcpSummary['reason_codes']);

        // Verify UDP targets 1 and 2 exist and target 0 was NOT created for UDP connection
        $udpSummaries = collect($allItems)->where('connection_id', $connUdp)->values()->all();
        $this->assertCount(2, $udpSummaries);
        $this->assertEqualsCanonicalizing([1, 2], array_column($udpSummaries, 'target_id'));

        // 3. Modifying filters while reusing cursor must fail with 422 cursor_parameter_mismatch
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'limit' => 2,
            'filters' => ['outcome' => 'error'],
            'cursor' => $cursor,
        ])->assertStatus(422)->assertJsonPath('error.code', 'cursor_parameter_mismatch');

        // 4. Tampering with cursor signature must fail with 422 invalid_cursor
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'limit' => 2,
            'cursor' => $cursor . 'tampered',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');

        // 5. Rotating storage_generation invalidates existing cursor with 409 cursor_expired
        config()->set('diagnostics.storage_generation', 2);
        $this->postJson($queryUri, [
            'order_id' => $order->id,
            'from' => $fromTs,
            'to' => $toTs,
            'limit' => 2,
            'cursor' => $cursor,
        ])->assertStatus(409)->assertJsonPath('error.code', 'cursor_expired');
    }

    public function test_t13_t21_connection_detail_batches_authorized_dns_operations_and_marks_call_chain_gaps(): void
    {
        $plan = $this->makePlan();
        $node = $this->makeServer(['group_ids' => ['1']]);
        $order = $this->createStandardOrder($plan, 'sub-detail@example.com', '20261018DTL0001');
        $diagId = DiagnosticContract::computeDiagnosticId($this->panelScope, (int) $order->user_id);

        $runtimeId = str_repeat('a', 32);
        $connectionId = str_repeat('5', 32);
        $traceId = str_repeat('e', 32);
        $dnsOp1 = str_repeat('7', 32);
        $dnsOp2 = str_repeat('8', 32);

        // Connection events starting at seq=2 (missing seq=1 / diag.connection.open -> start_in_window = false, missing parent_span_id)
        // Also span '2222222222222222' has no terminal close event -> gap_note = '父步骤未捕获' / '未观察到结束事件'
        $connEvents = [
            [
                'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connectionId, 2),
                'event_time' => '2026-10-18T09:58:01Z',
                'panel_scope' => $this->panelScope,
                'diagnostic_id' => $diagId,
                'node_id' => $node->id,
                'runtime_id' => $runtimeId,
                'event_family' => 'connection',
                'event' => 'diag.dns.result',
                'connection_id' => $connectionId,
                'target_id' => 0,
                'seq' => 2,
                'trace_id' => $traceId,
                'span_id' => '2222222222222222',
                'parent_span_id' => '1111111111111111', // missing parent span in window
                'resolver_operation_id' => $dnsOp1,
                'stage' => 'dns',
                'span_status' => 'ok',
            ],
            [
                'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'connection', $connectionId, 3),
                'event_time' => '2026-10-18T09:58:02Z',
                'panel_scope' => $this->panelScope,
                'diagnostic_id' => $diagId,
                'node_id' => $node->id,
                'runtime_id' => $runtimeId,
                'event_family' => 'connection',
                'event' => 'diag.dns.result',
                'connection_id' => $connectionId,
                'target_id' => 0,
                'seq' => 3,
                'trace_id' => $traceId,
                'span_id' => '3333333333333333',
                'resolver_operation_id' => $dnsOp2,
                'stage' => 'dns',
                'span_status' => 'error',
                'reason_code' => 'dns_nxdomain',
                'has_error' => true,
            ],
        ];

        $dnsEvents = [
            [
                'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'resolver_operation', $dnsOp1, 1),
                'event_time' => '2026-10-18T09:58:00Z',
                'panel_scope' => $this->panelScope,
                'node_id' => $node->id,
                'runtime_id' => $runtimeId,
                'event_family' => 'resolver_operation',
                'event' => 'diag.dns.exchange',
                'resolver_operation_id' => $dnsOp1,
                'seq' => 1,
                'trace_id' => str_repeat('f', 32),
                'span_id' => '7777777777777777',
                'resolver_tag' => 'dns-remote',
                'qname' => 'api.openai.com',
                'qtype' => 'AAAA',
                'dns_rcode' => 0,
                'detail_started_mid_operation' => true,
                'span_status' => 'ok',
            ],
            [
                'event_id' => DiagnosticContract::computeEventId($this->panelScope, $node->id, $runtimeId, 'resolver_operation', $dnsOp2, 1),
                'event_time' => '2026-10-18T09:58:01Z',
                'panel_scope' => $this->panelScope,
                'node_id' => $node->id,
                'runtime_id' => $runtimeId,
                'event_family' => 'resolver_operation',
                'event' => 'diag.dns.exchange',
                'resolver_operation_id' => $dnsOp2,
                'seq' => 1,
                'trace_id' => str_repeat('9', 32),
                'span_id' => '8888888888888888',
                'resolver_tag' => 'dns-local',
                'qname' => 'bad.openai.com',
                'qtype' => 'A',
                'dns_rcode' => 3,
                'span_status' => 'error',
                'reason_code' => 'dns_nxdomain',
            ],
        ];

        $logsQlCalls = [];
        Http::fake([
            'http://127.0.0.1:19428/select/logsql/query' => function (HttpClientRequest $request) use (&$logsQlCalls, $connEvents, $dnsEvents) {
                $q = (string) ($request->data()['query'] ?? '');
                $logsQlCalls[] = $q;
                if (str_contains($q, 'event_family:"resolver_operation"')) {
                    $body = implode("\n", array_map(
                        static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        $dnsEvents
                    ));
                    return Http::response($body, 200);
                }
                $body = implode("\n", array_map(
                    static fn (array $r): string => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $connEvents
                ));
                return Http::response($body, 200);
            },
            'http://127.0.0.1:19428/diagnostics/health' => Http::response($this->healthyPayload(), 200),
        ]);

        $connUri = $this->adminUri('order/diagnostics/connection');
        $res = $this->postJson($connUri, [
            'order_id' => $order->id,
            'node_id' => $node->id,
            'runtime_id' => $runtimeId,
            'connection_id' => $connectionId,
            'from' => time() - 600,
            'to' => time() - 5,
        ])->assertOk();

        // Exactly 2 LogsQL queries: 1 for connection events + 1 batch IN(...) query for both resolver_operation_ids (no N+1)
        $this->assertCount(2, $logsQlCalls);
        $this->assertStringContainsString('resolver_operation_id:in(', $logsQlCalls[1]);
        $this->assertStringContainsString($dnsOp1, $logsQlCalls[1]);
        $this->assertStringContainsString($dnsOp2, $logsQlCalls[1]);

        $res->assertJsonPath('data.start_in_window', false);
        $res->assertJsonPath('data.window_note', '开始记录不在当前窗口');
        $res->assertJsonCount(2, 'data.events');
        $this->assertArrayHasKey($dnsOp1, (array) $res->json('data.dns_operations'));
        $this->assertArrayHasKey($dnsOp2, (array) $res->json('data.dns_operations'));

        $spans = (array) $res->json('data.call_chain.spans');
        $this->assertNotEmpty($spans);
        $span2 = collect($spans)->firstWhere('span_id', '2222222222222222');
        $this->assertIsArray($span2);
        $this->assertSame('父操作记录未采集', $span2['gap_note']);
    }

    /**
     * @return array<string, mixed>
     */
    private function healthyPayload(): array
    {
        return [
            'schema_version' => 1,
            'panel_scope' => $this->panelScope,
            'storage_id' => 'diag-store-primary',
            'state' => 'ready',
            'latched' => false,
            'reason_codes' => [],
            'checked_at_utc' => '2026-10-18T10:00:00Z',
            'check_age_seconds' => 2,
            'data_used_bytes' => 1073741824,
            'data_available_bytes' => 28991029248,
            'root_available_bytes' => 10737418240,
        ];
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
            'name' => 'Diagnostics Query Plan',
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
            'name' => 'Query Test Node',
            'type' => Server::TYPE_VLESS,
            'host' => '198.51.100.30',
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
