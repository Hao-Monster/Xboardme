<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ResourceDiagnosticWorkflowTest extends TestCase
{
    public function test_diagnostic_is_manual_exact_main_and_has_no_operational_inputs(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/production-resource-diagnostic.yml'));
        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['expected_sha'], array_keys($workflow['on']['workflow_dispatch']['inputs']));
        $this->assertTrue($workflow['on']['workflow_dispatch']['inputs']['expected_sha']['required']);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        $this->assertSame('xboard-production-release', $workflow['concurrency']['group']);
        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $this->assertSame(['resource-diagnostic'], array_keys($workflow['jobs']));
        $job = $workflow['jobs']['resource-diagnostic'];
        $this->assertSame('distributor-server', $job['environment']);
        $this->assertSame(5, $job['timeout-minutes']);
        foreach (["github.ref == 'refs/heads/main'", "github.repository == 'Hao-Monster/Xboardme'", "github.event_name == 'workflow_dispatch'"] as $guard) {
            $this->assertStringContainsString($guard, $job['if']);
        }
        $this->assertSame('${{ github.sha }}', $job['steps'][0]['with']['ref']);
        $this->assertSame('recursive', $job['steps'][0]['with']['submodules']);
        $this->assertSame(0, $job['steps'][0]['with']['fetch-depth']);
    }

    public function test_admission_precedes_host_access_and_artifact_is_the_only_final_step(): void
    {
        $steps = Yaml::parseFile(base_path('.github/workflows/production-resource-diagnostic.yml'))['jobs']['resource-diagnostic']['steps'];
        $this->assertCount(5, $steps);
        $this->assertStringContainsString('verify-resource-diagnostic-request.sh', $steps[1]['run']);
        $this->assertArrayNotHasKey('env', $steps[1]);
        $this->assertStringContainsString('ssh-keyscan', $steps[2]['run']);
        $this->assertStringContainsString('run-resource-diagnostic-remote.sh', $steps[3]['run']);
        $this->assertSame('${{ always() }}', $steps[4]['if']);
        $this->assertSame('actions/upload-artifact@v4', $steps[4]['uses']);
        $this->assertSame('error', $steps[4]['with']['if-no-files-found']);
        $this->assertSame(90, $steps[4]['with']['retention-days']);
        $workflowSource = file_get_contents(base_path('.github/workflows/production-resource-diagnostic.yml'));
        foreach (['GHCR_TOKEN', 'DISTRIBUTOR_PASSWORD', 'distributor-smoke', 'workflow run', 'post-success', 'post-failure', 'release_state', 'run-images-only-remote.sh'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflowSource);
        }
    }

    public function test_transport_is_a_fixed_resource_function_closure_not_the_retention_module(): void
    {
        $transport = file_get_contents(base_path('.github/scripts/run-resource-diagnostic-remote.sh'));
        $this->assertStringContainsString('source "$script_dir/verify-resource-diagnostic-request.sh"', $transport);
        $this->assertStringContainsString('declare -f _rc_run _rc_jq _rc_resource_read _rc_resource_error _rc_resource_limits_valid _rc_resource_sample', $transport);
        $this->assertStringContainsString('cat "$script_dir/diagnose-retention-resources.sh"', $transport);
        $this->assertStringContainsString('env -i PATH=/usr/bin:/bin LC_ALL=C', $transport);
        $this->assertStringContainsString('timeout --signal=TERM --kill-after=2s 90s bash --noprofile --norc -se', $transport);
        $this->assertStringContainsString('timeout --signal=TERM --kill-after=2s 120s', $transport);
        foreach (['cat "$script_dir/retention-continuity.sh"', 'RETENTION_REVIEW_MANIFEST_B64', 'verify-image-restore.sh', 'cleanup-xboard', 'retention_continuity_postverify'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $transport);
        }
    }

    public function test_host_sampling_is_fixed_and_never_invokes_production_services_or_gates(): void
    {
        $host = file_get_contents(base_path('.github/scripts/diagnose-retention-resources.sh'));
        $this->assertStringContainsString('for diagnostic_index in 1 2 3; do', $host);
        $this->assertStringContainsString('if _rc_resource_sample "$RESOURCE_DIAGNOSTIC_LIMITS"; then', $host);
        $this->assertStringContainsString('12s sleep 10', $host);
        $this->assertStringContainsString('"continuation_authorized":false', $host);
        $this->assertStringContainsString('"lifecycle_gate":"not_accessed"', $host);
        foreach (['docker ', 'systemctl ', 'curl ', 'wget ', 'sqlite3 ', 'php ', 'flock ', 'mktemp ', 'mkdir ', 'retention_continuity_', 'release_state_', '.codex-v2-release'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $host);
        }
        $this->assertDoesNotMatchRegularExpression('/(?:^|\n)\s*(?:source|eval)\s/', $host);
    }
}
