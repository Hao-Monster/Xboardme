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
}
