<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ProductionReleaseWorkflowTest extends TestCase
{
    public function test_production_commit_is_verified_and_built_once_as_a_signed_amd64_image(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString(
            "github.event_name != 'workflow_dispatch' ||\n          inputs.production_release_action == 'admin_assets'",
            str_replace("\r\n", "\n", $workflow)
        );
        $this->assertStringContainsString(
            "if: \${{ github.event_name == 'push' && github.ref == 'refs/heads/codex/distributor' }}",
            $workflow
        );
        $this->assertStringContainsString('platforms: linux/amd64', $workflow);
        $this->assertStringNotContainsString('linux/arm64', $workflow);
        $this->assertStringNotContainsString('docker/setup-qemu-action', $workflow);
        $this->assertStringContainsString('Publish the immutable full-SHA image lock', $workflow);
        $this->assertStringContainsString('--prefer-index=false', $workflow);
        $this->assertStringContainsString('--tag "$IMAGE:sha-$GITHUB_SHA"', $workflow);
        $this->assertStringContainsString('uses: actions/attest@v4', $workflow);
        $this->assertStringContainsString('push-to-registry: true', $workflow);
        $this->assertStringContainsString('uses: actions/upload-artifact@v4', $workflow);
        $this->assertStringContainsString('name: production-image-${{ github.sha }}', $workflow);
        $this->assertStringContainsString('if-no-files-found: error', $workflow);
        $this->assertStringContainsString('production-image-manifest.php create', $workflow);
        $this->assertStringContainsString('bash .github/scripts/test-resolve-production-image.sh', $workflow);
        $parsed = Yaml::parse($workflow);
        $this->assertSame(
            [
                'none',
                'admin_assets',
                'admin_assets_rollback',
                'activate_roles',
                'repair_roles',
                'retire_previous',
                'rollback',
                'cleanup',
                'v2_rollback',
            ],
            $parsed['on']['workflow_dispatch']['inputs']['production_release_action']['options'] ?? null
        );
        $this->assertStringContainsString('reject-deprecated-release-controls:', $workflow);
        $this->assertStringContainsString(
            'Legacy deploy/stage controls are disabled. Run Distributor Production Release instead.',
            $workflow
        );

        foreach ([
            'Run PHP 8.3 SQLite regression tests',
            'Run MySQL 5.7 compatibility regression',
            'Run MySQL 8.4 compatibility regression',
            'Run PHP 8.4 SQLite regression tests',
            'Validate companion JavaScript',
            'Validate deployment scripts',
        ] as $requiredGate) {
            $this->assertStringContainsString($requiredGate, $workflow);
        }
    }

    public function test_release_resolves_one_canonical_build_and_verifies_signed_provenance(): void
    {
        $path = base_path('.github/workflows/production-release.yml');
        $workflow = file_get_contents($path);
        $parsed = Yaml::parseFile($path);

        $this->assertIsString($workflow);
        $this->assertIsArray($parsed);
        $this->assertSame(
            ['resolve-signed-image', 'production-preflight', 'retention-gate'],
            $parsed['jobs']['stage-signed-image']['needs'] ?? null
        );
        $this->assertSame(
            ['resolve-signed-image', 'stage-signed-image'],
            $parsed['jobs']['prepare-approved-release']['needs'] ?? null
        );
        $this->assertSame(
            ['resolve-signed-image', 'prepare-approved-release'],
            $parsed['jobs']['switch-approved-release']['needs'] ?? null
        );

        foreach ([
            'resolve-production-image.sh',
            'head_sha=$EXPECTED_SHA',
            'event=push&status=success',
            'production-image-$EXPECTED_SHA',
            'gh attestation verify',
            '--signer-workflow "$GITHUB_REPOSITORY/.github/workflows/docker-publish.yml"',
            '--signer-digest "$EXPECTED_SHA"',
            '--source-digest "$EXPECTED_SHA"',
            '--source-ref refs/heads/codex/distributor',
            '--deny-self-hosted-runners',
        ] as $identityControl) {
            $this->assertStringContainsString(
                $identityControl,
                $workflow . file_get_contents(base_path('.github/scripts/resolve-production-image.sh'))
            );
        }

        $imageReference = '${{ needs.resolve-signed-image.outputs.image_ref }}';
        $this->assertGreaterThanOrEqual(4, substr_count($workflow, $imageReference));
        $this->assertStringContainsString('STAGE_IMAGE: ' . $imageReference, $workflow);
        $this->assertStringContainsString('RELEASE_IMAGE: ' . $imageReference, $workflow);
    }

    public function test_release_retention_lifecycle_is_guarded_and_does_not_rebuild_images(): void
    {
        $path = base_path('.github/workflows/production-release.yml');
        $workflow = file_get_contents($path);
        $parsed = Yaml::parseFile($path);

        $this->assertIsString($workflow);
        $this->assertSame(
            ['none', 'retention_audit', 'v2_finalize', 'retention_cleanup', 'network_audit', 'network_cleanup'],
            $parsed['on']['workflow_dispatch']['inputs']['maintenance_action']['options'] ?? null
        );
        foreach ([
            'retention-audit',
            'pre-finalize-v2-smoke',
            'finalize-v2-release',
            'verify-finalized-production',
            'pre-retention-cleanup-smoke',
            'cleanup-retired-production-assets',
            'verify-retention-cleanup',
            'network-debt-audit',
            'pre-network-debt-cleanup-smoke',
            'cleanup-orphan-production-networks',
            'verify-network-debt-cleanup',
        ] as $job) {
            $this->assertArrayHasKey($job, $parsed['jobs']);
            $this->assertStringContainsString(
                "github.ref == 'refs/heads/codex/distributor'",
                $parsed['jobs'][$job]['if'] ?? '',
                $job
            );
            $this->assertSame('distributor-server', $parsed['jobs'][$job]['environment'] ?? null, $job);
        }

        $this->assertStringContainsString("inputs.maintenance_action == 'none'", $parsed['jobs']['resolve-signed-image']['if'] ?? '');
        $this->assertSame(['production-preflight'], (array) ($parsed['jobs']['retention-gate']['needs'] ?? []));
        $this->assertStringContainsString('RETENTION_REQUIRE_FINALIZED=true', $workflow);
        $this->assertStringContainsString("RETENTION_REQUIRED_FREE_PORT='\$RETENTION_REQUIRED_FREE_PORT'", $workflow);
        $this->assertStringContainsString('Verify public production before retiring rollback assets', $workflow);
        $this->assertStringContainsString('Audit runtime and retained assets after finalize', $workflow);
        $legacyWorkflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $this->assertIsString($legacyWorkflow);
        $this->assertStringNotContainsString("inputs.production_release_action == 'v2_finalize'", $legacyWorkflow);
        $this->assertStringNotContainsString('finalize-v2-low-memory:', $legacyWorkflow);
        $this->assertSame(['pre-finalize-v2-smoke'], (array) ($parsed['jobs']['finalize-v2-release']['needs'] ?? []));
        $this->assertSame(['finalize-v2-release'], (array) ($parsed['jobs']['verify-finalized-production']['needs'] ?? []));
        $this->assertSame(
            ['pre-retention-cleanup-smoke'],
            (array) ($parsed['jobs']['cleanup-retired-production-assets']['needs'] ?? [])
        );
        $this->assertSame(
            ['cleanup-retired-production-assets'],
            (array) ($parsed['jobs']['verify-retention-cleanup']['needs'] ?? [])
        );
        $this->assertStringContainsString('RETENTION_ACQUIRE_LOCK=true', $workflow);
        $this->assertStringContainsString('RETENTION_REQUIRE_FINALIZED=true', $workflow);
        $this->assertStringContainsString('cleanup_expected_resource_fingerprint', $workflow);
        $this->assertStringContainsString('Verify public production before exact retention cleanup', $workflow);
        $this->assertStringContainsString('Verify public production after exact retention cleanup', $workflow);
        $this->assertStringContainsString('Verify live admin assets before exact retention cleanup', $workflow);
        $this->assertStringContainsString('Verify live admin assets after exact retention cleanup', $workflow);
        $this->assertSame(
            ['pre-network-debt-cleanup-smoke'],
            (array) ($parsed['jobs']['cleanup-orphan-production-networks']['needs'] ?? [])
        );
        $this->assertSame(
            ['cleanup-orphan-production-networks'],
            (array) ($parsed['jobs']['verify-network-debt-cleanup']['needs'] ?? [])
        );
        $this->assertStringContainsString('network_cleanup_expected_fingerprint', $workflow);
        $this->assertStringContainsString('NETWORK_DEBT_CANDIDATE_COUNT=0', $workflow);
        $this->assertStringContainsString('Verify public production before exact network cleanup', $workflow);
        $this->assertStringContainsString('Verify public production after exact network cleanup', $workflow);
    }

    public function test_retention_audit_is_read_only_and_fails_closed(): void
    {
        $path = base_path('.github/scripts/audit-xboard-retention.sh');
        $script = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertIsString($script);
        foreach ([
            'EXPECTED_WORKFLOW_SHA is required',
            'active_revision_missing',
            'active_state_revision_mismatch',
            'maintenance_container',
            'RETENTION_ACTIVE_REDIS_VOLUME=',
            'RETENTION_ACTIVE_APP_DATA_ID=',
            'RETENTION_DIRECT_PREVIOUS_MAINTENANCE=',
            'active_release_not_finalized',
            'rollback_support_not_closed',
            'required_port_in_use',
            'RETENTION_IDENTITY_FINGERPRINT=',
            'RETENTION_RESOURCE_FINGERPRINT=',
            'RETENTION_AUDIT_FINGERPRINT=',
            'RETENTION_AUDIT=PASS mode=read_only',
        ] as $guard) {
            $this->assertStringContainsString($guard, $script);
        }
        foreach ([
            'docker rm',
            'docker container rm',
            'docker image rm',
            'docker volume rm',
            'docker system prune',
            'release_state_set',
            'release_state_open',
            'rm -rf',
        ] as $mutation) {
            $this->assertStringNotContainsString($mutation, $script, $mutation);
        }
        $this->assertStringNotContainsString("done < <(docker ps -aq --no-trunc)\n  size=", $script);
        $this->assertStringContainsString('image_ref_counts["$image_id"]', $script);
        $this->assertStringContainsString('org.opencontainers.image.source', $script);
    }

    public function test_retention_cleanup_is_fingerprinted_retryable_and_preserves_data(): void
    {
        $path = base_path('.github/scripts/cleanup-xboard-retention.sh');
        $script = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertIsString($script);
        foreach ([
            'EXPECTED_RETENTION_RESOURCE_FINGERPRINT is required',
            'RETENTION_REQUIRE_FINALIZED',
            'RETENTION_ACQUIRE_LOCK',
            'resource_fingerprint_mismatch',
            'active_release_not_finalized',
            'rollback_support_not_closed',
            'direct_rollback_still_present',
            'candidate_identity_changed',
            'maintenance_referenced_by_caddy',
            'image_min_age_below_seven_days',
            'retention_cleanup_source_fingerprint',
            'retention_cleanup_status',
            'active_app_data_changed',
            'active_redis_volume_missing',
            'compose_anchor_missing',
            'volumes=preserved directories=preserved',
        ] as $guard) {
            $this->assertStringContainsString($guard, $script);
        }
        foreach ([
            'docker system prune',
            'docker image prune',
            'docker volume rm',
            'docker volume prune',
            'docker container rm -v',
            'docker image rm -f',
            'rm -rf',
        ] as $forbiddenMutation) {
            $this->assertStringNotContainsString($forbiddenMutation, $script, $forbiddenMutation);
        }
        $this->assertStringContainsString('docker container rm "$id"', $script);
        $this->assertStringContainsString('docker image rm "$candidate_image_id"', $script);
        $this->assertStringContainsString('https://github.com/Hao-Monster/Xboardme', $script);
        $this->assertStringContainsString('https://github.com/FengHaoyun-MONSTER/Xboardme', $script);
    }

    public function test_network_debt_cleanup_is_fingerprinted_and_never_uses_global_prune(): void
    {
        $auditPath = base_path('.github/scripts/audit-xboard-network-debt.sh');
        $cleanupPath = base_path('.github/scripts/cleanup-xboard-network-debt.sh');
        $audit = file_get_contents($auditPath);
        $cleanup = file_get_contents($cleanupPath);

        $this->assertFileExists($auditPath);
        $this->assertFileExists($cleanupPath);
        $this->assertIsString($audit);
        $this->assertIsString($cleanup);
        foreach ([
            'classification=candidate',
            'empty_retired_release_network',
            'identity_or_state_invalid',
            'container_endpoints_present',
            'NETWORK_DEBT_FINGERPRINT=',
            'NETWORK_DEBT_AUDIT=PASS mode=read_only',
        ] as $guard) {
            $this->assertStringContainsString($guard, $audit);
        }
        foreach ([
            'EXPECTED_NETWORK_DEBT_FINGERPRINT is required',
            'active_release_not_finalized',
            'rollback_support_not_closed',
            'direct_rollback_still_present',
            'fingerprint_mismatch',
            'candidate_gained_endpoints',
            'candidate_state_changed',
            'active_app_data_changed',
            'active_redis_volume_missing',
            'containers=preserved volumes=preserved images=preserved directories=preserved',
        ] as $guard) {
            $this->assertStringContainsString($guard, $cleanup);
        }
        foreach ([$audit, $cleanup] as $script) {
            $this->assertStringNotContainsString('docker network prune', $script);
            $this->assertStringNotContainsString('docker system prune', $script);
            $this->assertStringNotContainsString('docker volume rm', $script);
            $this->assertStringNotContainsString('rm -rf', $script);
        }
        $this->assertStringNotContainsString('docker network rm', $audit);
        $this->assertStringContainsString('docker network rm "$id"', $cleanup);
    }

    public function test_prepare_and_switch_are_separate_approval_boundaries_without_idle_maintenance(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/production-release.yml'));

        $this->assertIsString($workflow);
        preg_match(
            '/^  prepare-approved-release:\R(?<body>.*?)(?=^  [a-z][a-z0-9-]+:|\z)/ms',
            $workflow,
            $prepare
        );
        preg_match(
            '/^  switch-approved-release:\R(?<body>.*?)(?=^  [a-z][a-z0-9-]+:|\z)/ms',
            $workflow,
            $switch
        );

        $this->assertArrayHasKey('body', $prepare);
        $this->assertArrayHasKey('body', $switch);
        $this->assertStringContainsString('environment: distributor-server', $prepare['body']);
        $this->assertStringContainsString('environment: distributor-server', $switch['body']);
        $this->assertStringContainsString('prepare-xboard-v2-low-memory.sh', $prepare['body']);
        $this->assertStringContainsString(
            'release_id: ${{ steps.release-identity.outputs.release_id }}',
            $prepare['body']
        );
        $this->assertStringNotContainsString('start-xboard-v2-low-memory.sh', $prepare['body']);
        $this->assertStringContainsString('start-xboard-v2-low-memory.sh', $switch['body']);
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($switch['body'], '${{ needs.prepare-approved-release.outputs.release_id }}')
        );
        $this->assertStringContainsString('switch-xboard-v2-low-memory.sh', $switch['body']);
        $this->assertStringContainsString('verify_public_assets: true', $switch['body']);
        $this->assertStringContainsString('rollback-xboard-v2-low-memory.sh', $switch['body']);
        $this->assertStringContainsString(
            "failure() && (steps.start.outcome == 'success' || steps.start.outcome == 'failure')",
            $switch['body']
        );
        $this->assertStringContainsString('validation_mode: rollback', $switch['body']);
        $this->assertStringContainsString('preflight-xboard-compose.sh', $switch['body']);
    }

    public function test_read_only_preflight_cannot_build_or_publish_an_image(): void
    {
        $preflight = file_get_contents(base_path('.github/workflows/distributor-preflight.yml'));
        $publish = file_get_contents(base_path('.github/workflows/docker-publish.yml'));

        $this->assertIsString($preflight);
        $this->assertIsString($publish);
        $this->assertStringNotContainsString('docker/build-push-action', $preflight);
        $this->assertStringNotContainsString('docker/setup-buildx-action', $preflight);
        $this->assertStringNotContainsString('packages: write', $preflight);
        $this->assertStringContainsString(
            "github.event_name != 'workflow_dispatch' ||\n          inputs.production_release_action == 'admin_assets'",
            str_replace("\r\n", "\n", $publish)
        );
    }

    public function test_production_image_manifest_fails_closed_on_identity_changes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xboard-production-image-');
        $this->assertIsString($path);
        $script = base_path('.github/scripts/production-image-manifest.php');
        $repository = 'Hao-Monster/Xboardme';
        $sha = str_repeat('a', 40);
        $digest = 'sha256:' . str_repeat('b', 64);

        try {
            $create = new Process([
                PHP_BINARY,
                $script,
                'create',
                $path,
                $repository,
                $sha,
                'ghcr.io/hao-monster/xboardme',
                $digest,
                '12345',
            ]);
            $create->run();
            $this->assertTrue($create->isSuccessful(), $create->getErrorOutput());

            $verify = new Process([
                PHP_BINARY,
                $script,
                'verify',
                $path,
                $repository,
                $sha,
                '12345',
            ]);
            $verify->run();
            $this->assertTrue($verify->isSuccessful(), $verify->getErrorOutput());

            $canonical = (string) file_get_contents($path);
            file_put_contents($path, rtrim($canonical));
            $nonCanonical = new Process([
                PHP_BINARY,
                $script,
                'verify',
                $path,
                $repository,
                $sha,
                '12345',
            ]);
            $nonCanonical->run();
            $this->assertFalse($nonCanonical->isSuccessful());
            $this->assertStringContainsString('PRODUCTION_IMAGE_MANIFEST_FAIL=contract_mismatch', $nonCanonical->getErrorOutput());

            file_put_contents($path, $canonical);

            $manifest = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $manifest['platform'] = 'linux/arm64';
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

            $tampered = new Process([
                PHP_BINARY,
                $script,
                'verify',
                $path,
                $repository,
                $sha,
                '12345',
            ]);
            $tampered->run();
            $this->assertFalse($tampered->isSuccessful());
            $this->assertStringContainsString('PRODUCTION_IMAGE_MANIFEST_FAIL=contract_mismatch', $tampered->getErrorOutput());
        } finally {
            @unlink($path);
        }
    }
}
