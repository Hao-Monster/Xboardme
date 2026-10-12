<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ImageOnlyCleanupWorkflowTest extends TestCase
{
    public function test_image_cleanup_is_a_separate_exact_main_manual_operation(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/production-image-cleanup.yml'));
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['audit', 'retirement-audit', 'gate-audit', 'cleanup'], $workflow['on']['workflow_dispatch']['inputs']['action']['options']);
        $this->assertSame('audit', $workflow['on']['workflow_dispatch']['inputs']['action']['default']);
        $this->assertSame('default', $workflow['on']['workflow_dispatch']['inputs']['review_id']['default']);
        $this->assertSame('xboard-production-release', $workflow['concurrency']['group']);
        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $job = $workflow['jobs']['images-only'];
        $this->assertSame('distributor-server', $job['environment']);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $job['if']);
        $this->assertSame('recursive', $job['steps'][0]['with']['submodules']);
        $this->assertGreaterThanOrEqual(60, $job['timeout-minutes']);
    }

    public function test_post_acceptance_follows_independent_audit_smoke_and_evidence_upload(): void
    {
        $steps = Yaml::parseFile(base_path('.github/workflows/production-image-cleanup.yml'))['jobs']['images-only']['steps'];
        $names = array_column($steps, 'name');
        $positions = array_map(fn ($name) => array_search($name, $names, true), [
            'Prove exactly one image changed and all protected resources survived',
            'Verify current public runtime and authenticated read-only flows',
            'Preserve evidence even for failed or cancelled operations',
            'Accept post-verification only after all required evidence succeeded',
            'Persist HOLD on any unsuccessful cleanup or post-verification',
        ]);
        $this->assertNotContains(false, $positions);
        $ordered = $positions;
        sort($ordered);
        $this->assertSame($ordered, $positions);
        $this->assertStringContainsString('success()', $steps[$positions[3]]['if']);
        $this->assertStringContainsString('failure() || cancelled()', $steps[$positions[4]]['if']);
    }

    public function test_executor_cannot_finalize_containers_or_prune_layers(): void
    {
        $executor = file_get_contents(base_path('.github/scripts/cleanup-xboard-images-only.sh'));
        $this->assertStringContainsString('docker image rm --no-prune', $executor);
        $this->assertStringNotContainsString('docker rm ', $executor);
        $this->assertStringNotContainsString('docker volume rm', $executor);
        $this->assertStringNotContainsString('docker system prune', $executor);
        $this->assertStringNotContainsString('release_state_set', $executor);
        $workflow = file_get_contents(base_path('.github/workflows/production-image-cleanup.yml'));
        $this->assertStringNotContainsString('finalize-xboard', $workflow);
        $this->assertStringNotContainsString('preflight-xboard-compose', $workflow);
        $this->assertStringNotContainsString('workflow run', $workflow);
    }

    public function test_standard_release_waits_for_the_persistent_cleanup_gate(): void
    {
        $jobs = Yaml::parseFile(base_path('.github/workflows/production-release.yml'))['jobs'];
        foreach (['resolve-signed-image', 'pre-finalize-v2-smoke', 'pre-retention-cleanup-smoke', 'pre-network-debt-cleanup-smoke'] as $job) {
            $this->assertSame('cleanup-continuity-admission', $jobs[$job]['needs']);
        }
        $this->assertStringNotContainsString('cleanup-continuity-admission', json_encode($jobs['auto-rollback-failed-approved-release']));
    }

    public function test_retirement_audit_does_not_select_cleanup_or_post_mutations(): void
    {
        $steps = Yaml::parseFile(base_path('.github/workflows/production-image-cleanup.yml'))['jobs']['images-only']['steps'];
        foreach ($steps as $step) {
            if (in_array($step['id'] ?? null, ['restore', 'post-audit', 'post-identity', 'smoke'], true)
                || in_array($step['name'], ['Bind independent post-verification evidence',
                    'Accept post-verification only after all required evidence succeeded',
                    'Persist HOLD on any unsuccessful cleanup or post-verification'], true)) {
                $this->assertStringContainsString("inputs.action == 'cleanup'", $step['if']);
            }
        }
        $transport = file_get_contents(base_path('.github/scripts/run-images-only-remote.sh'));
        $this->assertStringContainsString("printf 'retirement_scope=full\\n'", $transport);
        $this->assertStringContainsString("printf 'retirement_scope=gate\\n'", $transport);
        $this->assertStringContainsString('"$operation" == gate-audit', $transport);
        $this->assertStringContainsString('if [[ "$operation" == cleanup ]]; then cat "$script_dir/cleanup-xboard-images-only.sh"; fi', $transport);
    }

    public function test_recovery_remains_a_cleanup_specific_admission_not_a_deployment_unlock(): void
    {
        $continuity = file_get_contents(base_path('.github/scripts/retention-continuity.sh'));
        $start = strpos($continuity, 'retention_continuity_admit_deployment()');
        $end = strpos($continuity, '_rc_http()', $start);
        $admission = substr($continuity, $start, $end - $start);
        $this->assertStringContainsString('.status=="COMPLETE"', $admission);
        $this->assertStringContainsString('.post_verification=="passed"', $admission);
        $this->assertStringNotContainsString('recovery', $admission);
        $request = file_get_contents(base_path('.github/scripts/verify-images-only-request.sh'));
        $this->assertStringContainsString('manual_first_attempt_required', $request);
        $this->assertStringContainsString('sequence.predecessor_review_sha256==$previous', $request);
        $this->assertStringContainsString('retention_predelete_recovery_schema_validate', $request);
    }

    public function test_passive_cleanup_verifies_restore_before_host_access_and_never_claims_authenticated_smoke(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/production-image-cleanup.yml'));
        $steps = $workflow['jobs']['images-only']['steps'];
        $ids = array_column($steps, 'id');
        $restoreIndex = array_search('restore', $ids, true);
        $hostIndex = array_search('host-key', $ids, true);
        $this->assertNotFalse($restoreIndex);
        $this->assertLessThan($hostIndex, $restoreIndex);
        $byId = [];
        foreach ($steps as $step) {
            if (isset($step['id'])) {
                $byId[$step['id']] = $step;
            }
        }
        $this->assertSame('read', $workflow['permissions']['packages']);
        $this->assertStringContainsString("monitoring_mode == 'passive_runtime_v1'", $byId['restore']['if']);
        $this->assertStringNotContainsString('SERVICES_', json_encode($byId['restore']));
        $this->assertStringNotContainsString('DISTRIBUTOR_', json_encode($byId['restore']));
        $this->assertStringContainsString('verify-image-restore.sh', $byId['restore']['run']);
        $this->assertStringContainsString("monitoring_mode == 'legacy_live_v1'", $byId['smoke']['if']);
        $evidence = array_values(array_filter($steps, fn ($step) => $step['name'] === 'Bind independent post-verification evidence'))[0];
        $this->assertStringContainsString('PASSIVE_RUNTIME_POST_AUDIT=PASS', $evidence['run']);
        $this->assertStringContainsString('images-only-restore.json', $evidence['run']);
    }
}
