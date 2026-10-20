<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

final class DiagnosticQueryService
{
    public const DEFAULT_WINDOW_SECONDS = 900;
    public const MAX_WINDOW_SECONDS = 259_200; // 72 hours
    public const DEFAULT_PAGE_LIMIT = 100;
    public const MAX_PAGE_LIMIT = 200;

    public const MAX_SCAN_EVENTS = 10_000;
    public const MAX_SCAN_BYTES = 16_777_216; // 16 MiB
    public const MAX_SNAPSHOT_ITEMS = 1_000;
    public const MAX_SNAPSHOT_BYTES = 2_097_152; // 2 MiB
    public const SNAPSHOT_TTL_SECONDS = 300; // 5 minutes
    public const MAX_SNAPSHOTS_PER_ADMIN = 3;

    public const MAX_DNS_OPERATIONS_PER_PAGE = 32;
    public const CONNECT_TIMEOUT_SECONDS = 1.0;
    public const REQUEST_TIMEOUT_SECONDS = 5.0;
    public const UPSTREAM_QUERY_TIMEOUT = '4s';

    public const MAX_CONCURRENT_QUERIES_PER_ADMIN = 2;
    public const MAX_QUERIES_PER_MINUTE_PER_ADMIN = 60;

    public const PREFIX_SNAPSHOT = 'diag:v1:qsnap:';
    public const PREFIX_SNAPSHOT_INDEX = 'diag:v1:qsnaps:';
    public const PREFIX_CONCURRENCY = 'diag:v1:qconc:';
    public const PREFIX_RATE = 'diag:v1:qrate:';

    public function __construct(
        private readonly OrderDiagnosticIdentityService $identityService,
        private readonly DiagnosticLeaseService $leaseService,
        private readonly DiagnosticStorageHealthClient $healthClient
    ) {
    }

    /**
     * Query aggregated connection/target summaries for an order or read-only diagnostic_id.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function queryConnections(int $adminId, array $input): array
    {
        $subject = $this->resolveQuerySubject($input);
        $cursorRaw = isset($input['cursor']) && is_string($input['cursor']) ? trim($input['cursor']) : '';
        $decodedCursor = $cursorRaw !== '' ? $this->decodeAndVerifyCursor($cursorRaw, $adminId) : null;
        $timeRange = $this->resolveTimeRange($input, $decodedCursor);
        $limit = $this->resolvePageLimit($input['limit'] ?? null);
        $filters = $this->validateAndNormalizeFilters($input['filters'] ?? [], $input);
        $storageGeneration = $this->getStorageGeneration();

        $filtersHash = $this->computeFiltersHash($timeRange, $filters);

        if ($decodedCursor !== null) {
            return $this->serveConnectionsFromCursor(
                $adminId,
                $decodedCursor,
                $subject,
                $timeRange,
                $filtersHash,
                $limit,
                $storageGeneration
            );
        }

        $this->acquireQuerySlot($adminId);
        try {
            $deadlineAt = microtime(true) + self::REQUEST_TIMEOUT_SECONDS;
            $logsQl = $this->buildConnectionQueryLogsQl(
                $subject['panel_scope'],
                $subject['diagnostic_id'],
                $timeRange['from_iso'],
                $timeRange['to_iso'],
                $filters['node_id'],
                $filters['trace_id']
            );

            $scanResult = $this->executeLogsQlStreamScan(
                $logsQl,
                $timeRange['from_iso'],
                $timeRange['to_iso'],
                self::MAX_SCAN_EVENTS,
                self::MAX_SCAN_BYTES,
                $deadlineAt
            );

            $aggregated = $this->aggregateAndFilterTargets($scanResult['events'], $filters);

            $snapshotTruncated = false;
            if (count($aggregated) > self::MAX_SNAPSHOT_ITEMS) {
                $aggregated = array_slice($aggregated, 0, self::MAX_SNAPSHOT_ITEMS);
                $snapshotTruncated = true;
            }

            [$boundedItems, $byteTruncated] = $this->enforceSnapshotByteLimit($aggregated, self::MAX_SNAPSHOT_BYTES);
            $truncated = $scanResult['scan_truncated'] || $snapshotTruncated || $byteTruncated;

            $snapshotId = bin2hex(random_bytes(16));
            $asOfIso = gmdate('Y-m-d\TH:i:s\Z');

            $coverage = $this->buildCoverage(
                $subject,
                $scanResult['scan_truncated'],
                $snapshotTruncated || $byteTruncated
            );

            $snapshotData = [
                'kind' => 'connections',
                'snapshot_id' => $snapshotId,
                'as_of' => $asOfIso,
                'from_ts' => $timeRange['from_ts'],
                'to_ts' => $timeRange['to_ts'],
                'from_iso' => $timeRange['from_iso'],
                'to_iso' => $timeRange['to_iso'],
                'diagnostic_id' => $subject['diagnostic_id'],
                'mode' => $subject['mode'],
                'order_id' => $subject['order_id'],
                'public_card' => $subject['public_card'],
                'storage_generation' => $storageGeneration,
                'scan_truncated' => $scanResult['scan_truncated'],
                'truncated' => $truncated,
                'coverage' => $coverage,
                'items' => $boundedItems,
            ];

            $this->storeAdminSnapshot($adminId, $snapshotId, $snapshotData);

            $pageItems = array_slice($boundedItems, 0, $limit);
            $nextOffset = count($pageItems);
            $nextCursor = $nextOffset < count($boundedItems)
                ? $this->encodeCursor([
                    'kind' => 'connections',
                    'admin_id' => $adminId,
                    'mode' => $subject['mode'],
                    'order_id' => $subject['order_id'],
                    'panel_scope' => $subject['panel_scope'],
                    'diagnostic_id' => $subject['diagnostic_id'],
                    'from_ts' => $timeRange['from_ts'],
                    'to_ts' => $timeRange['to_ts'],
                    'from_iso' => $timeRange['from_iso'],
                    'to_iso' => $timeRange['to_iso'],
                    'filters_hash' => $filtersHash,
                    'storage_generation' => $storageGeneration,
                    'snapshot_id' => $snapshotId,
                    'offset' => $nextOffset,
                    'limit' => $limit,
                ])
                : null;

            return $this->formatConnectionsPageResponse($snapshotData, $pageItems, $nextCursor);
        } finally {
            $this->releaseQuerySlot($adminId);
        }
    }

    /**
     * Query single connection detail, real trace/span call chain, and batch-expanded shared DNS operations.
     * Connection events and DNS expansion share the 10,000 events / 16 MiB / 5s total budget and 1,000 items / 2 MiB output budget.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function queryConnectionDetail(int $adminId, array $input): array
    {
        $subject = $this->resolveQuerySubject($input);
        $cursorRaw = isset($input['cursor']) && is_string($input['cursor']) ? trim($input['cursor']) : '';
        $decodedCursor = $cursorRaw !== '' ? $this->decodeAndVerifyCursor($cursorRaw, $adminId) : null;
        $timeRange = $this->resolveTimeRange($input, $decodedCursor);
        $storageGeneration = $this->getStorageGeneration();

        $nodeId = $this->validatePositiveInt($input['node_id'] ?? null, 'node_id');
        $runtimeId = isset($input['runtime_id']) && is_string($input['runtime_id'])
            ? strtolower(trim($input['runtime_id']))
            : '';
        $connectionId = isset($input['connection_id']) && is_string($input['connection_id'])
            ? strtolower(trim($input['connection_id']))
            : '';

        if (!DiagnosticContract::isValidHexId($runtimeId, 32)) {
            throw new DiagnosticDomainException('invalid_runtime_id', 'runtime_id 必须为 32 位小写十六进制字符串', 422);
        }
        if (!DiagnosticContract::isValidHexId($connectionId, 32)) {
            throw new DiagnosticDomainException('invalid_connection_id', 'connection_id 必须为 32 位小写十六进制字符串', 422);
        }

        $detailTargetHash = hash('sha256', implode('|', [
            (string) $nodeId,
            $runtimeId,
            $connectionId,
            $timeRange['from_iso'],
            $timeRange['to_iso'],
        ]));

        if ($decodedCursor !== null) {
            return $this->serveConnectionDetailFromCursor(
                $adminId,
                $decodedCursor,
                $subject,
                $detailTargetHash,
                $storageGeneration
            );
        }

        $this->acquireQuerySlot($adminId);
        try {
            $deadlineAt = microtime(true) + self::REQUEST_TIMEOUT_SECONDS;

            // Step 1: Fetch connection events for this customer + node + runtime + connection
            $connLogsQl = $this->buildSingleConnectionLogsQl(
                $subject['panel_scope'],
                $subject['diagnostic_id'],
                $nodeId,
                $runtimeId,
                $connectionId,
                $timeRange['from_iso'],
                $timeRange['to_iso']
            );

            $connScan = $this->executeLogsQlStreamScan(
                $connLogsQl,
                $timeRange['from_iso'],
                $timeRange['to_iso'],
                self::MAX_SCAN_EVENTS,
                self::MAX_SCAN_BYTES,
                $deadlineAt
            );

            $remainingScanEvents = max(0, self::MAX_SCAN_EVENTS - $connScan['scanned_events']);
            $remainingScanBytes = max(0, self::MAX_SCAN_BYTES - $connScan['scanned_bytes']);

            $connEvents = $this->deduplicateAndSortConnectionEvents($connScan['events'], $subject['diagnostic_id']);

            // Extract authorized resolver_operation_ids referenced by this customer's dns.result events
            $authorizedOpIds = $this->extractAuthorizedResolverOperationIds($connEvents);

            // Step 2: Batch-expand up to 32 resolver_operation_ids per page within the SHARED read budget
            $dnsOperationsById = [];
            $dnsScanTruncated = false;

            if ($authorizedOpIds !== [] && $remainingScanEvents > 0 && $remainingScanBytes > 0) {
                $opChunks = array_chunk($authorizedOpIds, self::MAX_DNS_OPERATIONS_PER_PAGE);
                foreach ($opChunks as $opBatch) {
                    if ($remainingScanEvents <= 0 || $remainingScanBytes <= 0) {
                        $dnsScanTruncated = true;
                        break;
                    }

                    $dnsLogsQl = $this->buildBatchDnsOperationsLogsQl(
                        $subject['panel_scope'],
                        $nodeId,
                        $runtimeId,
                        $opBatch,
                        $timeRange['from_iso'],
                        $timeRange['to_iso'],
                        $remainingScanEvents
                    );

                    $dnsScan = $this->executeLogsQlStreamScan(
                        $dnsLogsQl,
                        $timeRange['from_iso'],
                        $timeRange['to_iso'],
                        $remainingScanEvents,
                        $remainingScanBytes,
                        $deadlineAt
                    );

                    $remainingScanEvents = max(0, $remainingScanEvents - $dnsScan['scanned_events']);
                    $remainingScanBytes = max(0, $remainingScanBytes - $dnsScan['scanned_bytes']);
                    if ($dnsScan['scan_truncated']) {
                        $dnsScanTruncated = true;
                    }

                    foreach ($this->groupDnsOperationEvents($dnsScan['events'], $opBatch) as $opId => $opEvents) {
                        $dnsOperationsById[$opId] = $opEvents;
                    }
                }
            } elseif ($authorizedOpIds !== []) {
                $dnsScanTruncated = true;
            }

            // Enforce shared 1,000 total detail items and 2 MiB output budget across connection + DNS events
            [$boundedConnEvents, $boundedDnsOps, $outputTruncated] = $this->enforceDetailOutputBudget(
                $connEvents,
                $authorizedOpIds,
                $dnsOperationsById,
                self::MAX_SNAPSHOT_ITEMS,
                self::MAX_SNAPSHOT_BYTES
            );

            $startInWindow = false;
            foreach ($boundedConnEvents as $ev) {
                $evName = (string) ($ev['event'] ?? $ev['event_name'] ?? '');
                if ($evName === 'diag.connection.open' || (int) ($ev['seq'] ?? 0) === 1) {
                    $startInWindow = true;
                    break;
                }
            }

            $callChain = $this->buildCallChain($boundedConnEvents, $boundedDnsOps, $startInWindow);
            $scanTruncated = $connScan['scan_truncated'] || $dnsScanTruncated;
            $truncated = $scanTruncated || $outputTruncated || count($authorizedOpIds) > self::MAX_DNS_OPERATIONS_PER_PAGE;

            $snapshotId = bin2hex(random_bytes(16));
            $asOfIso = gmdate('Y-m-d\TH:i:s\Z');

            $coverage = $this->buildCoverage($subject, $scanTruncated, $outputTruncated);

            $snapshotData = [
                'kind' => 'connection_detail',
                'snapshot_id' => $snapshotId,
                'as_of' => $asOfIso,
                'from_ts' => $timeRange['from_ts'],
                'to_ts' => $timeRange['to_ts'],
                'from_iso' => $timeRange['from_iso'],
                'to_iso' => $timeRange['to_iso'],
                'diagnostic_id' => $subject['diagnostic_id'],
                'mode' => $subject['mode'],
                'order_id' => $subject['order_id'],
                'node_id' => $nodeId,
                'runtime_id' => $runtimeId,
                'connection_id' => $connectionId,
                'storage_generation' => $storageGeneration,
                'start_in_window' => $startInWindow,
                'window_note' => ($boundedConnEvents !== [] && !$startInWindow) ? '开始记录不在当前窗口' : null,
                'empty_message' => $boundedConnEvents === [] ? '所选范围没有收到匹配记录' : null,
                'scan_truncated' => $scanTruncated,
                'truncated' => $truncated,
                'coverage' => $coverage,
                'events' => $boundedConnEvents,
                'authorized_resolver_operation_ids' => $authorizedOpIds,
                'dns_operations' => $boundedDnsOps,
                'call_chain' => $callChain,
            ];

            $this->storeAdminSnapshot($adminId, $snapshotId, $snapshotData);

            return $this->formatConnectionDetailPageResponse(
                $adminId,
                $subject,
                $detailTargetHash,
                $storageGeneration,
                $snapshotData,
                0
            );
        } finally {
            $this->releaseQuerySlot($adminId);
        }
    }

    /**
     * Build safe LogsQL expression for connection summary queries with exact trace_id pushdown.
     */
    public function buildConnectionQueryLogsQl(
        string $panelScope,
        string $diagnosticId,
        string $fromIso,
        string $toIso,
        ?int $nodeId,
        ?string $traceId
    ): string {
        $clauses = [
            sprintf('_time:[%s, %s]', $this->encodeLogsQlLiteral($fromIso), $this->encodeLogsQlLiteral($toIso)),
            sprintf('panel_scope:"%s"', $this->encodeLogsQlLiteral($panelScope)),
            sprintf('diagnostic_id:"%s"', $this->encodeLogsQlLiteral($diagnosticId)),
            'event_family:"connection"',
        ];

        if ($nodeId !== null && $nodeId > 0) {
            $clauses[] = sprintf('node_id:"%d"', $nodeId);
        }

        // Exact trace_id filter MUST be pushed down before scan limit
        if ($traceId !== null && $traceId !== '') {
            $clauses[] = sprintf('trace_id:"%s"', $this->encodeLogsQlLiteral($traceId));
        }

        $limitPlusOne = self::MAX_SCAN_EVENTS + 1;
        return implode(' AND ', $clauses) . sprintf(' | sort by (_time desc) | limit %d', $limitPlusOne);
    }

    /**
     * Build safe LogsQL expression for a single connection's events.
     */
    public function buildSingleConnectionLogsQl(
        string $panelScope,
        string $diagnosticId,
        int $nodeId,
        string $runtimeId,
        string $connectionId,
        string $fromIso,
        string $toIso
    ): string {
        $clauses = [
            sprintf('_time:[%s, %s]', $this->encodeLogsQlLiteral($fromIso), $this->encodeLogsQlLiteral($toIso)),
            sprintf('panel_scope:"%s"', $this->encodeLogsQlLiteral($panelScope)),
            sprintf('diagnostic_id:"%s"', $this->encodeLogsQlLiteral($diagnosticId)),
            sprintf('node_id:"%d"', $nodeId),
            sprintf('runtime_id:"%s"', $this->encodeLogsQlLiteral($runtimeId)),
            sprintf('connection_id:"%s"', $this->encodeLogsQlLiteral($connectionId)),
            'event_family:"connection"',
        ];

        $limitPlusOne = self::MAX_SCAN_EVENTS + 1;
        return implode(' AND ', $clauses) . sprintf(' | sort by (_time asc) | limit %d', $limitPlusOne);
    }

    /**
     * Build batch LogsQL expression for authorized shared DNS resolver operations (no N+1).
     *
     * @param list<string> $operationIds
     */
    public function buildBatchDnsOperationsLogsQl(
        string $panelScope,
        int $nodeId,
        string $runtimeId,
        array $operationIds,
        string $fromIso,
        string $toIso,
        int $remainingLimit
    ): string {
        $encodedIds = array_map(
            fn (string $opId): string => '"' . $this->encodeLogsQlLiteral($opId) . '"',
            $operationIds
        );

        $clauses = [
            sprintf('_time:[%s, %s]', $this->encodeLogsQlLiteral($fromIso), $this->encodeLogsQlLiteral($toIso)),
            sprintf('panel_scope:"%s"', $this->encodeLogsQlLiteral($panelScope)),
            sprintf('node_id:"%d"', $nodeId),
            sprintf('runtime_id:"%s"', $this->encodeLogsQlLiteral($runtimeId)),
            'event_family:"resolver_operation"',
            sprintf('resolver_operation_id:in(%s)', implode(', ', $encodedIds)),
        ];

        $limitPlusOne = max(1, $remainingLimit) + 1;
        return implode(' AND ', $clauses) . sprintf(' | sort by (_time asc) | limit %d', $limitPlusOne);
    }

    /**
     * Encode a literal string for LogsQL double-quoted filter, rejecting any control characters.
     */
    public function encodeLogsQlLiteral(string $value): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new DiagnosticDomainException(
                'invalid_query_literal',
                '查询过滤值不能包含控制字符',
                422
            );
        }

        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * Resolve query subject: either `order_id` (order mode) or `diagnostic_id` (read-only ID mode), never both.
     *
     * @param array<string, mixed> $input
     * @return array{mode: string, order_bound: bool, order_id: int|null, panel_scope: string, diagnostic_id: string, public_card: array<string, mixed>, eligible_nodes: Collection<int, Server>}
     */
    private function resolveQuerySubject(array $input): array
    {
        $hasOrderId = array_key_exists('order_id', $input) && $input['order_id'] !== null && $input['order_id'] !== '';
        $hasDiagId = array_key_exists('diagnostic_id', $input) && $input['diagnostic_id'] !== null && $input['diagnostic_id'] !== '';

        if ($hasOrderId === $hasDiagId) {
            throw new DiagnosticDomainException(
                'invalid_query_subject',
                '必须且只能提供 order_id 或 diagnostic_id 其中一项',
                422
            );
        }

        if ($hasOrderId) {
            $resolved = $this->identityService->resolveOrderFromInput(['order_id' => $input['order_id']]);
            $eligibleNodes = $this->identityService->resolveEligibleNodesForSubscriber($resolved['subscriber']);
            return [
                'mode' => 'order',
                'order_bound' => true,
                'order_id' => (int) $resolved['order']->id,
                'panel_scope' => $resolved['panel_scope'],
                'diagnostic_id' => $resolved['diagnostic_id'],
                'public_card' => $resolved['public_card'],
                'eligible_nodes' => $eligibleNodes,
            ];
        }

        $panelScope = $this->identityService->requirePanelScope();
        $rawDiagId = $input['diagnostic_id'];
        if (!is_string($rawDiagId)) {
            throw new DiagnosticDomainException(
                'invalid_diagnostic_id',
                'diagnostic_id 必须为 24 位小写十六进制字符串',
                422
            );
        }

        $diagId = trim($rawDiagId);
        if (!DiagnosticContract::isValidDiagnosticId($diagId)) {
            throw new DiagnosticDomainException(
                'invalid_diagnostic_id',
                'diagnostic_id 必须为 24 位小写十六进制字符串',
                422
            );
        }

        return [
            'mode' => 'diagnostic_id',
            'order_bound' => false,
            'order_id' => null,
            'panel_scope' => $panelScope,
            'diagnostic_id' => $diagId,
            'public_card' => [
                'order_id' => null,
                'trade_no' => null,
                'order_bound' => false,
                'binding_note' => '未绑定订单（只读客户日志 ID 模式，开启详细诊断需绑定订单）',
                'diagnostic_id' => $diagId,
            ],
            'eligible_nodes' => collect(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $cursor
     * @return array{from_ts: int, to_ts: int, from_iso: string, to_iso: string}
     */
    private function resolveTimeRange(array $input, ?array $cursor = null): array
    {
        $nowTs = time();
        $hasExplicitTo = isset($input['to']) && $input['to'] !== '';
        $hasExplicitFrom = isset($input['from']) && $input['from'] !== '';

        if ($hasExplicitTo) {
            $toTs = $this->parseTimestampInput($input['to'], 'to');
        } elseif ($cursor !== null && isset($cursor['to_ts']) && is_int($cursor['to_ts'])) {
            $toTs = $cursor['to_ts'];
        } elseif ($cursor !== null && isset($cursor['to_iso']) && is_string($cursor['to_iso']) && $cursor['to_iso'] !== '') {
            $toTs = $this->parseTimestampInput($cursor['to_iso'], 'to');
        } else {
            $toTs = $nowTs;
        }

        if ($hasExplicitFrom) {
            $fromTs = $this->parseTimestampInput($input['from'], 'from');
        } elseif ($cursor !== null && isset($cursor['from_ts']) && is_int($cursor['from_ts'])) {
            $fromTs = $cursor['from_ts'];
        } elseif ($cursor !== null && isset($cursor['from_iso']) && is_string($cursor['from_iso']) && $cursor['from_iso'] !== '') {
            $fromTs = $this->parseTimestampInput($cursor['from_iso'], 'from');
        } else {
            $fromTs = $toTs - self::DEFAULT_WINDOW_SECONDS;
        }

        if ($fromTs >= $toTs) {
            throw new DiagnosticDomainException(
                'invalid_time_range',
                '起始时间 from 必须早于结束时间 to',
                422
            );
        }

        if ($toTs > $nowTs + 5) {
            throw new DiagnosticDomainException(
                'invalid_time_range',
                '结束时间 to 不能超过当前服务器时间',
                422
            );
        }

        if (($toTs - $fromTs) > self::MAX_WINDOW_SECONDS) {
            throw new DiagnosticDomainException(
                'invalid_time_range',
                '单次查询时间窗口不能超过 72 小时',
                422
            );
        }

        return [
            'from_ts' => $fromTs,
            'to_ts' => $toTs,
            'from_iso' => gmdate('Y-m-d\TH:i:s\Z', $fromTs),
            'to_iso' => gmdate('Y-m-d\TH:i:s\Z', $toTs),
        ];
    }

    private function parseTimestampInput(mixed $raw, string $field): int
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/\A\d+\z/', trim($raw)) === 1)) {
            $val = (int) $raw;
            return $val > 10_000_000_000 ? intdiv($val, 1000) : $val;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            $parsed = strtotime($trimmed);
            if ($parsed !== false && $parsed > 0) {
                return $parsed;
            }
        }

        throw new DiagnosticDomainException(
            'invalid_time_range',
            sprintf('时间字段 %s 格式无效', $field),
            422
        );
    }

    private function resolvePageLimit(mixed $rawLimit): int
    {
        if ($rawLimit === null || $rawLimit === '') {
            return self::DEFAULT_PAGE_LIMIT;
        }

        if (is_int($rawLimit)) {
            $limit = $rawLimit;
        } elseif (is_string($rawLimit) && preg_match('/\A\d+\z/', trim($rawLimit)) === 1) {
            $limit = (int) trim($rawLimit);
        } else {
            throw new DiagnosticDomainException('invalid_limit', 'limit 必须为 1 至 200 的整数', 422);
        }

        if ($limit < 1 || $limit > self::MAX_PAGE_LIMIT) {
            throw new DiagnosticDomainException('invalid_limit', 'limit 必须为 1 至 200 的整数', 422);
        }

        return $limit;
    }

    /**
     * @param mixed $rawFilters
     * @param array<string, mixed> $rootInput
     * @return array{
     *   node_id: int|null,
     *   trace_id: string|null,
     *   domain: string|null,
     *   domain_match: string,
     *   error_stages: list<string>,
     *   reason_codes: list<string>,
     *   outcome: string|null
     * }
     */
    private function validateAndNormalizeFilters(mixed $rawFilters, array $rootInput): array
    {
        if (!is_array($rawFilters)) {
            throw new DiagnosticDomainException('invalid_filters', 'filters 必须为对象', 422);
        }

        foreach (['query', 'logsql', 'raw_query', 'raw_logsql', 'endpoint', 'storage_url'] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $rawFilters) || array_key_exists($forbiddenKey, $rootInput)) {
                throw new DiagnosticDomainException(
                    'raw_logsql_forbidden',
                    '禁止直接提交原始 LogsQL 或自定义日志端点',
                    422
                );
            }
        }

        $nodeId = null;
        if (isset($rawFilters['node_id']) && $rawFilters['node_id'] !== '') {
            $nodeId = $this->validatePositiveInt($rawFilters['node_id'], 'filters.node_id');
        }

        $traceId = null;
        if (isset($rawFilters['trace_id']) && $rawFilters['trace_id'] !== '') {
            if (!is_string($rawFilters['trace_id'])) {
                throw new DiagnosticDomainException('invalid_trace_id', 'trace_id 必须为 32 位小写十六进制字符串', 422);
            }
            $trimmedTrace = trim($rawFilters['trace_id']);
            if (!DiagnosticContract::isValidHexId($trimmedTrace, 32)) {
                throw new DiagnosticDomainException('invalid_trace_id', 'trace_id 必须为 32 位小写十六进制字符串', 422);
            }
            $traceId = $trimmedTrace;
        }

        $domain = null;
        $domainMatch = 'contains';
        if (isset($rawFilters['domain_match']) && $rawFilters['domain_match'] !== '') {
            if (!is_string($rawFilters['domain_match']) || !in_array($rawFilters['domain_match'], ['exact', 'suffix', 'contains'], true)) {
                throw new DiagnosticDomainException('invalid_domain_filter', 'domain_match 仅允许 exact、suffix 或 contains', 422);
            }
            $domainMatch = $rawFilters['domain_match'];
        }

        if (isset($rawFilters['domain']) && $rawFilters['domain'] !== '') {
            if (!is_string($rawFilters['domain'])) {
                throw new DiagnosticDomainException('invalid_domain_filter', 'domain 必须为字符串', 422);
            }
            $trimmedDomain = strtolower(trim($rawFilters['domain'], " \t\n\r\0\x0B."));
            if (
                $trimmedDomain === ''
                || strlen($trimmedDomain) > 253
                || preg_match('/[\x00-\x20\x7f]/', $trimmedDomain) === 1
            ) {
                throw new DiagnosticDomainException('invalid_domain_filter', 'domain 长度不能超过 253 且不能包含空白或控制字符', 422);
            }
            $domain = $trimmedDomain;
        }

        $errorStages = [];
        $rawStages = $rawFilters['error_stages'] ?? (isset($rawFilters['error_stage']) ? [$rawFilters['error_stage']] : []);
        if (!is_array($rawStages)) {
            throw new DiagnosticDomainException('invalid_stage_filter', 'error_stages 必须为数组', 422);
        }
        foreach ($rawStages as $stage) {
            if (!is_string($stage) || !in_array($stage, DiagnosticContract::STAGES, true)) {
                throw new DiagnosticDomainException('invalid_stage_filter', '存在非法的诊断阶段过滤条件', 422);
            }
            $errorStages[] = $stage;
        }

        $reasonCodes = [];
        $rawReasons = $rawFilters['reason_codes'] ?? (isset($rawFilters['reason_code']) ? [$rawFilters['reason_code']] : []);
        if (!is_array($rawReasons)) {
            throw new DiagnosticDomainException('invalid_reason_filter', 'reason_codes 必须为数组', 422);
        }
        foreach ($rawReasons as $reason) {
            if (!is_string($reason) || !in_array($reason, DiagnosticContract::REASON_CODES, true)) {
                throw new DiagnosticDomainException('invalid_reason_filter', '存在非法的诊断原因码过滤条件', 422);
            }
            $reasonCodes[] = $reason;
        }

        $outcome = null;
        if (isset($rawFilters['outcome']) && $rawFilters['outcome'] !== '') {
            if (!is_string($rawFilters['outcome']) || !in_array($rawFilters['outcome'], DiagnosticContract::SPAN_STATUSES, true)) {
                throw new DiagnosticDomainException('invalid_outcome_filter', 'outcome 过滤值无效', 422);
            }
            $outcome = $rawFilters['outcome'];
        }

        return [
            'node_id' => $nodeId,
            'trace_id' => $traceId,
            'domain' => $domain,
            'domain_match' => $domainMatch,
            'error_stages' => array_values(array_unique($errorStages)),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'outcome' => $outcome,
        ];
    }

    /**
     * Execute a LogsQL query against VictoriaLogs `/select/logsql/query` and parse NDJSON/JSON lines within read budgets.
     *
     * @return array{events: list<array<string, mixed>>, scanned_events: int, scanned_bytes: int, scan_truncated: bool}
     */
    private function executeLogsQlStreamScan(
        string $logsQl,
        string $fromIso,
        string $toIso,
        int $maxEvents,
        int $maxBytes,
        float $deadlineAt
    ): array {
        $queryUrl = $this->resolveQueryUrl();
        if ($queryUrl === '') {
            throw new DiagnosticDomainException(
                'diagnostics_query_unavailable',
                '诊断日志查询端点尚未配置',
                503
            );
        }

        $remainingSeconds = $deadlineAt - microtime(true);
        if ($remainingSeconds <= 0.05) {
            throw new DiagnosticDomainException(
                'diagnostics_query_timeout',
                '诊断日志查询超过 5 秒总时限',
                504
            );
        }

        try {
            $response = $this->buildHttpClient()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, max(0.2, $remainingSeconds)))
                ->asForm()
                ->post($queryUrl, [
                    'query' => $logsQl,
                    'start' => $fromIso,
                    'end' => $toIso,
                    'timeout' => self::UPSTREAM_QUERY_TIMEOUT,
                ]);
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out')
                || str_contains(strtolower($e->getMessage()), 'timeout')
                || (microtime(true) >= $deadlineAt);

            if ($isTimeout) {
                throw new DiagnosticDomainException(
                    'diagnostics_query_timeout',
                    '诊断日志后端查询超时',
                    504
                );
            }

            throw new DiagnosticDomainException(
                'diagnostics_query_unavailable',
                '无法连接诊断日志查询服务',
                503
            );
        } catch (\Throwable) {
            throw new DiagnosticDomainException(
                'diagnostics_query_unavailable',
                '诊断日志查询服务请求失败',
                503
            );
        }

        if ($response->status() === 504 || $response->status() === 408) {
            throw new DiagnosticDomainException(
                'diagnostics_query_timeout',
                '诊断日志后端执行超时',
                504
            );
        }

        if (!$response->ok()) {
            throw new DiagnosticDomainException(
                'diagnostics_query_unavailable',
                '诊断日志后端返回错误状态: HTTP ' . $response->status(),
                503
            );
        }

        $rawBody = $response->body();
        return $this->parseNdjsonWithinBudget($rawBody, $maxEvents, $maxBytes);
    }

    /**
     * Parse VictoriaLogs NDJSON response within strict event count and byte budgets.
     *
     * @return array{events: list<array<string, mixed>>, scanned_events: int, scanned_bytes: int, scan_truncated: bool}
     */
    public function parseNdjsonWithinBudget(string $rawBody, int $maxEvents, int $maxBytes): array
    {
        $events = [];
        $scannedEvents = 0;
        $scannedBytes = 0;
        $scanTruncated = false;

        $trimmedBody = trim($rawBody);
        if ($trimmedBody === '') {
            return [
                'events' => [],
                'scanned_events' => 0,
                'scanned_bytes' => 0,
                'scan_truncated' => false,
            ];
        }

        $lines = preg_split('/\r?\n/', $trimmedBody) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $lineBytes = strlen($line) + 1;
            if ($scannedEvents >= $maxEvents || ($scannedBytes + $lineBytes) > $maxBytes) {
                $scanTruncated = true;
                break;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $normalized = $this->normalizeVictoriaLogsRow($decoded);
            if ($normalized === null) {
                continue;
            }

            $scannedEvents++;
            $scannedBytes += $lineBytes;
            $events[] = $normalized;
        }

        return [
            'events' => $events,
            'scanned_events' => $scannedEvents,
            'scanned_bytes' => $scannedBytes,
            'scan_truncated' => $scanTruncated,
        ];
    }

    /**
     * Normalize a VictoriaLogs row (handling flattened fields or JSON nested in `_msg`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function normalizeVictoriaLogsRow(array $row): ?array
    {
        if (isset($row['_msg']) && is_string($row['_msg']) && str_starts_with(trim($row['_msg']), '{')) {
            $nested = json_decode($row['_msg'], true);
            if (is_array($nested)) {
                foreach (['panel_scope', 'node_id', 'collector_id', '_time'] as $trustedKey) {
                    if (isset($row[$trustedKey])) {
                        $nested[$trustedKey] = $row[$trustedKey];
                    }
                }
                $row = array_merge($nested, $row);
            }
        }

        if (!isset($row['event_time']) && isset($row['_time']) && is_string($row['_time'])) {
            $row['event_time'] = $row['_time'];
        }

        foreach (['node_id', 'seq', 'target_id', 'dns_rcode', 'dns_answers_total', 'duration_ms', 'span_duration_ms', 'bytes_up', 'bytes_down'] as $intField) {
            if (isset($row[$intField]) && is_string($row[$intField]) && preg_match('/\A-?\d+\z/', $row[$intField]) === 1) {
                $row[$intField] = (int) $row[$intField];
            }
        }

        foreach (['error_stages', 'reason_codes', 'span_links', 'exception'] as $jsonField) {
            if (isset($row[$jsonField]) && is_string($row[$jsonField])) {
                $decodedArr = json_decode($row[$jsonField], true);
                if (is_array($decodedArr)) {
                    $row[$jsonField] = $decodedArr;
                }
            }
        }

        foreach ([
            'has_error',
            'payload_truncated',
            'dns_answers_truncated',
            'dns_cache_hit',
            'cache_hit',
            'timeout',
            'detail_started_mid_operation',
            'exception.stack_truncated',
        ] as $boolField) {
            if (array_key_exists($boolField, $row)) {
                $row[$boolField] = $this->toStrictBool($row[$boolField]);
            }
        }

        if (isset($row['exception']) && is_array($row['exception']) && array_key_exists('stack_truncated', $row['exception'])) {
            $row['exception']['stack_truncated'] = $this->toStrictBool($row['exception']['stack_truncated']);
        }

        $eventId = $row['event_id'] ?? null;
        if (!is_string($eventId) || !DiagnosticContract::isValidHexId($eventId, 64)) {
            return null;
        }

        return $row;
    }

    private function toStrictBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return $normalized === 'true' || $normalized === '1';
        }
        return false;
    }

    /**
     * Deduplicate connection events by `event_id`, aggregate by `(node_id, runtime_id, connection_id, target_id)`,
     * preserve highest-seq self-contained summary while accumulating `error_stages` and `reason_codes`,
     * and apply post-aggregation filters.
     *
     * @param list<array<string, mixed>> $rawEvents
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function aggregateAndFilterTargets(array $rawEvents, array $filters): array
    {
        $seenEventIds = [];
        $targets = [];
        $udpConnectionsWithNonZeroTargets = [];

        foreach ($rawEvents as $ev) {
            $targetId = (int) ($ev['target_id'] ?? 0);
            if ($targetId > 0) {
                $connKey = sprintf('%d|%s|%s', (int) ($ev['node_id'] ?? 0), (string) ($ev['runtime_id'] ?? ''), (string) ($ev['connection_id'] ?? ''));
                $udpConnectionsWithNonZeroTargets[$connKey] = true;
            }
        }

        foreach ($rawEvents as $ev) {
            $eventId = (string) ($ev['event_id'] ?? '');
            if ($eventId === '' || isset($seenEventIds[$eventId])) {
                continue;
            }
            $seenEventIds[$eventId] = true;

            $nodeId = (int) ($ev['node_id'] ?? 0);
            $runtimeId = (string) ($ev['runtime_id'] ?? '');
            $connectionId = (string) ($ev['connection_id'] ?? '');
            $targetId = (int) ($ev['target_id'] ?? 0);
            $eventName = (string) ($ev['event'] ?? $ev['event_name'] ?? '');
            $seq = (int) ($ev['seq'] ?? 0);
            $eventTime = (string) ($ev['event_time'] ?? '');

            if ($nodeId <= 0 || $runtimeId === '' || $connectionId === '') {
                continue;
            }

            // UDP connection-level close (target_id=0) is an auxiliary session close event;
            // it must not overwrite individual UDP target summaries or create a fake target 0 when target_id > 0 exists.
            $connBaseKey = sprintf('%d|%s|%s', $nodeId, $runtimeId, $connectionId);
            if ($targetId === 0 && isset($udpConnectionsWithNonZeroTargets[$connBaseKey])) {
                continue;
            }

            $targetKey = sprintf('%s|%d', $connBaseKey, $targetId);
            if (!isset($targets[$targetKey])) {
                $targets[$targetKey] = [
                    'node_id' => $nodeId,
                    'runtime_id' => $runtimeId,
                    'connection_id' => $connectionId,
                    'target_id' => $targetId,
                    'trace_id' => (string) ($ev['trace_id'] ?? ''),
                    'max_seq' => -1,
                    'first_event_time' => $eventTime,
                    'last_event_time' => $eventTime,
                    'event_count' => 0,
                    'start_observed' => false,
                    'has_exception_stack' => false,
                    'error_stages_map' => [],
                    'reason_codes_map' => [],
                    'resolver_op_ids_map' => [],
                    'latest_event' => $ev,
                ];
            }

            $targets[$targetKey]['event_count']++;

            if ($eventTime !== '' && ($targets[$targetKey]['first_event_time'] === '' || strcmp($eventTime, $targets[$targetKey]['first_event_time']) < 0)) {
                $targets[$targetKey]['first_event_time'] = $eventTime;
            }
            if ($eventTime !== '' && ($targets[$targetKey]['last_event_time'] === '' || strcmp($eventTime, $targets[$targetKey]['last_event_time']) > 0)) {
                $targets[$targetKey]['last_event_time'] = $eventTime;
            }

            if ($eventName === 'diag.connection.open' || $seq === 1) {
                $targets[$targetKey]['start_observed'] = true;
            }

            $nestedStack = is_array($ev['exception'] ?? null) ? ($ev['exception']['stacktrace'] ?? null) : null;
            $flatStack = $ev['exception.stacktrace'] ?? null;
            if ((is_string($nestedStack) && $nestedStack !== '') || (is_string($flatStack) && $flatStack !== '')) {
                $targets[$targetKey]['has_exception_stack'] = true;
            }

            $hasError = $this->toStrictBool($ev['has_error'] ?? false)
                || (($ev['span_status'] ?? '') === 'error')
                || (isset($ev['outcome']) && $ev['outcome'] !== 'ok');
            $evStage = (string) ($ev['last_error_stage'] ?? $ev['stage'] ?? '');
            $evReason = (string) ($ev['last_reason_code'] ?? $ev['reason_code'] ?? '');

            if ($hasError && $evStage !== '') {
                $targets[$targetKey]['error_stages_map'][$evStage] = true;
            }
            if ($evReason !== '' && $evReason !== 'none') {
                $targets[$targetKey]['reason_codes_map'][$evReason] = true;
            }

            if (isset($ev['error_stages']) && is_array($ev['error_stages'])) {
                foreach ($ev['error_stages'] as $st) {
                    if (is_string($st) && $st !== '') {
                        $targets[$targetKey]['error_stages_map'][$st] = true;
                    }
                }
            }

            if (isset($ev['reason_codes']) && is_array($ev['reason_codes'])) {
                foreach ($ev['reason_codes'] as $rc) {
                    if (is_string($rc) && $rc !== '' && $rc !== 'none') {
                        $targets[$targetKey]['reason_codes_map'][$rc] = true;
                    }
                }
            }

            if (isset($ev['resolver_operation_id']) && is_string($ev['resolver_operation_id']) && DiagnosticContract::isValidHexId($ev['resolver_operation_id'], 32)) {
                $targets[$targetKey]['resolver_op_ids_map'][$ev['resolver_operation_id']] = true;
            }

            if ($seq > $targets[$targetKey]['max_seq']) {
                $targets[$targetKey]['max_seq'] = $seq;
                $targets[$targetKey]['latest_event'] = $ev;
                if (isset($ev['trace_id']) && is_string($ev['trace_id']) && $ev['trace_id'] !== '') {
                    $targets[$targetKey]['trace_id'] = $ev['trace_id'];
                }
            }
        }

        $summaries = [];
        foreach ($targets as $entry) {
            $latest = $entry['latest_event'];
            /** @var list<string> $errorStages */
            $errorStages = array_keys($entry['error_stages_map']);
            /** @var list<string> $reasonCodes */
            $reasonCodes = array_keys($entry['reason_codes_map']);

            $destDomain = null;
            foreach (['observed_domain', 'requested_host', 'dest_domain'] as $domainField) {
                if (isset($latest[$domainField]) && is_string($latest[$domainField]) && $latest[$domainField] !== '') {
                    $destDomain = $latest[$domainField];
                    break;
                }
            }

            $hasErrorSummary = count($errorStages) > 0 || $this->toStrictBool($latest['has_error'] ?? false);
            $outcome = (string) ($latest['span_status'] ?? $latest['outcome'] ?? ($hasErrorSummary ? 'error' : 'ok'));
            if ($outcome === 'unset') {
                $outcome = $hasErrorSummary ? 'error' : 'ok';
            }

            if (!$this->matchesPostAggregationFilters($destDomain, $errorStages, $reasonCodes, $outcome, $filters)) {
                continue;
            }

            $fallbackReasonCode = count($reasonCodes) > 0 ? $reasonCodes[count($reasonCodes) - 1] : null;
            $summaries[] = [
                'node_id' => $entry['node_id'],
                'runtime_id' => $entry['runtime_id'],
                'connection_id' => $entry['connection_id'],
                'target_id' => $entry['target_id'],
                'trace_id' => $entry['trace_id'],
                'first_event_time' => $entry['first_event_time'],
                'last_event_time' => $entry['last_event_time'],
                'max_seq' => $entry['max_seq'],
                'event_count' => $entry['event_count'],
                'start_observed' => $entry['start_observed'],
                'protocol' => $latest['protocol'] ?? null,
                'network' => $latest['network'] ?? $latest['transport'] ?? null,
                'dest_domain' => $destDomain,
                'requested_host' => $latest['requested_host'] ?? $destDomain,
                'observed_domain' => $latest['observed_domain'] ?? $destDomain,
                'dest_port' => $latest['requested_port'] ?? $latest['dest_port'] ?? null,
                'domain_source' => $latest['domain_source'] ?? null,
                'outbound_tag' => $latest['outbound_tag'] ?? null,
                'selected_target_address' => $latest['selected_target_address'] ?? null,
                'socket_peer' => $latest['socket_peer'] ?? null,
                'stage' => $latest['stage'] ?? null,
                'transport_state' => $latest['transport_state'] ?? null,
                'outcome' => $outcome,
                'reason_code' => $latest['last_reason_code'] ?? $latest['reason_code'] ?? $fallbackReasonCode,
                'error_type' => $latest['error_type'] ?? null,
                'safe_message' => $latest['safe_message'] ?? null,
                'error_stages' => $errorStages,
                'reason_codes' => $reasonCodes,
                'has_exception_stack' => $entry['has_exception_stack'],
                'resolver_operation_ids' => array_keys($entry['resolver_op_ids_map']),
            ];
        }

        usort($summaries, static function (array $a, array $b): int {
            $timeCmp = strcmp((string) $b['last_event_time'], (string) $a['last_event_time']);
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
            if ($a['node_id'] !== $b['node_id']) {
                return $a['node_id'] <=> $b['node_id'];
            }
            $rtCmp = strcmp((string) $a['runtime_id'], (string) $b['runtime_id']);
            if ($rtCmp !== 0) {
                return $rtCmp;
            }
            $connCmp = strcmp((string) $a['connection_id'], (string) $b['connection_id']);
            if ($connCmp !== 0) {
                return $connCmp;
            }
            return $a['target_id'] <=> $b['target_id'];
        });

        return $summaries;
    }

    /**
     * @param list<string> $errorStages
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $filters
     */
    private function matchesPostAggregationFilters(
        ?string $destDomain,
        array $errorStages,
        array $reasonCodes,
        string $outcome,
        array $filters
    ): bool {
        if ($filters['domain'] !== null) {
            if ($destDomain === null || $destDomain === '') {
                return false;
            }
            $needle = (string) $filters['domain'];
            $haystack = strtolower($destDomain);
            $matched = match ($filters['domain_match']) {
                'exact' => $haystack === $needle,
                'suffix' => $haystack === $needle || str_ends_with($haystack, '.' . ltrim($needle, '.')),
                default => str_contains($haystack, $needle),
            };
            if (!$matched) {
                return false;
            }
        }

        if ($filters['error_stages'] !== []) {
            if (array_intersect($filters['error_stages'], $errorStages) === []) {
                return false;
            }
        }

        if ($filters['reason_codes'] !== []) {
            if (array_intersect($filters['reason_codes'], $reasonCodes) === []) {
                return false;
            }
        }

        if ($filters['outcome'] !== null && $outcome !== $filters['outcome']) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $rawEvents
     * @return list<array<string, mixed>>
     */
    private function deduplicateAndSortConnectionEvents(array $rawEvents, string $expectedDiagnosticId): array
    {
        $seen = [];
        $events = [];

        foreach ($rawEvents as $ev) {
            if (($ev['diagnostic_id'] ?? '') !== $expectedDiagnosticId) {
                continue;
            }
            $eventId = (string) ($ev['event_id'] ?? '');
            if ($eventId === '' || isset($seen[$eventId])) {
                continue;
            }
            $seen[$eventId] = true;
            $events[] = $ev;
        }

        usort($events, static function (array $a, array $b): int {
            $seqA = (int) ($a['seq'] ?? 0);
            $seqB = (int) ($b['seq'] ?? 0);
            if ($seqA !== $seqB) {
                return $seqA <=> $seqB;
            }
            return strcmp((string) ($a['event_time'] ?? ''), (string) ($b['event_time'] ?? ''));
        });

        return $events;
    }

    /**
     * Extract authorized `resolver_operation_id`s strictly from this customer's `diag.dns.result` events.
     *
     * @param list<array<string, mixed>> $connectionEvents
     * @return list<string>
     */
    private function extractAuthorizedResolverOperationIds(array $connectionEvents): array
    {
        $opIds = [];
        foreach ($connectionEvents as $ev) {
            $eventName = (string) ($ev['event'] ?? $ev['event_name'] ?? '');
            if ($eventName !== 'diag.dns.result') {
                continue;
            }
            $opId = $ev['resolver_operation_id'] ?? null;
            if (is_string($opId) && DiagnosticContract::isValidHexId($opId, 32)) {
                $opIds[$opId] = true;
            }
            if (isset($ev['span_links']) && is_array($ev['span_links'])) {
                foreach ($ev['span_links'] as $link) {
                    if (
                        is_array($link)
                        && in_array($link['relation'] ?? '', DiagnosticContract::SPAN_LINK_RELATIONS, true)
                        && is_string($opId)
                        && DiagnosticContract::isValidHexId($opId, 32)
                    ) {
                        $opIds[$opId] = true;
                    }
                }
            }
        }

        return array_keys($opIds);
    }

    /**
     * @param list<array<string, mixed>> $rawDnsEvents
     * @param list<string> $allowedOpIds
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupDnsOperationEvents(array $rawDnsEvents, array $allowedOpIds): array
    {
        $allowedMap = array_fill_keys($allowedOpIds, true);
        $seenEventIds = [];
        $grouped = [];

        foreach ($rawDnsEvents as $ev) {
            if (($ev['event_family'] ?? '') !== 'resolver_operation') {
                continue;
            }
            $opId = (string) ($ev['resolver_operation_id'] ?? '');
            if ($opId === '' || !isset($allowedMap[$opId])) {
                continue;
            }
            $eventId = (string) ($ev['event_id'] ?? '');
            if ($eventId === '' || isset($seenEventIds[$eventId])) {
                continue;
            }
            $seenEventIds[$eventId] = true;
            $grouped[$opId][] = $ev;
        }

        foreach ($grouped as $opId => &$list) {
            usort($list, static function (array $a, array $b): int {
                $seqA = (int) ($a['seq'] ?? 0);
                $seqB = (int) ($b['seq'] ?? 0);
                if ($seqA !== $seqB) {
                    return $seqA <=> $seqB;
                }
                return strcmp((string) ($a['event_time'] ?? ''), (string) ($b['event_time'] ?? ''));
            });
        }
        unset($list);

        return $grouped;
    }

    /**
     * Build call chain spans from real connection events and linked DNS operations without fabricating missing spans.
     *
     * @param list<array<string, mixed>> $connEvents
     * @param array<string, list<array<string, mixed>>> $dnsOperations
     * @return array<string, mixed>
     */
    private function buildCallChain(array $connEvents, array $dnsOperations, bool $startInWindow): array
    {
        $spans = [];
        $endEventNames = [
            'diag.connection.close' => true,
            'diag.admission.rejected' => true,
            'diag.route.result' => true,
            'diag.route.decision' => true,
            'diag.dns.result' => true,
            'diag.dial.result' => true,
            'diag.outbound.result' => true,
            'diag.relay.result' => true,
            'diag.target.summary' => true,
        ];

        foreach ($connEvents as $ev) {
            $spanId = (string) ($ev['span_id'] ?? '');
            if ($spanId === '') {
                continue;
            }
            $eventName = (string) ($ev['event'] ?? $ev['event_name'] ?? '');
            $parentSpanId = isset($ev['parent_span_id']) && is_string($ev['parent_span_id']) && $ev['parent_span_id'] !== ''
                ? $ev['parent_span_id']
                : null;

            if (!isset($spans[$spanId])) {
                $spans[$spanId] = [
                    'span_id' => $spanId,
                    'parent_span_id' => $parentSpanId,
                    'trace_id' => (string) ($ev['trace_id'] ?? ''),
                    'span_name' => (string) ($ev['span_name'] ?? $ev['stage'] ?? 'internal'),
                    'stage' => (string) ($ev['stage'] ?? ''),
                    'target_id' => (int) ($ev['target_id'] ?? 0),
                    'started_at' => $ev['span_start_time'] ?? $ev['started_at'] ?? $ev['event_time'] ?? null,
                    'ended_at' => null,
                    'duration_ms' => null,
                    'outcome' => (string) ($ev['span_status'] ?? $ev['outcome'] ?? 'ok'),
                    'reason_code' => $ev['reason_code'] ?? null,
                    'safe_message' => $ev['safe_message'] ?? null,
                    'end_observed' => false,
                    'missing_parent' => false,
                    'gap_note' => null,
                    'detail_started_mid_operation' => $this->toStrictBool($ev['detail_started_mid_operation'] ?? false),
                    'linked_dns_operation_id' => null,
                    'linked_dns_operation' => null,
                    'exception' => null,
                ];
            }

            $span = &$spans[$spanId];
            if ($parentSpanId !== null && $span['parent_span_id'] === null) {
                $span['parent_span_id'] = $parentSpanId;
            }

            if (isset($endEventNames[$eventName])) {
                $span['end_observed'] = true;
                $span['ended_at'] = $ev['event_time'] ?? null;
                $span['outcome'] = (string) ($ev['span_status'] ?? $ev['outcome'] ?? $span['outcome']);
                $span['reason_code'] = $ev['reason_code'] ?? $span['reason_code'];
                $span['safe_message'] = $ev['safe_message'] ?? $span['safe_message'];
                $dur = $ev['span_duration_ms'] ?? $ev['duration_ms'] ?? null;
                if ($dur !== null && is_numeric($dur)) {
                    $span['duration_ms'] = (int) $dur;
                }
                $startAt = $ev['span_start_time'] ?? $ev['started_at'] ?? null;
                if (is_string($startAt) && $startAt !== '') {
                    $span['started_at'] = $startAt;
                }
            }

            if (isset($ev['exception']) && is_array($ev['exception'])) {
                $exc = $ev['exception'];
                $span['exception'] = [
                    'type' => $exc['type'] ?? $ev['error_type'] ?? null,
                    'message' => $exc['message'] ?? $ev['safe_message'] ?? null,
                    'stacktrace' => $exc['stacktrace'] ?? null,
                    'stack_capture_kind' => $exc['stack_capture_kind'] ?? 'unavailable',
                    'stack_truncated' => $this->toStrictBool($exc['stack_truncated'] ?? false),
                    'stack_frames_total' => isset($exc['stack_frames_total']) ? (int) $exc['stack_frames_total'] : 0,
                ];
            } elseif (isset($ev['exception.stack_capture_kind']) || isset($ev['exception.stacktrace'])) {
                $span['exception'] = [
                    'type' => $ev['exception.type'] ?? $ev['error_type'] ?? null,
                    'message' => $ev['exception.message'] ?? $ev['safe_message'] ?? null,
                    'stacktrace' => $ev['exception.stacktrace'] ?? null,
                    'stack_capture_kind' => $ev['exception.stack_capture_kind'] ?? 'unavailable',
                    'stack_truncated' => $this->toStrictBool($ev['exception.stack_truncated'] ?? false),
                    'stack_frames_total' => isset($ev['exception.stack_frames_total']) ? (int) $ev['exception.stack_frames_total'] : 0,
                ];
            }

            if ($eventName === 'diag.dns.result' && isset($ev['resolver_operation_id']) && is_string($ev['resolver_operation_id'])) {
                $opId = $ev['resolver_operation_id'];
                $span['linked_dns_operation_id'] = $opId;
                $span['linked_dns_operation'] = $dnsOperations[$opId] ?? null;
            }
            unset($span);
        }

        // Detect missing parent spans without fabricating parent nodes
        foreach ($spans as &$span) {
            $parentId = $span['parent_span_id'];
            if ($parentId !== null && !isset($spans[$parentId])) {
                $span['missing_parent'] = true;
                $span['gap_note'] = '父操作记录未采集';
            } elseif (!$span['end_observed']) {
                $span['gap_note'] = '未观察到结束事件';
            }
        }
        unset($span);

        return [
            'start_in_window' => $startInWindow,
            'spans' => array_values($spans),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function enforceSnapshotByteLimit(array $items, int $maxBytes): array
    {
        $bounded = [];
        $usedBytes = 256;
        $truncated = false;

        foreach ($items as $item) {
            $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                continue;
            }
            $itemBytes = strlen($encoded) + 2;
            if (($usedBytes + $itemBytes) > $maxBytes) {
                $truncated = true;
                break;
            }
            $usedBytes += $itemBytes;
            $bounded[] = $item;
        }

        return [$bounded, $truncated];
    }

    /**
     * Enforce shared 1,000 items and 2 MiB output budget across connection events and DNS operations.
     *
     * @param list<array<string, mixed>> $connEvents
     * @param list<string> $authorizedOpIds
     * @param array<string, list<array<string, mixed>>> $dnsOperations
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<array<string, mixed>>>, 2: bool}
     */
    private function enforceDetailOutputBudget(
        array $connEvents,
        array $authorizedOpIds,
        array $dnsOperations,
        int $maxItems,
        int $maxBytes
    ): array {
        $boundedConn = [];
        $boundedDns = [];
        $totalItems = 0;
        $totalBytes = 512;
        $truncated = false;

        foreach ($connEvents as $ev) {
            $encoded = json_encode($ev, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                continue;
            }
            $bytes = strlen($encoded) + 2;
            if ($totalItems >= $maxItems || ($totalBytes + $bytes) > $maxBytes) {
                $truncated = true;
                break;
            }
            $totalItems++;
            $totalBytes += $bytes;
            $boundedConn[] = $ev;
        }

        foreach ($authorizedOpIds as $opId) {
            if (!isset($dnsOperations[$opId])) {
                continue;
            }
            $boundedDns[$opId] = [];
            foreach ($dnsOperations[$opId] as $dnsEv) {
                $encoded = json_encode($dnsEv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    continue;
                }
                $bytes = strlen($encoded) + 2;
                if ($totalItems >= $maxItems || ($totalBytes + $bytes) > $maxBytes) {
                    $truncated = true;
                    break 2;
                }
                $totalItems++;
                $totalBytes += $bytes;
                $boundedDns[$opId][] = $dnsEv;
            }
        }

        return [$boundedConn, $boundedDns, $truncated];
    }

    /**
     * Build observation coverage object.
     *
     * @param array{mode: string, order_bound: bool, eligible_nodes: Collection<int, Server>} $subject
     * @return array<string, mixed>
     */
    private function buildCoverage(array $subject, bool $scanTruncated, bool $outputTruncated): array
    {
        $reasons = [];
        $storageHealth = $this->healthClient->fetchHealth();
        $healthState = (string) ($storageHealth['state'] ?? 'unknown');
        $healthReasons = is_array($storageHealth['reason_codes'] ?? null) ? $storageHealth['reason_codes'] : [];

        if ($scanTruncated) {
            $reasons[] = 'scan_budget_truncated';
        }
        if ($outputTruncated) {
            $reasons[] = 'snapshot_budget_truncated';
        }

        if ($healthState === 'unknown') {
            $reasons[] = 'storage_health_unknown';
        } elseif ($healthState === 'blocked') {
            $reasons[] = 'storage_ingestion_blocked';
        }

        foreach ($healthReasons as $hr) {
            if (is_string($hr) && $hr !== '') {
                $reasons[] = $hr;
            }
        }

        if (!$subject['order_bound']) {
            $reasons[] = 'diagnostic_id_unbound_order';
            return [
                'state' => 'unknown',
                'reasons' => array_values(array_unique($reasons)),
                'storage_health' => $storageHealth,
                'nodes' => [],
            ];
        }

        $eligibleNodes = $subject['eligible_nodes'];
        $nodeIds = $eligibleNodes->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $capMap = $this->leaseService->getNodesCapabilityMap($nodeIds);

        $nodesCoverage = [];
        $hasUnsupported = false;
        $hasUnknownCollector = false;
        $hasDrops = false;
        $hasDelayed = in_array('collector_buffer_delayed', $reasons, true);

        foreach ($eligibleNodes as $node) {
            $nid = (int) $node->id;
            $cap = $capMap[$nid] ?? [];
            $supported = (bool) ($cap['supported'] ?? false);
            $observerState = (string) ($cap['observer_state'] ?? 'unknown');
            $droppedTotal = isset($cap['dropped_total']) && is_numeric($cap['dropped_total'])
                ? (int) $cap['dropped_total']
                : null;

            if (!$supported) {
                $hasUnsupported = true;
            }
            if ($observerState === 'unknown' || $observerState === 'degraded') {
                $hasUnknownCollector = true;
            }
            if ($droppedTotal !== null && $droppedTotal > 0) {
                $hasDrops = true;
            }

            $nodesCoverage[] = [
                'node_id' => $nid,
                'node_name' => (string) $node->name,
                'capability' => $supported ? 'supported' : 'unsupported',
                'collector' => $supported ? $observerState : 'unknown',
                'known_dropped_events' => $supported ? ($droppedTotal ?? 0) : null,
            ];
        }

        if ($hasUnsupported) {
            $reasons[] = 'node_unsupported';
        }
        if ($hasDrops) {
            $reasons[] = 'node_queue_dropped_events';
        }
        if ($hasUnknownCollector) {
            $reasons[] = 'collector_state_unknown';
        }

        $state = 'complete';
        if ($healthState === 'unknown') {
            $state = 'unknown';
        } elseif ($hasUnsupported || $hasDrops || $scanTruncated || $outputTruncated || $healthState === 'blocked' || in_array('retention_shortened', $reasons, true)) {
            $state = 'partial';
        } elseif ($hasDelayed) {
            $state = 'delayed';
        } elseif ($hasUnknownCollector) {
            $state = 'unknown';
        }

        return [
            'state' => $state,
            'reasons' => array_values(array_unique($reasons)),
            'storage_health' => $storageHealth,
            'nodes' => $nodesCoverage,
        ];
    }

    /**
     * Store bounded snapshot in Redis (max 3 per admin, 5-minute TTL).
     *
     * @param array<string, mixed> $snapshotData
     */
    private function storeAdminSnapshot(int $adminId, string $snapshotId, array $snapshotData): void
    {
        $snapKey = self::PREFIX_SNAPSHOT . $adminId . ':' . $snapshotId;
        $indexKey = self::PREFIX_SNAPSHOT_INDEX . $adminId;
        $now = time();

        try {
            $encoded = json_encode($snapshotData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            Redis::setex($snapKey, self::SNAPSHOT_TTL_SECONDS, $encoded);
            Redis::zadd($indexKey, $now, $snapshotId);
            Redis::expire($indexKey, self::SNAPSHOT_TTL_SECONDS);

            $allSnapIds = Redis::zrange($indexKey, 0, -1);
            if (is_array($allSnapIds) && count($allSnapIds) > self::MAX_SNAPSHOTS_PER_ADMIN) {
                $toEvict = array_slice($allSnapIds, 0, count($allSnapIds) - self::MAX_SNAPSHOTS_PER_ADMIN);
                foreach ($toEvict as $oldId) {
                    Redis::del(self::PREFIX_SNAPSHOT . $adminId . ':' . $oldId);
                    Redis::zrem($indexKey, $oldId);
                }
            }
        } catch (\Throwable) {
            // Non-fatal if snapshot cache write fails; initial page still returns
        }
    }

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $timeRange
     * @return array<string, mixed>
     */
    private function serveConnectionsFromCursor(
        int $adminId,
        array $cursor,
        array $subject,
        array $timeRange,
        string $filtersHash,
        int $limit,
        int $storageGeneration
    ): array {
        if (($cursor['kind'] ?? '') !== 'connections') {
            throw new DiagnosticDomainException('cursor_parameter_mismatch', '分页游标类型不匹配', 422);
        }

        if ((int) ($cursor['storage_generation'] ?? 0) !== $storageGeneration) {
            throw new DiagnosticDomainException('cursor_expired', '日志存储代次已变更，分页快照已失效，请重新查询', 409);
        }

        if (
            ($cursor['mode'] ?? '') !== $subject['mode']
            || ($cursor['order_id'] ?? null) !== $subject['order_id']
            || ($cursor['panel_scope'] ?? '') !== $subject['panel_scope']
            || ($cursor['diagnostic_id'] ?? '') !== $subject['diagnostic_id']
            || ($cursor['filters_hash'] ?? '') !== $filtersHash
        ) {
            throw new DiagnosticDomainException(
                'cursor_parameter_mismatch',
                '翻页请求不能修改查询模式、客户范围、时间窗口或过滤条件',
                422
            );
        }

        $snapshotId = (string) ($cursor['snapshot_id'] ?? '');
        $offset = max(0, (int) ($cursor['offset'] ?? 0));

        $snapRaw = Redis::get(self::PREFIX_SNAPSHOT . $adminId . ':' . $snapshotId);
        if (!is_string($snapRaw) || $snapRaw === '') {
            throw new DiagnosticDomainException('cursor_expired', '查询快照已过期，请刷新重新查询', 409);
        }

        $snapshotData = json_decode($snapRaw, true);
        if (!is_array($snapshotData) || !isset($snapshotData['items']) || !is_array($snapshotData['items'])) {
            throw new DiagnosticDomainException('cursor_expired', '查询快照已过期，请刷新重新查询', 409);
        }

        $allItems = $snapshotData['items'];
        $pageItems = array_slice($allItems, $offset, $limit);
        $nextOffset = $offset + count($pageItems);

        $nextCursor = $nextOffset < count($allItems)
            ? $this->encodeCursor([
                'kind' => 'connections',
                'admin_id' => $adminId,
                'mode' => $subject['mode'],
                'order_id' => $subject['order_id'],
                'panel_scope' => $subject['panel_scope'],
                'diagnostic_id' => $subject['diagnostic_id'],
                'from_ts' => $timeRange['from_ts'],
                'to_ts' => $timeRange['to_ts'],
                'from_iso' => $timeRange['from_iso'],
                'to_iso' => $timeRange['to_iso'],
                'filters_hash' => $filtersHash,
                'storage_generation' => $storageGeneration,
                'snapshot_id' => $snapshotId,
                'offset' => $nextOffset,
                'limit' => $limit,
            ])
            : null;

        return $this->formatConnectionsPageResponse($snapshotData, $pageItems, $nextCursor);
    }

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    private function serveConnectionDetailFromCursor(
        int $adminId,
        array $cursor,
        array $subject,
        string $detailTargetHash,
        int $storageGeneration
    ): array {
        if (($cursor['kind'] ?? '') !== 'connection_detail') {
            throw new DiagnosticDomainException('cursor_parameter_mismatch', '详情分页游标类型不匹配', 422);
        }

        if ((int) ($cursor['storage_generation'] ?? 0) !== $storageGeneration) {
            throw new DiagnosticDomainException('cursor_expired', '日志存储代次已变更，详情快照已失效，请重新查询', 409);
        }

        if (
            ($cursor['mode'] ?? '') !== $subject['mode']
            || ($cursor['order_id'] ?? null) !== $subject['order_id']
            || ($cursor['panel_scope'] ?? '') !== $subject['panel_scope']
            || ($cursor['diagnostic_id'] ?? '') !== $subject['diagnostic_id']
            || ($cursor['target_hash'] ?? '') !== $detailTargetHash
        ) {
            throw new DiagnosticDomainException(
                'cursor_parameter_mismatch',
                '详情翻页不能修改查询模式、客户范围或连接标识',
                422
            );
        }

        $snapshotId = (string) ($cursor['snapshot_id'] ?? '');
        $opOffset = max(0, (int) ($cursor['op_offset'] ?? 0));

        $snapRaw = Redis::get(self::PREFIX_SNAPSHOT . $adminId . ':' . $snapshotId);
        if (!is_string($snapRaw) || $snapRaw === '') {
            throw new DiagnosticDomainException('cursor_expired', '连接详情快照已过期，请重新查询', 409);
        }

        $snapshotData = json_decode($snapRaw, true);
        if (!is_array($snapshotData)) {
            throw new DiagnosticDomainException('cursor_expired', '连接详情快照已过期，请重新查询', 409);
        }

        return $this->formatConnectionDetailPageResponse(
            $adminId,
            $subject,
            $detailTargetHash,
            $storageGeneration,
            $snapshotData,
            $opOffset
        );
    }

    /**
     * @param array<string, mixed> $snapshotData
     * @param list<array<string, mixed>> $pageItems
     * @return array<string, mixed>
     */
    private function formatConnectionsPageResponse(array $snapshotData, array $pageItems, ?string $nextCursor): array
    {
        $scanTruncated = (bool) ($snapshotData['scan_truncated'] ?? false);
        $emptyMessage = null;
        if ($pageItems === []) {
            $emptyMessage = $scanTruncated
                ? '读取预算已截断，建议缩小查询时间窗口或指定精确条件重试'
                : '所选范围没有收到匹配记录';
        }

        return [
            'mode' => $snapshotData['mode'],
            'order_id' => $snapshotData['order_id'],
            'diagnostic_id' => $snapshotData['diagnostic_id'],
            'identity_card' => $snapshotData['public_card'],
            'snapshot_id' => $snapshotData['snapshot_id'],
            'as_of' => $snapshotData['as_of'],
            'from' => $snapshotData['from_iso'] ?? null,
            'to' => $snapshotData['to_iso'] ?? null,
            'storage_generation' => $snapshotData['storage_generation'],
            'items' => $pageItems,
            'next_cursor' => $nextCursor,
            'scan_truncated' => $scanTruncated,
            'truncated' => (bool) ($snapshotData['truncated'] ?? false),
            'empty_message' => $emptyMessage,
            'coverage' => $snapshotData['coverage'],
        ];
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $snapshotData
     * @return array<string, mixed>
     */
    private function formatConnectionDetailPageResponse(
        int $adminId,
        array $subject,
        string $detailTargetHash,
        int $storageGeneration,
        array $snapshotData,
        int $opOffset
    ): array {
        $allOpIds = is_array($snapshotData['authorized_resolver_operation_ids'] ?? null)
            ? $snapshotData['authorized_resolver_operation_ids']
            : [];
        $allDnsOps = is_array($snapshotData['dns_operations'] ?? null)
            ? $snapshotData['dns_operations']
            : [];

        $pageOpIds = array_slice($allOpIds, $opOffset, self::MAX_DNS_OPERATIONS_PER_PAGE);
        $pageDnsOps = [];
        foreach ($pageOpIds as $opId) {
            if (isset($allDnsOps[$opId])) {
                $pageDnsOps[$opId] = $allDnsOps[$opId];
            }
        }

        $nextOpOffset = $opOffset + count($pageOpIds);
        $nextCursor = $nextOpOffset < count($allOpIds)
            ? $this->encodeCursor([
                'kind' => 'connection_detail',
                'admin_id' => $adminId,
                'mode' => $subject['mode'],
                'order_id' => $subject['order_id'],
                'panel_scope' => $subject['panel_scope'],
                'diagnostic_id' => $subject['diagnostic_id'],
                'from_ts' => $snapshotData['from_ts'] ?? null,
                'to_ts' => $snapshotData['to_ts'] ?? null,
                'from_iso' => $snapshotData['from_iso'] ?? null,
                'to_iso' => $snapshotData['to_iso'] ?? null,
                'target_hash' => $detailTargetHash,
                'storage_generation' => $storageGeneration,
                'snapshot_id' => $snapshotData['snapshot_id'],
                'op_offset' => $nextOpOffset,
            ])
            : null;

        return [
            'mode' => $snapshotData['mode'],
            'order_id' => $snapshotData['order_id'],
            'diagnostic_id' => $snapshotData['diagnostic_id'],
            'node_id' => $snapshotData['node_id'],
            'runtime_id' => $snapshotData['runtime_id'],
            'connection_id' => $snapshotData['connection_id'],
            'snapshot_id' => $snapshotData['snapshot_id'],
            'as_of' => $snapshotData['as_of'],
            'from' => $snapshotData['from_iso'] ?? null,
            'to' => $snapshotData['to_iso'] ?? null,
            'storage_generation' => $storageGeneration,
            'start_in_window' => $snapshotData['start_in_window'],
            'window_note' => $snapshotData['window_note'],
            'empty_message' => $snapshotData['empty_message'],
            'events' => $snapshotData['events'],
            'dns_operations' => $pageDnsOps,
            'call_chain' => $snapshotData['call_chain'],
            'next_cursor' => $nextCursor,
            'scan_truncated' => (bool) ($snapshotData['scan_truncated'] ?? false),
            'truncated' => (bool) ($snapshotData['truncated'] ?? false) || $nextCursor !== null,
            'coverage' => $snapshotData['coverage'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function encodeCursor(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, $this->cursorSigningKey());
        return $b64 . '.' . $sig;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAndVerifyCursor(string $cursor, int $adminId): array
    {
        $parts = explode('.', $cursor, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new DiagnosticDomainException('invalid_cursor', '分页游标格式无效', 422);
        }

        [$b64, $sig] = $parts;
        $expectedSig = hash_hmac('sha256', $b64, $this->cursorSigningKey());
        if (!hash_equals($expectedSig, $sig)) {
            throw new DiagnosticDomainException('invalid_cursor', '分页游标签名校验失败', 422);
        }

        $padded = strtr($b64, '-_', '+/');
        $rem = strlen($padded) % 4;
        if ($rem > 0) {
            $padded .= str_repeat('=', 4 - $rem);
        }

        $json = base64_decode($padded, true);
        $decoded = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            throw new DiagnosticDomainException('invalid_cursor', '分页游标内容损坏', 422);
        }

        if ((int) ($decoded['admin_id'] ?? 0) !== $adminId) {
            throw new DiagnosticDomainException('invalid_cursor', '分页游标归属管理员不匹配', 403);
        }

        return $decoded;
    }

    private function cursorSigningKey(): string
    {
        return (string) config('app.key', 'xboard-diagnostics-cursor-key');
    }

    /**
     * @param array<string, mixed> $timeRange
     * @param array<string, mixed> $filters
     */
    private function computeFiltersHash(array $timeRange, array $filters): string
    {
        return hash('sha256', json_encode([
            'from_iso' => $timeRange['from_iso'],
            'to_iso' => $timeRange['to_iso'],
            'filters' => $filters,
        ], JSON_THROW_ON_ERROR));
    }

    private function validatePositiveInt(mixed $raw, string $fieldName): int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/\A[1-9]\d*\z/', trim($raw)) === 1) {
            return (int) trim($raw);
        }

        throw new DiagnosticDomainException(
            'invalid_parameter',
            sprintf('%s 必须为正整数', $fieldName),
            422
        );
    }

    private function acquireQuerySlot(int $adminId): void
    {
        $minuteBucket = intdiv(time(), 60);
        $rateKey = self::PREFIX_RATE . $adminId . ':' . $minuteBucket;
        $concKey = self::PREFIX_CONCURRENCY . $adminId;

        try {
            $rateCount = (int) Redis::incr($rateKey);
            if ($rateCount === 1) {
                Redis::expire($rateKey, 70);
            }
            if ($rateCount > self::MAX_QUERIES_PER_MINUTE_PER_ADMIN) {
                throw new DiagnosticDomainException(
                    'diagnostics_query_rate_limited',
                    '诊断查询请求过于频繁（每分钟最多 60 次）',
                    429
                );
            }

            $concCount = (int) Redis::incr($concKey);
            if ($concCount === 1) {
                Redis::expire($concKey, 15);
            }
            if ($concCount > self::MAX_CONCURRENT_QUERIES_PER_ADMIN) {
                Redis::decr($concKey);
                throw new DiagnosticDomainException(
                    'diagnostics_query_rate_limited',
                    '同一管理员并发诊断查询数超过上限（最多 2 个并发查询）',
                    429
                );
            }
        } catch (DiagnosticDomainException $e) {
            throw $e;
        } catch (\Throwable) {
            // Allow query execution if rate-limit store has transient error
        }
    }

    private function releaseQuerySlot(int $adminId): void
    {
        $concKey = self::PREFIX_CONCURRENCY . $adminId;
        try {
            $rem = (int) Redis::decr($concKey);
            if ($rem <= 0) {
                Redis::del($concKey);
            }
        } catch (\Throwable) {
            // Ignore cleanup error
        }
    }

    private function getStorageGeneration(): int
    {
        $gen = config('diagnostics.storage_generation');
        if ($gen === null || $gen === '') {
            $gen = admin_setting('diagnostics_storage_generation', 1);
        }

        return max(1, (int) $gen);
    }

    private function resolveQueryUrl(): string
    {
        $configured = config('diagnostics.query_url');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = admin_setting('diagnostics_query_url', '');
        }
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $baseUrl = config('diagnostics.base_url');
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            $baseUrl = admin_setting('diagnostics_base_url', '');
        }
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return rtrim(trim($baseUrl), '/') . '/select/logsql/query';
        }

        return '';
    }

    private function buildHttpClient(): PendingRequest
    {
        $client = Http::withHeaders([
            'X-Diagnostics-Client' => 'xboard-panel-readonly',
        ]);

        $token = config('diagnostics.read_token');
        if (!is_string($token) || trim($token) === '') {
            $token = admin_setting('diagnostics_read_token', '');
        }
        if (is_string($token) && trim($token) !== '') {
            return $client->withToken(trim($token));
        }

        $username = config('diagnostics.read_username');
        if (!is_string($username) || trim($username) === '') {
            $username = admin_setting('diagnostics_read_username', '');
        }
        $password = config('diagnostics.read_password');
        if (!is_string($password) || trim($password) === '') {
            $password = admin_setting('diagnostics_read_password', '');
        }

        if (is_string($username) && trim($username) !== '' && is_string($password) && $password !== '') {
            return $client->withBasicAuth(trim($username), $password);
        }

        return $client;
    }
}
