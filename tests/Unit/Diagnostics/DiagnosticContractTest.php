<?php

declare(strict_types=1);

namespace Tests\Unit\Diagnostics;

use App\Services\Diagnostics\DiagnosticContract;
use InvalidArgumentException;
use Tests\TestCase;

final class DiagnosticContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function loadSchema(): array
    {
        $path = base_path('tests/Fixtures/Diagnostics/contract-schema.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function loadFixtures(): array
    {
        $path = base_path('tests/Fixtures/Diagnostics/contract-fixtures.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    public function test_contract_schema_matches_php_constants_and_whitelists(): void
    {
        $schema = $this->loadSchema();

        $this->assertSame(DiagnosticContract::SCHEMA_VERSION, $schema['schema_version']);
        $this->assertSame(DiagnosticContract::MAX_EVENT_BYTES, $schema['budgets']['max_event_bytes']);
        $this->assertSame(DiagnosticContract::MAX_STACK_BYTES, $schema['budgets']['max_stack_bytes']);
        $this->assertSame(DiagnosticContract::MAX_STACK_FRAMES, $schema['budgets']['max_stack_frames']);
        $this->assertSame(DiagnosticContract::MAX_STACK_FRAME_BYTES, $schema['budgets']['max_stack_frame_bytes']);
        $this->assertSame(DiagnosticContract::MAX_SAFE_MESSAGE_CHARS, $schema['budgets']['max_safe_message_chars']);
        $this->assertSame(DiagnosticContract::MAX_REQUESTED_HOST_CHARS, $schema['budgets']['max_requested_host_chars']);
        $this->assertSame(DiagnosticContract::MAX_OUTBOUND_TAG_CHARS, $schema['budgets']['max_outbound_tag_chars']);
        $this->assertSame(DiagnosticContract::MAX_DNS_ANSWERS, $schema['budgets']['max_dns_answers']);
        $this->assertSame(DiagnosticContract::MAX_SPAN_LINKS, $schema['budgets']['max_span_links']);
        $this->assertSame(DiagnosticContract::MAX_UDP_TARGETS_PER_SESSION, $schema['budgets']['max_udp_targets_per_session']);
        $this->assertSame(DiagnosticContract::MAX_ACTIVE_LEASES_PER_PANEL, $schema['budgets']['max_active_leases_per_panel']);
        $this->assertSame(DiagnosticContract::LEASE_TTL_SECONDS, $schema['budgets']['lease_ttl_seconds']);
        $this->assertSame(DiagnosticContract::LEASE_MAX_RTT_MS, $schema['budgets']['lease_max_rtt_ms']);

        $this->assertSame(DiagnosticContract::EVENT_FAMILIES, $schema['enums']['event_family']);
        $this->assertSame(DiagnosticContract::EVENTS, $schema['enums']['event']);
        $this->assertSame(DiagnosticContract::STAGES, $schema['enums']['stage']);
        $this->assertSame(DiagnosticContract::NETWORKS, $schema['enums']['network']);
        $this->assertSame(DiagnosticContract::DOMAIN_SOURCES, $schema['enums']['domain_source']);
        $this->assertSame(DiagnosticContract::OBSERVATION_MODES, $schema['enums']['observation_mode']);
        $this->assertSame(DiagnosticContract::REASON_CODES, $schema['enums']['reason_code']);
        $this->assertSame(DiagnosticContract::TRANSPORT_STATES, $schema['enums']['transport_state']);
        $this->assertSame(DiagnosticContract::SUMMARY_REASONS, $schema['enums']['summary_reason']);
        $this->assertSame(DiagnosticContract::SPAN_NAMES, $schema['enums']['span_name']);
        $this->assertSame(DiagnosticContract::SPAN_STATUSES, $schema['enums']['span_status']);
        $this->assertSame(DiagnosticContract::SPAN_LINK_RELATIONS, $schema['enums']['span_link_relation']);
        $this->assertSame(DiagnosticContract::STACK_CAPTURE_KINDS, $schema['enums']['stack_capture_kind']);
        $this->assertSame(DiagnosticContract::NODE_RESOLUTIONS, $schema['enums']['node_resolution']);
        $this->assertSame(DiagnosticContract::CAPABILITIES, $schema['enums']['capabilities']);

        $this->assertSame(DiagnosticContract::EVENT_TO_FAMILY, $schema['event_to_family']);

        foreach (DiagnosticContract::EVENT_FAMILIES as $family) {
            $this->assertSame(
                DiagnosticContract::ALLOWED_FIELDS_BY_FAMILY[$family],
                $schema['family_rules'][$family]['allowed_fields'],
                "Allowed fields mismatch for family {$family}"
            );
            foreach (DiagnosticContract::FORBIDDEN_COMMON_FIELDS as $forbidden) {
                $this->assertContains(
                    $forbidden,
                    $schema['family_rules'][$family]['forbidden_fields'],
                    "Forbidden field {$forbidden} missing in schema for family {$family}"
                );
            }
        }
    }

    public function test_diagnostic_id_vectors_and_uuid_rotation_invariance(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['identity_vectors'] as $vector) {
            $actual = DiagnosticContract::computeDiagnosticId(
                (string) $vector['panel_scope'],
                (int) $vector['subscriber_user_id']
            );

            $this->assertSame(
                $vector['expected_diagnostic_id'],
                $actual,
                "Mismatch for identity vector: {$vector['name']}"
            );
            $this->assertTrue(DiagnosticContract::isValidDiagnosticId($actual));

            if (isset($vector['forbidden_distributor_diagnostic_id'])) {
                $this->assertNotSame(
                    $vector['forbidden_distributor_diagnostic_id'],
                    $actual,
                    "Distributor order must never resolve to distributor's own diagnostic_id"
                );
            }
        }
    }

    public function test_invalid_identity_vectors_and_trailing_newline_fail_closed(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['invalid_identity_vectors'] as $vector) {
            try {
                DiagnosticContract::computeDiagnosticId(
                    (string) $vector['panel_scope'],
                    (int) $vector['subscriber_user_id']
                );
                $this->fail("Expected InvalidArgumentException for vector: {$vector['name']}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(
                    $vector['expected_error_code'],
                    $e->getMessage(),
                    "Unexpected error code for vector: {$vector['name']}"
                );
            }
        }

        $this->assertFalse(DiagnosticContract::isValidPanelScope("00000000-0000-4000-8000-000000000001\n"));
        $this->assertFalse(DiagnosticContract::isValidDiagnosticId("66f4f8087e40081074ff37ce\n"));
        $this->assertFalse(DiagnosticContract::isValidHexId("0123456789abcdef0123456789abcdef\n", 32));
    }

    public function test_event_id_vectors_and_negative_inputs(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['event_id_vectors'] as $vector) {
            $actual = DiagnosticContract::computeEventId(
                (string) $vector['panel_scope'],
                (int) $vector['node_id'],
                (string) $vector['runtime_id'],
                (string) $vector['event_family'],
                (string) $vector['owner_id'],
                (int) $vector['sequence']
            );

            $this->assertSame(
                $vector['expected_event_id'],
                $actual,
                "Mismatch for event_id vector: {$vector['name']}"
            );
            $this->assertSame(hash('sha256', (string) $vector['expected_canonical']), $actual);
        }

        $validScope = '00000000-0000-4000-8000-000000000001';
        $validHex32 = '0123456789abcdef0123456789abcdef';

        $negativeCases = [
            ['bad-scope', 11, $validHex32, 'connection', $validHex32, 1, 'invalid_panel_scope'],
            [$validScope, 0, $validHex32, 'connection', $validHex32, 1, 'invalid_node_id'],
            [$validScope, 11, 'short', 'connection', $validHex32, 1, 'invalid_runtime_id'],
            [$validScope, 11, $validHex32, 'bad_family', $validHex32, 1, 'invalid_event_family'],
            [$validScope, 11, $validHex32, 'connection', 'bad_owner', 1, 'invalid_owner_id'],
            [$validScope, 11, $validHex32, 'connection', $validHex32, 0, 'invalid_sequence'],
        ];

        foreach ($negativeCases as [$scope, $nodeId, $rtId, $family, $ownerId, $seq, $expectedCode]) {
            try {
                DiagnosticContract::computeEventId($scope, $nodeId, $rtId, $family, $ownerId, $seq);
                $this->fail("Expected InvalidArgumentException({$expectedCode})");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($expectedCode, $e->getMessage());
            }
        }
    }

    public function test_valid_and_invalid_event_fixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['valid_events'] as $case) {
            $result = DiagnosticContract::validateEvent($case['payload']);
            $this->assertTrue(
                $result['valid'],
                "Expected valid event for {$case['name']}, got errors: " . implode(', ', $result['errors'])
            );
        }

        foreach ($fixtures['invalid_events'] as $case) {
            $result = DiagnosticContract::validateEvent($case['payload']);
            $this->assertFalse(
                $result['valid'],
                "Expected invalid event for {$case['name']}"
            );
            $matched = false;
            foreach ($result['errors'] as $err) {
                if (str_starts_with($err, (string) $case['expected_reason'])) {
                    $matched = true;
                    break;
                }
            }
            $this->assertTrue(
                $matched,
                "Expected error starting with {$case['expected_reason']} for {$case['name']}, got: " . implode(', ', $result['errors'])
            );
        }
    }

    public function test_domain_normalization_stack_sanitization_and_unicode_redaction(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['domain_normalization_vectors'] as $vector) {
            $normalized = DiagnosticContract::normalizeDomain((string) $vector['input']);
            $this->assertSame(
                $vector['expected'],
                $normalized,
                "Domain normalization mismatch for input: {$vector['input']}"
            );
        }

        foreach ($fixtures['stack_sanitization_vectors'] as $vector) {
            $res = DiagnosticContract::formatAndTruncateStack($vector['raw_frames']);
            $this->assertSame($vector['expected_frames_total'], $res['stack_frames_total']);
            $this->assertSame($vector['expected_truncated'], $res['stack_truncated']);
            $lines = explode("\n", $res['stacktrace']);
            $this->assertCount($vector['expected_frames_kept'], $lines);
            $this->assertSame($vector['expected_first_frame'], $lines[0]);
            $this->assertSame($vector['expected_second_frame'], $lines[1]);
            $this->assertSame($vector['expected_third_frame'], $lines[2]);
            $this->assertSame($vector['expected_fourth_frame'], $lines[3]);
            $this->assertSame($vector['expected_fifth_frame'], $lines[4]);
            $this->assertLessThanOrEqual(DiagnosticContract::MAX_STACK_BYTES, strlen($res['stacktrace']));

            foreach ($vector['forbidden_substrings'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $res['stacktrace'],
                    "Stacktrace leaked forbidden substring: {$forbidden}"
                );
            }
        }

        // Verify per-frame 192-byte cap and string frame handling
        $longFnFrame = DiagnosticContract::sanitizeStackFrame([
            'function' => 'pkg/very_long.' . str_repeat('中文函数名Abc', 25),
            'file' => '/home/user/secret/internal/diagnostics/stack.go',
            'line' => 99,
        ]);
        $this->assertLessThanOrEqual(DiagnosticContract::MAX_STACK_FRAME_BYTES, strlen($longFnFrame));
        $this->assertTrue(mb_check_encoding($longFnFrame, 'UTF-8'));
        $this->assertStringNotContainsString('/home/user', $longFnFrame);

        $stringFrameRes = DiagnosticContract::formatAndTruncateStack([
            "pkg/fn.Call(0xc000999) (/home/secretuser/Xboard-Node/internal/diagnostics/stack.go:77)",
        ]);
        $this->assertSame("pkg/fn.Call (internal/diagnostics/stack.go:77)", $stringFrameRes['stacktrace']);

        foreach ($fixtures['message_sanitization_vectors'] as $vector) {
            $clean = DiagnosticContract::sanitizeMessage((string) $vector['raw_message']);
            $this->assertLessThanOrEqual(
                (int) $vector['max_chars'],
                mb_strlen($clean, 'UTF-8'),
                'Sanitized message exceeded max Unicode code points'
            );
            $this->assertTrue(mb_check_encoding($clean, 'UTF-8'), 'Sanitized message must remain valid UTF-8');

            foreach ($vector['forbidden_substrings'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $clean,
                    "Sanitized message leaked forbidden substring: {$forbidden}"
                );
            }
        }
    }

    public function test_event_4096_byte_budget_degradation_and_max_required_fields_fit(): void
    {
        $fixtures = $this->loadFixtures();
        $baseEvent = $fixtures['valid_events'][2]['payload']; // connection_dial_failure_with_detail_stack

        // 1. Verify maximum-length required + standard fields fit well within 4096 bytes
        $maxRequiredEvent = $baseEvent;
        $maxRequiredEvent['requested_host'] = str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.' . str_repeat('d', 60) . '.com';
        $maxRequiredEvent['observed_domain'] = $maxRequiredEvent['requested_host'];
        $maxRequiredEvent['outbound_tag'] = str_repeat('x', DiagnosticContract::MAX_OUTBOUND_TAG_CHARS);
        $maxRequiredEvent['safe_message'] = str_repeat('诊', DiagnosticContract::MAX_SAFE_MESSAGE_CHARS);
        unset($maxRequiredEvent['exception']);

        $jsonMaxReq = json_encode($maxRequiredEvent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($jsonMaxReq);
        $this->assertLessThanOrEqual(DiagnosticContract::MAX_EVENT_BYTES, strlen((string) $jsonMaxReq) + 1);
        $this->assertTrue(DiagnosticContract::validateEvent($maxRequiredEvent)['valid']);

        // 2. Verify oversized event with huge stack + optional fields degrades cleanly <= 4096 bytes and remains valid
        $oversized = $baseEvent;
        $oversized['requested_host'] = $maxRequiredEvent['requested_host'];
        $oversized['observed_domain'] = $maxRequiredEvent['observed_domain'];
        $oversized['outbound_tag'] = $maxRequiredEvent['outbound_tag'];
        $oversized['safe_message'] = $maxRequiredEvent['safe_message'];
        $oversized['dns_answers'] = array_fill(0, 8, '2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $oversized['span_links'] = array_fill(0, 4, [
            'trace_id' => 'f0e1d2c3b4a5968778695a4b3c2d1e0f',
            'span_id' => '2000000000000001',
            'relation' => 'shared_dns_operation',
        ]);
        $oversized['exception']['message'] = $maxRequiredEvent['safe_message'];
        $oversized['exception']['stacktrace'] = implode("\n", array_fill(0, 12, str_repeat('f', 165) . ' (internal/diagnostics/stack.go:100)'));

        $degraded = DiagnosticContract::enforceEventByteBudget($oversized);
        $degradedJson = json_encode($degraded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($degradedJson);
        $this->assertLessThanOrEqual(DiagnosticContract::MAX_EVENT_BYTES, strlen((string) $degradedJson) + 1);
        $validation = DiagnosticContract::validateEvent($degraded);
        $this->assertTrue($validation['valid'], 'Degraded event must remain schema-valid: ' . implode(', ', $validation['errors']));
        $this->assertTrue($degraded['exception']['stack_truncated']);
    }

    public function test_lease_snapshot_ordering_rtt_and_monotonic_deadline_vectors(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['lease_snapshot_vectors'] as $vector) {
            $existingDeadlines = isset($vector['existing_deadlines']) && is_array($vector['existing_deadlines'])
                ? $vector['existing_deadlines']
                : [];

            $res = DiagnosticContract::evaluateLeaseSnapshot(
                $vector['snapshot'],
                (string) $vector['current_epoch'],
                (int) $vector['current_version'],
                (int) $vector['rtt_ms'],
                (int) $vector['monotonic_now_ms'],
                $existingDeadlines
            );

            $this->assertSame(
                $vector['expected_accepted'],
                $res['accepted'],
                "Lease snapshot acceptance mismatch for: {$vector['name']}"
            );

            if ($vector['expected_accepted']) {
                $this->assertSame(
                    $vector['expected_active_diagnostic_ids'],
                    array_keys($res['active_deadlines_ms']),
                    "Active diagnostic IDs mismatch for: {$vector['name']}"
                );
                if (isset($vector['expected_deadline_monotonic_ms']) && $vector['expected_active_diagnostic_ids'] !== []) {
                    $firstDiagId = $vector['expected_active_diagnostic_ids'][0];
                    $this->assertSame(
                        $vector['expected_deadline_monotonic_ms'],
                        $res['active_deadlines_ms'][$firstDiagId],
                        "Monotonic deadline mismatch for: {$vector['name']}"
                    );
                }
            } else {
                $this->assertSame(
                    $vector['expected_reason'],
                    $res['reason'],
                    "Rejection reason mismatch for: {$vector['name']}"
                );
                $this->assertSame($existingDeadlines, $res['active_deadlines_ms']);
            }
        }
    }
}
