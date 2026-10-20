<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use InvalidArgumentException;

final class DiagnosticContract
{
    public const SCHEMA_VERSION = 1;
    public const MAX_EVENT_BYTES = 4096;
    public const MAX_STACK_BYTES = 2048;
    public const MAX_STACK_FRAMES = 12;
    public const MAX_STACK_FRAME_BYTES = 192;
    public const MAX_SAFE_MESSAGE_CHARS = 256;
    public const MAX_REQUESTED_HOST_CHARS = 253;
    public const MAX_OUTBOUND_TAG_CHARS = 128;
    public const MAX_DNS_ANSWERS = 8;
    public const MAX_SPAN_LINKS = 4;
    public const MAX_UDP_TARGETS_PER_SESSION = 64;
    public const MAX_ACTIVE_LEASES_PER_PANEL = 10;
    public const LEASE_TTL_SECONDS = 900;
    public const LEASE_MAX_RTT_MS = 5000;

    public const EVENT_FAMILIES = [
        'connection',
        'resolver_operation',
        'node',
    ];

    public const EVENTS = [
        'diag.connection.open',
        'diag.admission.rejected',
        'diag.route.result',
        'diag.route.step',
        'diag.dns.result',
        'diag.dns.exchange.result',
        'diag.dns.attempt',
        'diag.dial.result',
        'diag.dial.attempt',
        'diag.relay.result',
        'diag.target.summary',
        'diag.connection.close',
        'diag.observer.health',
    ];

    public const EVENT_TO_FAMILY = [
        'diag.connection.open' => 'connection',
        'diag.admission.rejected' => 'connection',
        'diag.route.result' => 'connection',
        'diag.route.step' => 'connection',
        'diag.dns.result' => 'connection',
        'diag.dial.result' => 'connection',
        'diag.dial.attempt' => 'connection',
        'diag.relay.result' => 'connection',
        'diag.target.summary' => 'connection',
        'diag.connection.close' => 'connection',
        'diag.dns.exchange.result' => 'resolver_operation',
        'diag.dns.attempt' => 'resolver_operation',
        'diag.observer.health' => 'node',
    ];

    public const STAGES = [
        'admission',
        'route',
        'dns',
        'dial',
        'relay',
        'close',
    ];

    public const NETWORKS = [
        'tcp',
        'udp',
    ];

    public const DOMAIN_SOURCES = [
        'request',
        'sniff',
        'dns',
        'unknown',
    ];

    public const OBSERVATION_MODES = [
        'baseline',
        'detail',
    ];

    public const REASON_CODES = [
        'device_limit_exceeded',
        'route_rejected',
        'outbound_unavailable',
        'dns_timeout',
        'dns_nxdomain',
        'dns_servfail',
        'dns_transport_error',
        'connect_timeout',
        'connect_refused',
        'network_unreachable',
        'connection_reset',
        'peer_closed',
        'cancelled',
        'unclassified',
    ];

    public const TRANSPORT_STATES = [
        'pending',
        'admission_rejected',
        'route_rejected',
        'dns_failed',
        'dial_failed',
        'transport_established',
        'socket_ready',
        'response_observed',
        'closed',
    ];

    public const SUMMARY_REASONS = [
        'lru_evicted',
        'session_closed',
    ];

    public const SPAN_NAMES = [
        'connection',
        'route',
        'dns.lookup',
        'dns.exchange',
        'dial',
        'relay',
    ];

    public const SPAN_STATUSES = [
        'unset',
        'ok',
        'error',
        'cancelled',
    ];

    public const SPAN_LINK_RELATIONS = [
        'shared_dns_operation',
    ];

    public const STACK_CAPTURE_KINDS = [
        'creation_site',
        'library_provided',
        'observation_boundary',
        'unavailable',
    ];

    public const NODE_RESOLUTIONS = [
        'resolved',
        'cache_hit',
        'failed',
        'not_performed',
    ];

    public const CAPABILITIES = [
        'connection.v1',
        'dns.v1',
        'subject_lease.v1',
        'exception_stack.v1',
    ];

    public const FORBIDDEN_COMMON_FIELDS = [
        'user_id',
        'uuid',
        'token',
        'subscribe_url',
        'email',
        'trade_no',
        'order_id',
        'client_ip',
        'source_ip',
        'dest_ip',
        'resolved_ip',
        'ecs_subnet',
        'raw_url',
        'sni',
        'ech',
        'alpn',
        'stack_raw',
        'headers',
        'waiting_customers',
        'customer_ids',
    ];

    public const ALLOWED_FIELDS_BY_FAMILY = [
        'connection' => [
            'schema_version',
            'event_time',
            'event_id',
            'event_family',
            'panel_scope',
            'node_id',
            'runtime_id',
            'diagnostic_id',
            'connection_id',
            'target_id',
            'seq',
            'event',
            'stage',
            'observation_mode',
            'session_id',
            'build_revision',
            'payload_truncated',
            'trace_id',
            'span_id',
            'parent_span_id',
            'span_name',
            'span_start_time',
            'span_duration_ms',
            'span_status',
            'span_links',
            'network',
            'protocol',
            'requested_host',
            'requested_port',
            'observed_domain',
            'domain_source',
            'outbound_tag',
            'rule_id',
            'selected_target_address',
            'socket_peer',
            'has_error',
            'last_error_stage',
            'last_reason_code',
            'error_stages',
            'reason_codes',
            'transport_state',
            'summary_reason',
            'targets_evicted_total',
            'resolver_operation_id',
            'node_resolution',
            'dns_rcode',
            'dns_answers',
            'dns_answers_total',
            'dns_answers_truncated',
            'dns_cache_hit',
            'dns_query_type',
            'device_limit',
            'active_ip_count',
            'attempt_id',
            'duration_ms',
            'bytes_up',
            'bytes_down',
            'close_reason',
            'reason_code',
            'error_type',
            'timeout',
            'errno',
            'safe_message',
            'exception',
        ],
        'resolver_operation' => [
            'schema_version',
            'event_time',
            'event_id',
            'event_family',
            'panel_scope',
            'node_id',
            'runtime_id',
            'resolver_operation_id',
            'operation_seq',
            'event',
            'stage',
            'observation_mode',
            'build_revision',
            'payload_truncated',
            'trace_id',
            'span_id',
            'parent_span_id',
            'span_name',
            'span_start_time',
            'span_duration_ms',
            'span_status',
            'observed_domain',
            'dns_server',
            'dns_transport',
            'dns_query_type',
            'attempt_id',
            'dns_rcode',
            'dns_answers',
            'dns_answers_total',
            'dns_answers_truncated',
            'dns_cache_hit',
            'detail_started_mid_operation',
            'reason_code',
            'error_type',
            'timeout',
            'errno',
            'safe_message',
            'exception',
        ],
        'node' => [
            'schema_version',
            'event_time',
            'event_id',
            'event_family',
            'panel_scope',
            'node_id',
            'runtime_id',
            'runtime_seq',
            'event',
            'observation_mode',
            'build_revision',
            'payload_truncated',
            'observer_state',
            'control_epoch',
            'control_version',
            'active_leases',
            'queue_depth',
            'queue_bytes',
            'dropped_total',
            'dropped_detail',
            'dropped_baseline',
            'evicted_targets_total',
            'stage',
            'reason_code',
            'error_type',
            'safe_message',
        ],
    ];

    /**
     * Validate UUIDv4 lowercase format for panel_scope.
     */
    public static function isValidPanelScope(mixed $panelScope): bool
    {
        if (!is_string($panelScope) || $panelScope === '') {
            return false;
        }

        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $panelScope
        ) === 1;
    }

    /**
     * Validate lowercase hex string of exact length (and non-zero for trace/span IDs).
     */
    public static function isValidHexId(mixed $value, int $expectedLength, bool $requireNonZero = false): bool
    {
        if (!is_string($value) || strlen($value) !== $expectedLength) {
            return false;
        }
        if (preg_match('/\A[0-9a-f]{' . $expectedLength . '}\z/', $value) !== 1) {
            return false;
        }
        if ($requireNonZero && trim($value, '0') === '') {
            return false;
        }

        return true;
    }

    /**
     * Validate 24-hex lowercase diagnostic_id.
     */
    public static function isValidDiagnosticId(mixed $diagnosticId): bool
    {
        return self::isValidHexId($diagnosticId, 24, true);
    }

    /**
     * Compute opaque 24-hex diagnostic_id from panel_scope and subscriber_user_id.
     *
     * Formula: lower_hex(SHA256("xboard-diag:v1\0" + panel_scope + "\0" + decimal(subscriber_user_id)))[0:24]
     */
    public static function computeDiagnosticId(?string $panelScope, mixed $subscriberUserId): string
    {
        if (!is_string($panelScope) || trim($panelScope) === '') {
            throw new InvalidArgumentException('diagnostics_not_initialized');
        }
        if (!self::isValidPanelScope($panelScope)) {
            throw new InvalidArgumentException('invalid_panel_scope');
        }
        if (!is_int($subscriberUserId) || $subscriberUserId <= 0) {
            throw new InvalidArgumentException('subscription_user_missing');
        }

        $payload = "xboard-diag:v1\x00" . $panelScope . "\x00" . (string) $subscriberUserId;
        return substr(hash('sha256', $payload), 0, 24);
    }

    /**
     * Compute canonical 64-hex event_id.
     *
     * Formula: lower_hex(SHA256("{panel_scope}/{node_id}/{runtime_id}/{event_family}/{owner_id}/{sequence}"))
     */
    public static function computeEventId(
        string $panelScope,
        int $nodeId,
        string $runtimeId,
        string $eventFamily,
        string $ownerId,
        int $sequence
    ): string {
        if (!self::isValidPanelScope($panelScope)) {
            throw new InvalidArgumentException('invalid_panel_scope');
        }
        if ($nodeId <= 0) {
            throw new InvalidArgumentException('invalid_node_id');
        }
        if (!self::isValidHexId($runtimeId, 32)) {
            throw new InvalidArgumentException('invalid_runtime_id');
        }
        if (!in_array($eventFamily, self::EVENT_FAMILIES, true)) {
            throw new InvalidArgumentException('invalid_event_family');
        }
        if (!self::isValidHexId($ownerId, 32)) {
            throw new InvalidArgumentException('invalid_owner_id');
        }
        if ($sequence <= 0) {
            throw new InvalidArgumentException('invalid_sequence');
        }

        $canonical = sprintf(
            '%s/%d/%s/%s/%s/%d',
            $panelScope,
            $nodeId,
            $runtimeId,
            $eventFamily,
            $ownerId,
            $sequence
        );

        return hash('sha256', $canonical);
    }

    /**
     * Normalize domain to IDNA ASCII lowercase without trailing dots.
     * Strictly rejects URLs, paths, ports, whitespace, control characters, IP literals, and numeric TLDs.
     */
    public static function normalizeDomain(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (preg_match('/[\s\x00-\x1f\x7f]/', $raw) === 1) {
            return null;
        }

        if (
            str_contains($raw, '://')
            || str_contains($raw, '/')
            || str_contains($raw, '?')
            || str_contains($raw, '#')
            || str_contains($raw, '\\')
            || str_contains($raw, '@')
            || str_contains($raw, ':')
            || str_contains($raw, '[')
            || str_contains($raw, ']')
        ) {
            return null;
        }

        if (str_starts_with($raw, '.') || str_contains($raw, '..')) {
            return null;
        }

        $trimmed = rtrim($raw, '.');
        if ($trimmed === '' || str_contains($trimmed, '..')) {
            return null;
        }

        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || $ascii === '') {
            return null;
        }

        $normalized = strtolower(rtrim($ascii, '.'));
        if ($normalized === '' || strlen($normalized) > self::MAX_REQUESTED_HOST_CHARS) {
            return null;
        }

        $labels = explode('.', $normalized);
        if (count($labels) < 2) {
            return null;
        }

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63) {
                return null;
            }
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label) !== 1) {
                return null;
            }
            if (str_starts_with($label, 'xn--')) {
                if (strlen($label) <= 4 || !function_exists('idn_to_utf8')) {
                    return null;
                }
                $unicodeLabel = idn_to_utf8($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (!is_string($unicodeLabel) || $unicodeLabel === '' || strtolower($unicodeLabel) === $label) {
                    return null;
                }
            }
        }

        $tld = $labels[count($labels) - 1];
        if (preg_match('/\A[0-9]+\z/', $tld) === 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * Sanitize a single stack frame into "function (relative/file:line)" without host absolute paths,
     * control characters, memory addresses, or runtime arguments, truncated to MAX_STACK_FRAME_BYTES.
     *
     * @param array{function?: string, file?: string, line?: int} $frame
     */
    public static function sanitizeStackFrame(array $frame): string
    {
        $fn = self::stripControlAndAnsi((string) ($frame['function'] ?? 'unknown'));
        $file = self::stripControlAndAnsi((string) ($frame['file'] ?? 'unknown'));
        $line = (int) ($frame['line'] ?? 0);

        // Strip trailing argument dumps or hex addresses from function name if present
        $fn = preg_replace('/\([^)]*\)\z/', '', $fn) ?? $fn;
        $fn = preg_replace('/0x[0-9a-fA-F]+/', '', $fn) ?? $fn;
        $fn = trim($fn);
        if ($fn === '') {
            $fn = 'unknown';
        }

        $normalizedFile = self::normalizeStackFilePath($file);
        $formatted = $line > 0
            ? sprintf('%s (%s:%d)', $fn, $normalizedFile, $line)
            : sprintf('%s (%s)', $fn, $normalizedFile);

        return self::truncateUtf8Bytes($formatted, self::MAX_STACK_FRAME_BYTES);
    }

    /**
     * Normalize a file path in a stack frame so host absolute paths and OS usernames never leak.
     */
    public static function normalizeStackFilePath(string $file): string
    {
        $normalized = str_replace('\\', '/', trim($file));
        if ($normalized === '') {
            return 'unknown';
        }

        $markers = ['internal/', 'deps/', 'cmd/', 'pkg/', 'app/'];
        $bestPos = -1;
        foreach ($markers as $marker) {
            $pos = strrpos($normalized, $marker);
            if ($pos !== false && ($pos === 0 || $normalized[$pos - 1] === '/')) {
                if ($pos > $bestPos) {
                    $bestPos = $pos;
                }
            }
        }
        if ($bestPos >= 0) {
            return substr($normalized, $bestPos);
        }

        // Go module cache path: keep module@version/relative/path
        if (preg_match('#/([^/]+@v[0-9]+\.[0-9]+\.[0-9]+[^/]*/.+)\z#', $normalized, $matches) === 1) {
            return $matches[1];
        }

        // Go stdlib /src/ path
        $srcPos = strrpos($normalized, '/src/');
        if ($srcPos !== false) {
            return substr($normalized, $srcPos + 1);
        }

        // Strip any remaining absolute drive/root prefixes by keeping at most the last 2 path segments
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $seg): bool => $seg !== '' && $seg !== '.' && $seg !== '..' && !preg_match('/\A[A-Za-z]:\z/', $seg)));
        if ($segments === []) {
            return 'unknown';
        }
        if (count($segments) <= 2) {
            return implode('/', $segments);
        }

        return implode('/', array_slice($segments, -2));
    }

    /**
     * Format and truncate stack frames within MAX_STACK_FRAMES (12), MAX_STACK_FRAME_BYTES (192), and MAX_STACK_BYTES (2048).
     *
     * @param array<int, array{function?: string, file?: string, line?: int}|string> $frames
     * @return array{stacktrace: string, stack_truncated: bool, stack_frames_total: int}
     */
    public static function formatAndTruncateStack(array $frames): array
    {
        $totalFrames = count($frames);
        if ($totalFrames === 0) {
            return [
                'stacktrace' => '',
                'stack_truncated' => false,
                'stack_frames_total' => 0,
            ];
        }

        $truncated = false;
        $selectedFrames = $frames;
        if ($totalFrames > self::MAX_STACK_FRAMES) {
            $selectedFrames = array_slice($frames, 0, self::MAX_STACK_FRAMES);
            $truncated = true;
        }

        $lines = [];
        $currentBytes = 0;

        foreach ($selectedFrames as $frame) {
            if (is_array($frame)) {
                $lineStr = self::sanitizeStackFrame($frame);
            } else {
                $rawLine = trim((string) $frame);
                if (preg_match('/\A(.+?)\s*\((.+):(\d+)\)\z/s', $rawLine, $matches) === 1) {
                    $lineStr = self::sanitizeStackFrame([
                        'function' => $matches[1],
                        'file' => $matches[2],
                        'line' => (int) $matches[3],
                    ]);
                } else {
                    $lineStr = self::truncateUtf8Bytes(self::sanitizeMessage($rawLine), self::MAX_STACK_FRAME_BYTES);
                }
            }

            $additionBytes = ($lines === [] ? 0 : 1) + strlen($lineStr);
            if ($currentBytes + $additionBytes > self::MAX_STACK_BYTES) {
                $truncated = true;
                break;
            }
            $lines[] = $lineStr;
            $currentBytes += $additionBytes;
        }

        return [
            'stacktrace' => implode("\n", $lines),
            'stack_truncated' => $truncated,
            'stack_frames_total' => $totalFrames,
        ];
    }

    /**
     * Enforce the 4096-byte single-line JSON + newline budget on a diagnostic event.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public static function enforceEventByteBudget(array $event): array
    {
        $encodedSize = static function (array $payload): int {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return ($json === false ? 0 : strlen($json)) + 1; // +1 for trailing newline
        };

        if ($encodedSize($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        // Step 1: Trim exception.stacktrace frame by frame from the tail
        if (isset($event['exception']) && is_array($event['exception'])) {
            $stacktrace = (string) ($event['exception']['stacktrace'] ?? '');
            if ($stacktrace !== '') {
                $frames = explode("\n", $stacktrace);
                while ($frames !== [] && $encodedSize($event) > self::MAX_EVENT_BYTES) {
                    array_pop($frames);
                    $event['exception']['stacktrace'] = implode("\n", $frames);
                    $event['exception']['stack_truncated'] = true;
                }
            }
        }

        if ($encodedSize($event) <= self::MAX_EVENT_BYTES) {
            return $event;
        }

        // Step 2: Drop optional high-size fields and mark payload_truncated = true
        $optionalDropOrder = [
            'span_links',
            'dns_answers',
            'rule_id',
            'outbound_tag',
            'socket_peer',
            'selected_target_address',
            'observed_domain',
        ];
        foreach ($optionalDropOrder as $field) {
            if (array_key_exists($field, $event)) {
                unset($event[$field]);
                $event['payload_truncated'] = true;
                if ($field === 'dns_answers') {
                    $event['dns_answers_truncated'] = true;
                }
                if ($encodedSize($event) <= self::MAX_EVENT_BYTES) {
                    return $event;
                }
            }
        }

        // Step 3: Shorten message fields while preserving required schema fields
        $event['payload_truncated'] = true;
        if (isset($event['safe_message']) && is_string($event['safe_message'])) {
            $event['safe_message'] = mb_substr($event['safe_message'], 0, 64, 'UTF-8');
        }
        if (isset($event['exception']) && is_array($event['exception'])) {
            $event['exception']['stacktrace'] = '';
            $event['exception']['stack_truncated'] = true;
            if (isset($event['exception']['message']) && is_string($event['exception']['message'])) {
                $event['exception']['message'] = mb_substr($event['exception']['message'], 0, 64, 'UTF-8');
            }
        }

        return $event;
    }

    /**
     * Sanitize and redact sensitive tokens, URLs, UUIDs, Emails, IPv4/IPv6 addresses, and control chars,
     * then truncate to 256 Unicode code points without splitting multi-byte UTF-8 runes.
     */
    public static function sanitizeMessage(string $raw): string
    {
        $sanitized = function_exists('mb_scrub') ? mb_scrub($raw, 'UTF-8') : $raw;
        $sanitized = self::stripControlAndAnsi($sanitized);

        // Redact Bearer / Basic authorization values
        $sanitized = preg_replace('/(?i)\b(bearer|basic)\s+[a-z0-9._~+\/-]+=*/', '$1 [REDACTED]', $sanitized) ?? $sanitized;
        // Redact query/key-value secrets
        $sanitized = preg_replace('/(?i)\b(token|access_token|auth|password|passwd|secret|key|subscribe_url)\s*([=: ]+)\s*[^\s&"\'\],;]+/', '$1$2[REDACTED]', $sanitized) ?? $sanitized;
        // Redact raw URLs
        $sanitized = preg_replace('#https?://[^\s"\'<>]+#i', '[REDACTED_URL]', $sanitized) ?? $sanitized;
        // Redact UUIDs
        $sanitized = preg_replace('/\b[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\b/', '[REDACTED_UUID]', $sanitized) ?? $sanitized;
        // Redact Email addresses
        $sanitized = preg_replace('/\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b/', '[REDACTED_EMAIL]', $sanitized) ?? $sanitized;
        // Redact bracketed IPv6 literals with optional port
        $sanitized = preg_replace('/\[[0-9a-fA-F:]+\](?::\d{1,5})?/', '[REDACTED_IP]', $sanitized) ?? $sanitized;
        // Redact bare IPv6 literals
        $sanitized = preg_replace('/(?<![0-9a-fA-F:])(?:(?:[0-9a-fA-F]{1,4}:){2,7}[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,7}:|:(?::[0-9a-fA-F]{1,4}){1,7})(?:%[0-9A-Za-z._-]+)?(?![0-9a-fA-F:])/', '[REDACTED_IP]', $sanitized) ?? $sanitized;
        // Redact IPv4 addresses (with optional port)
        $sanitized = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b/', '[REDACTED_IP]', $sanitized) ?? $sanitized;

        $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized) ?? $sanitized);

        if (mb_strlen($sanitized, 'UTF-8') > self::MAX_SAFE_MESSAGE_CHARS) {
            $sanitized = mb_substr($sanitized, 0, self::MAX_SAFE_MESSAGE_CHARS, 'UTF-8');
        }

        return $sanitized;
    }

    /**
     * Validate a diagnostic event payload against the shared contract.
     *
     * @param array<string, mixed> $event
     * @return array{valid: bool, errors: array<int, string>}
     */
    public static function validateEvent(array $event): array
    {
        $errors = [];

        $encoded = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) + 1 > self::MAX_EVENT_BYTES) {
            $errors[] = 'exceeds_max_event_bytes';
        }

        if (($event['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'invalid_schema_version';
        }

        $family = $event['event_family'] ?? null;
        if (!is_string($family) || !in_array($family, self::EVENT_FAMILIES, true)) {
            $errors[] = 'invalid_event_family';
            return ['valid' => false, 'errors' => $errors];
        }

        $eventName = $event['event'] ?? null;
        if (!is_string($eventName) || !in_array($eventName, self::EVENTS, true)) {
            $errors[] = 'invalid_event_name';
        } elseif (self::EVENT_TO_FAMILY[$eventName] !== $family) {
            $errors[] = 'event_family_mismatch';
        }

        $eventTime = $event['event_time'] ?? null;
        if (!is_string($eventTime) || self::parseRfc3339Millis($eventTime) === null) {
            $errors[] = 'invalid_event_time';
        }

        // Check forbidden fields first
        foreach (self::FORBIDDEN_COMMON_FIELDS as $forbidden) {
            if (array_key_exists($forbidden, $event)) {
                $errors[] = "forbidden_field:{$forbidden}";
            }
        }

        $allowedForFamily = self::ALLOWED_FIELDS_BY_FAMILY[$family];
        foreach (array_keys($event) as $fieldKey) {
            if (!in_array($fieldKey, $allowedForFamily, true)) {
                $errors[] = "unknown_field_in_{$family}:{$fieldKey}";
            }
        }

        $panelScope = $event['panel_scope'] ?? null;
        if (!self::isValidPanelScope($panelScope)) {
            $errors[] = 'invalid_panel_scope';
        }

        $nodeId = $event['node_id'] ?? null;
        if (!is_int($nodeId) || $nodeId <= 0) {
            $errors[] = 'invalid_node_id';
        }

        $runtimeId = $event['runtime_id'] ?? null;
        if (!self::isValidHexId($runtimeId, 32)) {
            $errors[] = 'invalid_runtime_id';
        }

        if ($family === 'connection') {
            $diagId = $event['diagnostic_id'] ?? null;
            if (!self::isValidDiagnosticId($diagId)) {
                $errors[] = 'invalid_diagnostic_id';
            }
            $connId = $event['connection_id'] ?? null;
            if (!self::isValidHexId($connId, 32, true)) {
                $errors[] = 'invalid_connection_id';
            }
            $seq = $event['seq'] ?? null;
            if (!is_int($seq) || $seq <= 0) {
                $errors[] = 'invalid_seq';
            }
            if (($event['trace_id'] ?? null) !== $connId) {
                $errors[] = 'connection_trace_id_must_equal_connection_id';
            }
            if (!self::isValidHexId($event['span_id'] ?? null, 16, true)) {
                $errors[] = 'invalid_span_id';
            }
            if (isset($event['parent_span_id']) && $event['parent_span_id'] !== '' && !self::isValidHexId($event['parent_span_id'], 16, true)) {
                $errors[] = 'invalid_parent_span_id';
            }
            if (!in_array($event['span_name'] ?? null, self::SPAN_NAMES, true)) {
                $errors[] = 'invalid_span_name';
            }
            if (!is_string($event['span_start_time'] ?? null) || self::parseRfc3339Millis($event['span_start_time']) === null) {
                $errors[] = 'invalid_span_start_time';
            }
            if (!is_int($event['span_duration_ms'] ?? null) || $event['span_duration_ms'] < 0) {
                $errors[] = 'invalid_span_duration_ms';
            }
            if (!in_array($event['span_status'] ?? null, self::SPAN_STATUSES, true)) {
                $errors[] = 'invalid_span_status';
            }
            if (!in_array($event['observation_mode'] ?? null, self::OBSERVATION_MODES, true)) {
                $errors[] = 'invalid_observation_mode';
            }
            if (!in_array($event['stage'] ?? null, self::STAGES, true)) {
                $errors[] = 'invalid_stage';
            }
            if (!in_array($event['network'] ?? null, self::NETWORKS, true)) {
                $errors[] = 'invalid_network';
            }
            if (!in_array($event['transport_state'] ?? null, self::TRANSPORT_STATES, true)) {
                $errors[] = 'invalid_transport_state';
            }
            if (!is_bool($event['has_error'] ?? null)) {
                $errors[] = 'invalid_has_error';
            }
            if (!isset($event['error_stages']) || !is_array($event['error_stages'])) {
                $errors[] = 'invalid_error_stages';
            } else {
                foreach ($event['error_stages'] as $es) {
                    if (!is_string($es) || !in_array($es, self::STAGES, true)) {
                        $errors[] = 'invalid_error_stage_item';
                    }
                }
            }
            if (!isset($event['reason_codes']) || !is_array($event['reason_codes'])) {
                $errors[] = 'invalid_reason_codes';
            } else {
                foreach ($event['reason_codes'] as $rc) {
                    if (!is_string($rc) || !in_array($rc, self::REASON_CODES, true)) {
                        $errors[] = 'invalid_reason_code_item';
                    }
                }
            }
            if ($eventName === 'diag.target.summary') {
                if (!in_array($event['summary_reason'] ?? null, self::SUMMARY_REASONS, true)) {
                    $errors[] = 'invalid_summary_reason';
                }
            } elseif (isset($event['summary_reason']) && !in_array($event['summary_reason'], self::SUMMARY_REASONS, true)) {
                $errors[] = 'invalid_summary_reason';
            }
            if (isset($event['span_links'])) {
                if (!is_array($event['span_links']) || count($event['span_links']) > self::MAX_SPAN_LINKS) {
                    $errors[] = 'invalid_span_links';
                } else {
                    foreach ($event['span_links'] as $link) {
                        if (!is_array($link)) {
                            $errors[] = 'invalid_span_link_item';
                            continue;
                        }
                        foreach (array_keys($link) as $linkKey) {
                            if (!in_array($linkKey, ['trace_id', 'span_id', 'relation'], true)) {
                                $errors[] = "unknown_span_link_field:{$linkKey}";
                            }
                        }
                        if (
                            !self::isValidHexId($link['trace_id'] ?? null, 32, true)
                            || !self::isValidHexId($link['span_id'] ?? null, 16, true)
                            || !in_array($link['relation'] ?? null, self::SPAN_LINK_RELATIONS, true)
                        ) {
                            $errors[] = 'invalid_span_link_item';
                        }
                    }
                }
            }
            if (
                self::isValidPanelScope($panelScope)
                && is_int($nodeId) && $nodeId > 0
                && self::isValidHexId($runtimeId, 32)
                && self::isValidHexId($connId, 32, true)
                && is_int($seq) && $seq > 0
            ) {
                $expectedEventId = self::computeEventId($panelScope, $nodeId, $runtimeId, 'connection', $connId, $seq);
                if (($event['event_id'] ?? null) !== $expectedEventId) {
                    $errors[] = 'event_id_mismatch';
                }
            }
        } elseif ($family === 'resolver_operation') {
            foreach (['diagnostic_id', 'connection_id', 'session_id'] as $forbidden) {
                if (array_key_exists($forbidden, $event)) {
                    $errors[] = "forbidden_field_in_resolver_operation:{$forbidden}";
                }
            }
            $opId = $event['resolver_operation_id'] ?? null;
            if (!self::isValidHexId($opId, 32, true)) {
                $errors[] = 'invalid_resolver_operation_id';
            }
            $opSeq = $event['operation_seq'] ?? null;
            if (!is_int($opSeq) || $opSeq <= 0) {
                $errors[] = 'invalid_operation_seq';
            }
            if (($event['trace_id'] ?? null) !== $opId) {
                $errors[] = 'resolver_trace_id_must_equal_resolver_operation_id';
            }
            if (!self::isValidHexId($event['span_id'] ?? null, 16, true)) {
                $errors[] = 'invalid_span_id';
            }
            if (isset($event['parent_span_id']) && $event['parent_span_id'] !== '' && !self::isValidHexId($event['parent_span_id'], 16, true)) {
                $errors[] = 'invalid_parent_span_id';
            }
            if (!in_array($event['span_name'] ?? null, self::SPAN_NAMES, true)) {
                $errors[] = 'invalid_span_name';
            }
            if (!is_string($event['span_start_time'] ?? null) || self::parseRfc3339Millis($event['span_start_time']) === null) {
                $errors[] = 'invalid_span_start_time';
            }
            if (!is_int($event['span_duration_ms'] ?? null) || $event['span_duration_ms'] < 0) {
                $errors[] = 'invalid_span_duration_ms';
            }
            if (!in_array($event['span_status'] ?? null, self::SPAN_STATUSES, true)) {
                $errors[] = 'invalid_span_status';
            }
            if (!in_array($event['observation_mode'] ?? null, self::OBSERVATION_MODES, true)) {
                $errors[] = 'invalid_observation_mode';
            }
            if (!in_array($event['stage'] ?? null, self::STAGES, true)) {
                $errors[] = 'invalid_stage';
            }
            $domain = $event['observed_domain'] ?? null;
            if (!is_string($domain) || self::normalizeDomain($domain) !== $domain) {
                $errors[] = 'invalid_observed_domain';
            }
            if (
                self::isValidPanelScope($panelScope)
                && is_int($nodeId) && $nodeId > 0
                && self::isValidHexId($runtimeId, 32)
                && self::isValidHexId($opId, 32, true)
                && is_int($opSeq) && $opSeq > 0
            ) {
                $expectedEventId = self::computeEventId($panelScope, $nodeId, $runtimeId, 'resolver_operation', $opId, $opSeq);
                if (($event['event_id'] ?? null) !== $expectedEventId) {
                    $errors[] = 'event_id_mismatch';
                }
            }
        } elseif ($family === 'node') {
            foreach (['diagnostic_id', 'connection_id', 'session_id', 'trace_id', 'span_id'] as $forbidden) {
                if (array_key_exists($forbidden, $event)) {
                    $errors[] = "forbidden_field_in_node:{$forbidden}";
                }
            }
            $runtimeSeq = $event['runtime_seq'] ?? null;
            if (!is_int($runtimeSeq) || $runtimeSeq <= 0) {
                $errors[] = 'invalid_runtime_seq';
            }
            if (
                self::isValidPanelScope($panelScope)
                && is_int($nodeId) && $nodeId > 0
                && self::isValidHexId($runtimeId, 32)
                && is_int($runtimeSeq) && $runtimeSeq > 0
            ) {
                $expectedEventId = self::computeEventId($panelScope, $nodeId, $runtimeId, 'node', $runtimeId, $runtimeSeq);
                if (($event['event_id'] ?? null) !== $expectedEventId) {
                    $errors[] = 'event_id_mismatch';
                }
            }
        }

        if (isset($event['dns_answers'])) {
            if (!is_array($event['dns_answers']) || count($event['dns_answers']) > self::MAX_DNS_ANSWERS) {
                $errors[] = 'invalid_dns_answers';
            } else {
                foreach ($event['dns_answers'] as $ans) {
                    if (!is_string($ans) || $ans === '') {
                        $errors[] = 'invalid_dns_answer_item';
                    }
                }
            }
        }

        if (isset($event['exception'])) {
            if (!is_array($event['exception'])) {
                $errors[] = 'invalid_exception';
            } else {
                $allowedExKeys = ['type', 'message', 'stacktrace', 'stack_capture_kind', 'stack_truncated', 'stack_frames_total'];
                foreach (array_keys($event['exception']) as $exKey) {
                    if (!in_array($exKey, $allowedExKeys, true)) {
                        $errors[] = "unknown_exception_field:{$exKey}";
                    }
                }
                if (!in_array($event['exception']['stack_capture_kind'] ?? null, self::STACK_CAPTURE_KINDS, true)) {
                    $errors[] = 'invalid_stack_capture_kind';
                }
                if (!is_bool($event['exception']['stack_truncated'] ?? null)) {
                    $errors[] = 'invalid_stack_truncated';
                }
                if (!is_int($event['exception']['stack_frames_total'] ?? null) || $event['exception']['stack_frames_total'] < 0) {
                    $errors[] = 'invalid_stack_frames_total';
                }
                $stack = $event['exception']['stacktrace'] ?? '';
                if (!is_string($stack) || strlen($stack) > self::MAX_STACK_BYTES) {
                    $errors[] = 'stacktrace_exceeds_budget';
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Evaluate a 15-minute diagnostic lease snapshot against current node control state,
     * computing monotonic deadlines from server_time, expires_at, and positive RTT.
     *
     * @param array<string, mixed> $snapshot
     * @param string $currentEpoch
     * @param int $currentVersion
     * @param int $rttMs
     * @param int $monotonicNowMs
     * @param array<string, int> $existingDeadlines
     * @return array{accepted: bool, reason: string, control_epoch: string, control_version: int, active_deadlines_ms: array<string, int>}
     */
    public static function evaluateLeaseSnapshot(
        array $snapshot,
        string $currentEpoch,
        int $currentVersion,
        int $rttMs,
        int $monotonicNowMs,
        array $existingDeadlines = []
    ): array {
        if ($rttMs > self::LEASE_MAX_RTT_MS) {
            return [
                'accepted' => false,
                'reason' => 'rtt_exceeds_limit',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        if (($snapshot['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return [
                'accepted' => false,
                'reason' => 'invalid_schema_version',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        if (!self::isValidPanelScope($snapshot['panel_scope'] ?? null)) {
            return [
                'accepted' => false,
                'reason' => 'invalid_panel_scope',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        if (!self::isValidHexId($snapshot['runtime_id'] ?? null, 32)) {
            return [
                'accepted' => false,
                'reason' => 'invalid_runtime_id',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        $epoch = (string) ($snapshot['control_epoch'] ?? '');
        $versionRaw = $snapshot['control_version'] ?? null;
        if (!self::isValidPanelScope($epoch) || (!is_int($versionRaw) && (!is_string($versionRaw) || preg_match('/\A\d+\z/', $versionRaw) !== 1))) {
            return [
                'accepted' => false,
                'reason' => 'invalid_epoch_or_version',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }
        $version = (int) $versionRaw;

        if ($currentEpoch !== '' && $epoch === $currentEpoch && $version < $currentVersion) {
            return [
                'accepted' => false,
                'reason' => 'stale_control_version',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        $serverTimeStr = (string) ($snapshot['server_time'] ?? '');
        $serverTimeMs = self::parseRfc3339Millis($serverTimeStr);
        if ($serverTimeMs === null) {
            return [
                'accepted' => false,
                'reason' => 'invalid_server_time',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        $sessions = $snapshot['sessions'] ?? null;
        if (!is_array($sessions) || count($sessions) > self::MAX_ACTIVE_LEASES_PER_PANEL) {
            return [
                'accepted' => false,
                'reason' => 'invalid_sessions_list',
                'control_epoch' => $currentEpoch,
                'control_version' => $currentVersion,
                'active_deadlines_ms' => $existingDeadlines,
            ];
        }

        $snapshotNodeId = isset($snapshot['node_id']) && is_int($snapshot['node_id']) ? $snapshot['node_id'] : null;
        $penaltyMs = max(0, $rttMs);
        $activeDeadlines = [];

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                return [
                    'accepted' => false,
                    'reason' => 'invalid_session_in_snapshot',
                    'control_epoch' => $currentEpoch,
                    'control_version' => $currentVersion,
                    'active_deadlines_ms' => $existingDeadlines,
                ];
            }
            $sessionId = $session['session_id'] ?? null;
            $diagId = $session['diagnostic_id'] ?? null;
            $expiresAtStr = $session['expires_at'] ?? null;
            if (
                !self::isValidHexId($sessionId, 32)
                || !self::isValidDiagnosticId($diagId)
                || !is_string($expiresAtStr)
            ) {
                return [
                    'accepted' => false,
                    'reason' => 'invalid_session_in_snapshot',
                    'control_epoch' => $currentEpoch,
                    'control_version' => $currentVersion,
                    'active_deadlines_ms' => $existingDeadlines,
                ];
            }
            if ($snapshotNodeId !== null && isset($session['node_id']) && $session['node_id'] !== $snapshotNodeId) {
                return [
                    'accepted' => false,
                    'reason' => 'session_node_id_mismatch',
                    'control_epoch' => $currentEpoch,
                    'control_version' => $currentVersion,
                    'active_deadlines_ms' => $existingDeadlines,
                ];
            }
            $expiresAtMs = self::parseRfc3339Millis($expiresAtStr);
            if ($expiresAtMs === null) {
                return [
                    'accepted' => false,
                    'reason' => 'invalid_session_in_snapshot',
                    'control_epoch' => $currentEpoch,
                    'control_version' => $currentVersion,
                    'active_deadlines_ms' => $existingDeadlines,
                ];
            }

            if (($session['enabled'] ?? false) !== true) {
                continue;
            }

            $remainingMs = ($expiresAtMs - $serverTimeMs) - $penaltyMs;
            if ($remainingMs > 0) {
                $computedDeadline = $monotonicNowMs + $remainingMs;
                if ($currentEpoch !== '' && $epoch === $currentEpoch && isset($existingDeadlines[$diagId]) && $existingDeadlines[$diagId] > 0) {
                    $computedDeadline = min($existingDeadlines[$diagId], $computedDeadline);
                }
                $activeDeadlines[$diagId] = $computedDeadline;
            }
        }

        return [
            'accepted' => true,
            'reason' => 'ok',
            'control_epoch' => $epoch,
            'control_version' => $version,
            'active_deadlines_ms' => $activeDeadlines,
        ];
    }

    /**
     * Strictly parse an ISO-8601 / RFC3339 timestamp into integer milliseconds since Unix epoch.
     */
    public static function parseRfc3339Millis(string $value): ?int
    {
        if (
            preg_match(
                '/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})\z/',
                $value,
                $matches
            ) !== 1
        ) {
            return null;
        }

        [, $year, $month, $day, $hour, $minute, $second, $fraction, $tz] = $matches;
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        if ((int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            return null;
        }

        $baseIso = sprintf('%s-%s-%sT%s:%s:%s%s', $year, $month, $day, $hour, $minute, $second, $tz);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $baseIso);
        if ($dt === false) {
            return null;
        }

        $millis = ((int) $dt->getTimestamp()) * 1000;
        if ($fraction !== '') {
            $paddedFraction = substr(str_pad($fraction, 3, '0'), 0, 3);
            $millis += (int) $paddedFraction;
        }

        return $millis;
    }

    private static function stripControlAndAnsi(string $input): string
    {
        $withoutAnsi = preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $input) ?? $input;
        return preg_replace('/[\x00-\x1f\x7f]+/', ' ', $withoutAnsi) ?? $withoutAnsi;
    }

    private static function truncateUtf8Bytes(string $input, int $maxBytes): string
    {
        if (strlen($input) <= $maxBytes) {
            return $input;
        }
        if ($maxBytes <= 3) {
            return substr('...', 0, max(0, $maxBytes));
        }

        $targetBytes = $maxBytes - 3;
        $truncated = mb_strcut($input, 0, $targetBytes, 'UTF-8');
        return $truncated . '...';
    }
}
