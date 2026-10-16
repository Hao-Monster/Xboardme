<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class RetainOnlineOnlyCleanupWorkflowTest extends TestCase
{
    public function test_retain_online_only_cleanup_workflow_is_exact_main_manual_and_guarded(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/production-retain-online-only-cleanup.yml'));

        $this->assertSame(['workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['audit', 'cleanup'], $workflow['on']['workflow_dispatch']['inputs']['mode']['options']);
        $this->assertSame('audit', $workflow['on']['workflow_dispatch']['inputs']['mode']['default']);
        $this->assertTrue($workflow['on']['workflow_dispatch']['inputs']['expected_sha']['required']);
        $this->assertSame('xboard-production-release', $workflow['concurrency']['group']);
        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);

        $job = $workflow['jobs']['retain-online-only'];
        $this->assertSame('distributor-server', $job['environment']);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $job['if']);
        $this->assertStringContainsString("github.repository == 'Hao-Monster/Xboardme'", $job['if']);
        $this->assertStringContainsString("github.event_name == 'workflow_dispatch'", $job['if']);
        $this->assertSame('${{ github.sha }}', $job['steps'][0]['with']['ref']);
        $this->assertSame('recursive', $job['steps'][0]['with']['submodules']);
    }

    public function test_retain_online_only_script_never_uses_global_prune_or_volume_deletion(): void
    {
        $script = file_get_contents(base_path('.github/scripts/cleanup-xboard-retain-online-only.sh'));

        $this->assertIsString($script);
        foreach ([
            'docker system prune',
            'docker image prune',
            'docker network prune',
            'docker volume rm',
            'docker volume prune',
            'docker container rm -v',
            'docker image rm -f',
            'docker stop',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, $forbidden);
        }

        foreach ([
            'xboard_find_compose_anchor',
            'xboard_find_caddy_upstream',
            'xboard_resolve_active_runtime',
            'assert_active_runtime_healthy',
            'non_protected_container_not_exited',
            'candidate_network_has_endpoints',
            'release_assert_cleanup_admission',
            'RETAIN_ONLINE_CLEANUP=PASS',
        ] as $required) {
            $this->assertStringContainsString($required, $script);
        }
    }
}
