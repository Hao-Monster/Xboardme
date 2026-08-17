<?php

namespace Tests\Feature;

use Illuminate\Session\Middleware\StartSession;
use ReflectionObject;
use Tests\TestCase;

class ZeroDowntimeReleaseSafetyTest extends TestCase
{
    public function test_supervisor_control_socket_is_unique_per_runtime_instance(): void
    {
        $entrypoint = file_get_contents(base_path('.docker/entrypoint.sh'));
        $supervisor = file_get_contents(base_path('.docker/supervisor/supervisord.conf'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString('SUPERVISOR_SOCKET_FILE:=/tmp/xboard-supervisor-${RUNTIME_INSTANCE_ID}.sock', $entrypoint);
        $this->assertStringContainsString('"${SUPERVISOR_SOCKET_FILE}"', $entrypoint);
        $this->assertStringContainsString('[unix_http_server]', $supervisor);
        $this->assertStringContainsString('file=/tmp/xboard-supervisor-%(ENV_RUNTIME_INSTANCE_ID)s.sock', $supervisor);
        $this->assertStringContainsString('[rpcinterface:supervisor]', $supervisor);
        $this->assertStringContainsString('[supervisorctl]', $supervisor);
        $this->assertStringContainsString('serverurl=unix:///tmp/xboard-supervisor-%(ENV_RUNTIME_INSTANCE_ID)s.sock', $supervisor);
        $this->assertStringContainsString('ENV RUNTIME_INSTANCE_ID=default', $dockerfile);
        $this->assertStringContainsString('COPY .docker/supervisor/supervisord.conf /etc/supervisord.conf', $dockerfile);
        $this->assertStringContainsString('CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]', $dockerfile);
        $this->assertStringNotContainsString('/etc/supervisor/conf.d/supervisord.conf', $dockerfile);
    }

    public function test_file_sessions_are_not_active_on_public_route_groups(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $reflection = new ReflectionObject($kernel);
        $property = $reflection->getProperty('middlewareGroups');
        $property->setAccessible(true);
        $groups = $property->getValue($kernel);

        $this->assertNotContains(StartSession::class, $groups['web']);
        $this->assertNotContains(StartSession::class, $groups['api']);
    }

    public function test_live_green_is_web_only_and_reuses_authoritative_state(): void
    {
        $script = file_get_contents(base_path('.github/scripts/prepare-xboard-live-green.sh'));

        $this->assertStringContainsString('stage_image_is_not_an_immutable_ghcr_digest', $script);
        $this->assertStringContainsString('stage_image_revision_mismatch', $script);
        $this->assertStringContainsString('--volumes-from "$blue"', $script);
        $this->assertStringContainsString('-e ENABLE_HORIZON=false', $script);
        $this->assertStringContainsString('-e ENABLE_REDIS=false', $script);
        $this->assertStringContainsString('-e ENABLE_SCHEDULER=false', $script);
        $this->assertStringContainsString('supervisorctl status 2>&1 || true', $script);
        $this->assertStringContainsString('^redis:redis_00[[:space:]]+STOPPED', $script);
        $this->assertStringContainsString('^horizon:horizon_00[[:space:]]+STOPPED', $script);
        $this->assertStringContainsString('for proc in /proc/[0-9]*', $script);
        $this->assertStringContainsString('[ "$executable" = redis-server ]', $script);
        $this->assertStringContainsString('horizon|horizon:work)', $script);
        $this->assertStringNotContainsString('pgrep -f', $script);
        $this->assertStringContainsString('PRAGMA integrity_check;', $script);
        $this->assertStringContainsString('redis-cli -s /data/redis.sock BGSAVE', $script);
    }

    public function test_cutover_validates_caddy_and_has_an_exact_rollback_artifact(): void
    {
        $switch = file_get_contents(base_path('.github/scripts/switch-xboard-live-green.sh'));
        $rollback = file_get_contents(base_path('.github/scripts/rollback-xboard-live-green.sh'));

        $this->assertStringContainsString('caddy-before-switch.conf', $switch);
        $this->assertStringContainsString('caddy validate --config "$candidate" --adapter caddyfile', $switch);
        $this->assertTrue(
            strpos($switch, 'set_state CADDY_BACKUP "$caddy_backup"') < strpos($switch, 'mv -f -- "$candidate" "$proxy_file"')
        );
        $this->assertStringContainsString('for public_attempt in {1..3}', $switch);
        $this->assertStringContainsString('chmod 0644 "$proxy_file"', $switch);
        $this->assertStringContainsString("systemctl reload caddy", $switch);
        $this->assertStringContainsString('caddy_backup=${CADDY_BACKUP:-$workdir/.codex-release/', $rollback);
        $this->assertStringContainsString('cp -p -- "$caddy_backup" "$caddy_config"', $rollback);
        $this->assertStringContainsString('chmod 0644 "$caddy_config"', $rollback);
        $this->assertStringContainsString('grep -o \'127\\.0\\.0\\.1:7001\' "$caddy_config"', $rollback);
        $this->assertStringNotContainsString("grep -Rho '127\\.0\\.0\\.1:7001' /etc/caddy", $rollback);
        $this->assertStringContainsString('for attempt in {1..12}', $rollback);
        $this->assertStringContainsString('SIGCONT', $rollback);
        $this->assertStringContainsString('php /www/artisan horizon:continue', $rollback);
        $this->assertStringContainsString("RELEASE_ROLLBACK=PASS", $rollback);
    }

    public function test_role_transfer_never_starts_a_second_redis_owner(): void
    {
        $script = file_get_contents(base_path('.github/scripts/activate-xboard-green-roles.sh'));

        $this->assertStringContainsString('-e ENABLE_REDIS=false', $script);
        $this->assertStringContainsString('php /www/artisan horizon:pause', $script);
        $this->assertStringContainsString('php /www/artisan horizon:continue', $script);
        $this->assertStringContainsString('horizon_ready_samples >= 3', $script);
        $this->assertStringNotContainsString('php /www/artisan horizon:status', $script);
        $this->assertStringContainsString('SIGSTOP', $script);
        $this->assertStringContainsString('BLUE_OCTANE_PGID', $script);
        $this->assertStringNotContainsString('supervisorctl stop horizon', $script);
        $this->assertStringNotContainsString('supervisorctl stop octane', $script);
        $this->assertStringContainsString('php /www/artisan schedule:work', $script);
    }
}
