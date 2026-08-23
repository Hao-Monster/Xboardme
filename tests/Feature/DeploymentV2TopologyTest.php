<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DeploymentV2TopologyTest extends TestCase
{
    private array $topology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->topology = Yaml::parseFile(base_path('compose.v2.sample.yaml'));
    }

    public function test_topology_is_opt_in_and_uses_single_purpose_roles(): void
    {
        $services = $this->topology['services'];
        $expected = ['edge', 'web', 'ws', 'horizon', 'scheduler', 'maintenance', 'redis'];

        $this->assertSame($expected, array_keys($services));
        foreach (['web', 'ws', 'horizon', 'scheduler'] as $role) {
            $this->assertSame('${XBOARD_IMAGE:?XBOARD_IMAGE must be an immutable image digest}', $services[$role]['image']);
            $this->assertSame($role, $services[$role]['environment']['XBOARD_RUNTIME_ROLE']);
            $this->assertSame(['/run-role.sh'], $services[$role]['command']);
            $this->assertNotEmpty($services[$role]['healthcheck']['test']);
        }

        $this->assertSame(['owners'], $services['horizon']['profiles']);
        $this->assertSame(['owners'], $services['scheduler']['profiles']);
        $this->assertSame(['maintenance'], $services['maintenance']['profiles']);
        $this->assertSame(['/entrypoint.sh', '/run-role.sh'], $services['maintenance']['entrypoint']);
    }

    public function test_only_edge_publishes_a_loopback_port_and_redis_is_private(): void
    {
        $services = $this->topology['services'];

        $this->assertCount(1, $services['edge']['ports']);
        $this->assertSame('127.0.0.1', $services['edge']['ports'][0]['host_ip']);
        $this->assertSame('${XBOARD_HTTP_PORT:?XBOARD_HTTP_PORT is required}', $services['edge']['ports'][0]['published']);
        foreach (['web', 'ws', 'horizon', 'scheduler', 'maintenance', 'redis'] as $service) {
            $this->assertArrayNotHasKey('ports', $services[$service]);
        }

        $this->assertSame(['backplane'], $services['redis']['networks']);
        $this->assertTrue($services['redis']['read_only']);
        $this->assertContains('/tmp:size=16m,mode=1777', $services['redis']['tmpfs']);
        $this->assertTrue($this->topology['networks']['edge']['internal']);
        $this->assertTrue($this->topology['networks']['backplane']['internal']);
    }

    public function test_all_long_running_services_have_resource_and_process_limits(): void
    {
        foreach (['edge', 'web', 'ws', 'horizon', 'scheduler', 'redis'] as $serviceName) {
            $service = $this->topology['services'][$serviceName];
            $this->assertArrayHasKey('mem_limit', $service, $serviceName);
            $this->assertArrayHasKey('cpus', $service, $serviceName);
            $this->assertArrayHasKey('pids_limit', $service, $serviceName);
            $this->assertContains('no-new-privileges:true', $service['security_opt']);
        }
    }

    public function test_infrastructure_images_are_pinned_and_redis_secret_is_not_inline(): void
    {
        $services = $this->topology['services'];

        $this->assertMatchesRegularExpression('/caddy:[^@]+@sha256:[0-9a-f]{64}$/', $services['edge']['image']);
        $this->assertMatchesRegularExpression('/redis:[^@]+@sha256:[0-9a-f]{64}$/', $services['redis']['image']);
        $this->assertSame(
            '${XBOARD_REDIS_PASSWORD_FILE:?XBOARD_REDIS_PASSWORD_FILE is required}',
            $this->topology['secrets']['redis_password']['file']
        );
        $this->assertSame('', $services['web']['environment']['REDIS_PASSWORD']);
        $this->assertSame('', $services['web']['environment']['REDIS_URL']);
        $this->assertSame('', $services['web']['environment']['REDIS_USERNAME_FILE']);
        $this->assertSame('/run/secrets/xboard_redis_password', $services['web']['environment']['REDIS_PASSWORD_FILE']);
        $this->assertSame('stderr', $services['web']['environment']['LOG_CHANNEL']);
        $this->assertSame('stderr', $services['web']['environment']['LOG_DEPRECATIONS_CHANNEL']);
        $this->assertSame('xboard_redis_password', $services['web']['secrets'][0]['target']);
        $this->assertArrayNotHasKey('uid', $services['web']['secrets'][0]);
        $this->assertArrayNotHasKey('gid', $services['web']['secrets'][0]);
        $this->assertArrayNotHasKey('mode', $services['web']['secrets'][0]);
    }
}
