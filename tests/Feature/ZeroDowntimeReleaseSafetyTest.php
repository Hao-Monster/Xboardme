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
        $this->assertStringContainsString('systemctl is-active caddy', $switch);
        $this->assertStringContainsString('external_smoke_required', $switch);
        $this->assertStringNotContainsString('journalctl', $switch);
        $this->assertStringContainsString('chmod 0644 "$proxy_file"', $switch);
        $this->assertStringContainsString("systemctl reload caddy", $switch);
        $this->assertStringContainsString('caddy_backup=${CADDY_BACKUP:-$workdir/.codex-release/', $rollback);
        $this->assertStringContainsString('cp -p -- "$caddy_backup" "$caddy_config"', $rollback);
        $this->assertStringContainsString('chmod 0644 "$caddy_config"', $rollback);
        $this->assertStringContainsString('grep -o \'127\\.0\\.0\\.1:7001\' "$caddy_config"', $rollback);
        $this->assertStringNotContainsString("grep -Rho '127\\.0\\.0\\.1:7001' /etc/caddy", $rollback);
        $this->assertStringContainsString('systemctl is-active caddy', $rollback);
        $this->assertStringContainsString('external_smoke_required', $rollback);
        $this->assertStringNotContainsString('journalctl', $rollback);
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

    public function test_smoke_test_checks_the_public_route_from_the_runner(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/distributor-smoke.yml'));

        $resolver = file_get_contents(base_path('.github/scripts/resolve-xboard-public-url.sh'));

        $this->assertStringContainsString('< .github/scripts/resolve-xboard-public-url.sh', $workflow);
        $this->assertStringContainsString('--output /dev/null "$public_url/"', $workflow);
        $this->assertStringContainsString('test "$public_ready" = \'1\'', $workflow);
        $this->assertStringContainsString('caddy adapt --config', $resolver);
        $this->assertStringContainsString('tls_connection_policies', $resolver);
        $this->assertStringContainsString('ambiguous_caddy_origin', $resolver);
        $this->assertStringContainsString('bash -n .github/scripts/resolve-xboard-public-url.sh', file_get_contents(base_path('.github/workflows/docker-publish.yml')));
    }

    public function test_admin_frontend_submodule_and_assets_are_release_gates(): void
    {
        $publishWorkflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $smokeWorkflow = file_get_contents(base_path('.github/workflows/distributor-smoke.yml'));
        $adminAssetSmoke = file_get_contents(base_path('.github/scripts/smoke-admin-assets.sh'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $matched = preg_match(
            '/^  build:\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:\R|\z)/ms',
            $publishWorkflow,
            $matches
        );

        $this->assertSame(1, $matched, 'The image build job must remain discoverable.');
        $this->assertStringContainsString('submodules: recursive', $matches['job']);
        $this->assertStringContainsString('php .github/scripts/verify-admin-assets.php', $matches['job']);
        $this->assertStringContainsString('RUN php .github/scripts/verify-admin-assets.php', $dockerfile);
        $this->assertStringContainsString('bash .github/scripts/smoke-admin-assets.sh', $smokeWorkflow);
        $this->assertStringContainsString('bash -n .github/scripts/smoke-admin-assets.sh', $publishWorkflow);
        $this->assertStringContainsString('/assets/admin/manifest.json', $adminAssetSmoke);
        $this->assertStringContainsString('entry_asset=$(jq -er', $adminAssetSmoke);
        $this->assertStringContainsString('/assets/admin/$entry_asset', $adminAssetSmoke);
        $this->assertStringContainsString('for locale in en-US zh-CN', $adminAssetSmoke);
    }

    public function test_admin_asset_hotfix_is_scoped_verified_and_recoverable(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $deploy = file_get_contents(base_path('.github/scripts/deploy-admin-assets-hotfix.sh'));
        $rollback = file_get_contents(base_path('.github/scripts/rollback-admin-assets-hotfix.sh'));
        $remoteSmoke = file_get_contents(base_path('.github/scripts/smoke-admin-assets-remote.sh'));

        $this->assertStringContainsString("inputs.production_release_action == 'admin_assets'", $workflow);
        $this->assertMatchesRegularExpression(
            '/verify:\R\s+if:.*?production_release_action == \'admin_assets\'/s',
            $workflow
        );
        $this->assertStringContainsString('needs: deploy-admin-assets-hotfix', $workflow);
        $this->assertStringContainsString('TARGET_PORT: 7002', $workflow);
        $this->assertStringContainsString('run: bash .github/scripts/smoke-admin-assets-remote.sh', $workflow);
        $this->assertStringContainsString('-L "17001:127.0.0.1:$TARGET_PORT"', $remoteSmoke);
        $this->assertStringContainsString('bash .github/scripts/smoke-admin-assets.sh', $remoteSmoke);
        $this->assertStringContainsString('bash -n .github/scripts/smoke-admin-assets-remote.sh', $workflow);
        $this->assertStringContainsString("inputs.production_release_action == 'admin_assets_rollback'", $workflow);
        $this->assertStringContainsString('docker exec "$stage" php /www/.github/scripts/verify-admin-assets.php', $deploy);
        $this->assertStringContainsString('stage_image_revision_mismatch', $deploy);
        $this->assertStringContainsString('validator_payload="$hotfix_dir/verify-admin-assets.php"', $deploy);
        $this->assertStringContainsString('docker cp "$validator_payload" "$active:$validator"', $deploy);
        $this->assertStringNotContainsString(
            'docker cp "$stage:/www/.github/scripts/verify-admin-assets.php" "$active:$validator"',
            $deploy
        );
        $this->assertStringContainsString('active_web_not_on_expected_port', $deploy);
        $this->assertStringContainsString('active_caddy_route_ambiguous', $deploy);
        $this->assertStringContainsString('.admin-candidate-$HOTFIX_ID', $deploy);
        $this->assertStringContainsString('.admin-before-$HOTFIX_ID', $deploy);
        $this->assertStringContainsString('restore_on_error', $deploy);
        $this->assertStringContainsString('wget -q -O /dev/null http://127.0.0.1:7001/assets/admin/manifest.json', $deploy);
        $this->assertStringContainsString('PREVIOUS_EXISTS', $rollback);
        $this->assertStringContainsString('.admin-rolled-back-$HOTFIX_ID', $rollback);
    }

    public function test_isolated_stage_port_can_avoid_the_active_green_slot(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $stage = file_get_contents(base_path('.github/scripts/stage-xboard-green.sh'));

        $this->assertStringContainsString('stage_target_port:', $workflow);
        $this->assertStringContainsString('STAGE_PORT: ${{ inputs.stage_target_port }}', $workflow);
        $this->assertStringContainsString('TARGET_PORT: ${{ inputs.stage_target_port }}', $workflow);
        $this->assertMatchesRegularExpression(
            '/smoke-staged-distributor:\R\s+needs: stage-distributor-green\R\s+runs-on:/',
            $workflow
        );
        $this->assertStringContainsString("needs.smoke-staged-distributor.result == 'failure'", $workflow);
        $this->assertStringNotContainsString("needs.smoke-staged-distributor.result != 'success'", $workflow);
        $this->assertStringContainsString(': "${STAGE_PORT:=7002}"', $stage);
        $this->assertStringContainsString('STAGE_FAIL=invalid_stage_port', $stage);
        $this->assertStringContainsString('"127.0.0.1:$STAGE_PORT:7001"', $stage);
        $this->assertStringContainsString("'127\\.0\\.0\\.1:(7001|7002)'", $stage);
        $this->assertStringContainsString('((${#proxy_files[@]} != 1 || proxy_references != 1))', $stage);
    }

    public function test_failed_external_green_smoke_automatically_restores_and_verifies_blue(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));

        $this->assertStringContainsString('auto-rollback-failed-switch:', $workflow);
        $this->assertStringContainsString("needs.smoke-switched-green.result != 'success'", $workflow);
        $this->assertStringContainsString('Restore blue after failed external green smoke', $workflow);
        $this->assertStringContainsString('smoke-auto-rolled-back-blue:', $workflow);
        $this->assertStringContainsString('needs.auto-rollback-failed-switch.result == \'success\'', $workflow);
        $this->assertStringContainsString('target_port: 7001', $workflow);
    }

    public function test_release_cleanup_requires_the_exact_active_blue_caddy_config(): void
    {
        $script = file_get_contents(base_path('.github/scripts/cleanup-xboard-live-release.sh'));

        $this->assertStringContainsString("grep -RIlE --include='*.conf' --include='Caddyfile'", $script);
        $this->assertStringContainsString('ambiguous_caddy_file', $script);
        $this->assertStringContainsString('caddy validate --config "$caddy_config"', $script);
        $this->assertStringContainsString("grep -o '127\\.0\\.0\\.1:7001' \"\$caddy_config\"", $script);
        $this->assertStringNotContainsString("grep -Rho '127\\.0\\.0\\.1:7001'", $script);
    }
}
