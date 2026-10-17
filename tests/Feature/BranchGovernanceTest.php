<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BranchGovernanceTest extends TestCase
{
    public function test_only_main_and_develop_receive_push_checks_and_only_main_can_publish(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/docker-publish.yml'));

        $this->assertSame(['main', 'develop'], $workflow['on']['push']['branches']);
        $this->assertSame(['main'], $workflow['on']['pull_request']['branches']);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $workflow['jobs']['build']['if']);
        $this->assertStringContainsString("github.event_name == 'push'", $workflow['jobs']['build']['if']);
        $this->assertArrayNotHasKey('environment', $workflow['jobs']['verify']);
        $this->assertArrayNotHasKey('environment', $workflow['jobs']['pr-image-build']);
        $this->assertStringContainsString("github.head_ref != 'develop'", $workflow['jobs']['verify']['steps'][1]['if']);
        $this->assertStringContainsString('exit 1', $workflow['jobs']['verify']['steps'][1]['run']);
    }

    public function test_production_identity_and_environment_jobs_no_longer_accept_the_retired_branch(): void
    {
        foreach (glob(base_path('.github/workflows/*.yml')) as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('codex/distributor', $source, $path);
        }
        $resolver = file_get_contents(base_path('.github/scripts/resolve-production-image.sh'));
        $this->assertStringContainsString('branch=main&head_sha=', $resolver);
        $this->assertStringContainsString('.head_branch == "main"', $resolver);
        $manifest = file_get_contents(base_path('.github/scripts/production-image-manifest.php'));
        $this->assertStringContainsString("const PRODUCTION_REF = 'refs/heads/main';", $manifest);
    }

    public function test_smart_ci_gate_scopes_verify_checks_without_affecting_main_production_pipeline(): void
    {
        $workflow = Yaml::parseFile(base_path('.github/workflows/docker-publish.yml'));

        $this->assertStringContainsString(
            "github.event_name == 'pull_request' || (github.event_name == 'push' && github.ref == 'refs/heads/develop')",
            (string) $workflow['concurrency']['cancel-in-progress']
        );
        $this->assertSame('${{ steps.scope.outputs.tier }}', $workflow['jobs']['verify']['outputs']['tier']);
        $this->assertSame(
            '${{ steps.scope.outputs.run_pr_image_build }}',
            $workflow['jobs']['verify']['outputs']['run_pr_image_build']
        );
        $this->assertSame('scope', $workflow['jobs']['verify']['steps'][2]['id']);
        $this->assertStringContainsString(
            'node .github/scripts/detect-ci-scope.mjs',
            $workflow['jobs']['verify']['steps'][2]['run']
        );
        $this->assertStringContainsString(
            "needs.verify.outputs.run_pr_image_build == 'true'",
            $workflow['jobs']['pr-image-build']['if']
        );
    }
}
