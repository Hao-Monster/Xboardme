<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class DiagnosticStorageHealthClient
{
    public const MAX_CHECK_AGE_SECONDS = 60;
    public const TIMEOUT_SECONDS = 1.0;
    public const CONNECT_TIMEOUT_SECONDS = 0.8;

    /**
     * Strictly verify storage readiness before creating a new 15-minute diagnostic lease.
     * Never uses cached ready state; enforces a maximum 1-second timeout.
     *
     * @return array<string, mixed>
     */
    public function assertReadyForNewLease(string $expectedPanelScope): array
    {
        $health = $this->fetchHealth($expectedPanelScope);

        if (($health['state'] ?? 'unknown') !== 'ready') {
            throw new DiagnosticDomainException(
                'diagnostics_storage_not_ready',
                '诊断日志存储未就绪或已触发容量保护，无法开启新的 15 分钟详细诊断租约',
                503,
                [
                    'storage_health' => $health,
                ]
            );
        }

        return $health;
    }

    /**
     * Fetch current storage guard health for status polling and coverage calculation.
     * Never throws on network/configuration failure; returns state=unknown when unreachable.
     *
     * @return array<string, mixed>
     */
    public function fetchHealth(?string $expectedPanelScope = null): array
    {
        $healthUrl = $this->resolveHealthUrl();
        if ($healthUrl === '') {
            return $this->buildUnknownHealth(['health_endpoint_unconfigured']);
        }

        try {
            $response = $this->buildHttpClient()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($healthUrl);
        } catch (\Throwable) {
            return $this->buildUnknownHealth(['health_request_failed']);
        }

        if (!$response->ok()) {
            return $this->buildUnknownHealth(['health_http_' . $response->status()]);
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return $this->buildUnknownHealth(['health_invalid_json']);
        }

        return $this->evaluateHealthPayload($payload, $expectedPanelScope);
    }

    /**
     * Evaluate a raw health payload from GET /diagnostics/health.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function evaluateHealthPayload(array $payload, ?string $expectedPanelScope = null): array
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $panelScope = is_string($payload['panel_scope'] ?? null) ? trim($payload['panel_scope']) : '';
        $storageId = is_string($payload['storage_id'] ?? null) ? trim($payload['storage_id']) : '';
        $rawState = is_string($payload['state'] ?? null) ? trim($payload['state']) : 'unknown';
        $latched = (bool) ($payload['latched'] ?? false);
        $checkedAtUtc = is_string($payload['checked_at_utc'] ?? null) ? trim($payload['checked_at_utc']) : null;
        $checkAge = isset($payload['check_age_seconds']) && is_numeric($payload['check_age_seconds'])
            ? (float) $payload['check_age_seconds']
            : null;

        $reasonCodes = [];
        if (isset($payload['reason_codes']) && is_array($payload['reason_codes'])) {
            foreach ($payload['reason_codes'] as $code) {
                if (is_string($code) && trim($code) !== '') {
                    $reasonCodes[] = trim($code);
                }
            }
        }

        if ($schemaVersion !== DiagnosticContract::SCHEMA_VERSION) {
            $reasonCodes[] = 'invalid_health_schema_version';
            $rawState = 'unknown';
        }

        if ($expectedPanelScope !== null && $expectedPanelScope !== '' && $panelScope !== $expectedPanelScope) {
            $reasonCodes[] = 'panel_scope_mismatch';
            $rawState = 'unknown';
        }

        if ($storageId === '') {
            $reasonCodes[] = 'missing_storage_id';
            $rawState = 'unknown';
        }

        if ($checkAge === null || $checkAge < 0 || $checkAge > self::MAX_CHECK_AGE_SECONDS) {
            $reasonCodes[] = 'stale_health_check';
            if ($rawState === 'ready') {
                $rawState = 'blocked';
            }
        }

        if ($latched && $rawState === 'ready') {
            $reasonCodes[] = 'capacity_guard_latched';
            $rawState = 'blocked';
        }

        if (!in_array($rawState, ['ready', 'blocked', 'unknown'], true)) {
            $rawState = 'unknown';
            $reasonCodes[] = 'unrecognized_health_state';
        }

        return [
            'schema_version' => DiagnosticContract::SCHEMA_VERSION,
            'panel_scope' => $panelScope !== '' ? $panelScope : null,
            'storage_id' => $storageId !== '' ? $storageId : null,
            'state' => $rawState,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'checked_at_utc' => $checkedAtUtc,
            'check_age_seconds' => $checkAge !== null ? (int) round($checkAge) : null,
            'latched' => $latched,
            'data_used_bytes' => isset($payload['data_used_bytes']) && is_numeric($payload['data_used_bytes'])
                ? (int) $payload['data_used_bytes']
                : null,
            'data_available_bytes' => isset($payload['data_available_bytes']) && is_numeric($payload['data_available_bytes'])
                ? (int) $payload['data_available_bytes']
                : null,
            'root_available_bytes' => isset($payload['root_available_bytes']) && is_numeric($payload['root_available_bytes'])
                ? (int) $payload['root_available_bytes']
                : null,
        ];
    }

    /**
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private function buildUnknownHealth(array $reasons): array
    {
        return [
            'schema_version' => DiagnosticContract::SCHEMA_VERSION,
            'panel_scope' => null,
            'storage_id' => null,
            'state' => 'unknown',
            'reason_codes' => $reasons,
            'checked_at_utc' => null,
            'check_age_seconds' => null,
            'latched' => false,
            'data_used_bytes' => null,
            'data_available_bytes' => null,
            'root_available_bytes' => null,
        ];
    }

    private function resolveHealthUrl(): string
    {
        $configured = config('diagnostics.health_url');
        if (!is_string($configured) || trim($configured) === '') {
            $configured = admin_setting('diagnostics_health_url', '');
        }
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $baseUrl = config('diagnostics.base_url');
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            $baseUrl = admin_setting('diagnostics_base_url', '');
        }
        if (is_string($baseUrl) && trim($baseUrl) !== '') {
            return rtrim(trim($baseUrl), '/') . '/diagnostics/health';
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
