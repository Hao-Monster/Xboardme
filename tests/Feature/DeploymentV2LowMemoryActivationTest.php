<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DeploymentV2LowMemoryActivationTest extends TestCase
{
    private const APP_ROLES = ['web', 'ws', 'horizon', 'scheduler', 'maintenance'];

    public function test_production_overlay_reuses_authoritative_bind_mounts_and_redis_volume(): void
    {
        $path = base_path('compose.v2.production.yaml');

        $this->assertFileExists($path);
        $overlay = Yaml::parseFile($path);

        foreach (self::APP_ROLES as $role) {
            $volumes = $overlay['services'][$role]['volumes'] ?? [];
            $targets = array_column($volumes, 'target');

            $this->assertSame([
                '/www/.env',
                '/www/.docker/.data',
                '/www/storage/logs',
                '/www/storage/theme',
                '/www/storage/app/knowledge-attachments',
                '/www/plugins',
            ], $targets, $role);
            foreach ($volumes as $volume) {
                $this->assertSame('bind', $volume['type'] ?? null, $role);
            }
            $this->assertTrue($volumes[0]['read_only'] ?? false, $role);
        }

        $this->assertTrue($overlay['volumes']['redis_data']['external'] ?? false);
        $this->assertSame(
            '${XBOARD_REDIS_VOLUME_NAME:?XBOARD_REDIS_VOLUME_NAME is required}',
            $overlay['volumes']['redis_data']['name'] ?? null
        );
        $this->assertSame('no', $overlay['services']['redis']['environment']['XBOARD_REDIS_APPENDONLY'] ?? null);
    }

    public function test_redis_persistence_mode_is_explicit_and_defaults_to_aof_outside_compatibility_overlay(): void
    {
        $compose = file_get_contents(base_path('compose.v2.sample.yaml'));
        $runner = file_get_contents(base_path('.docker/redis/run-v2-redis.sh'));

        $this->assertStringContainsString('XBOARD_REDIS_APPENDONLY: ${XBOARD_REDIS_APPENDONLY:-yes}', $compose);
        $this->assertStringContainsString('appendonly=${XBOARD_REDIS_APPENDONLY:-yes}', $runner);
        $this->assertStringContainsString('case "$appendonly" in', $runner);
        $this->assertStringContainsString('"appendonly $appendonly"', $runner);
    }

    public function test_low_memory_cutover_has_explicit_locked_state_machine_and_maintenance_page(): void
    {
        $files = [
            '.github/scripts/v2-low-memory-common.sh',
            '.github/scripts/resolve-xboard-v2-port.sh',
            '.github/scripts/prepare-xboard-v2-low-memory.sh',
            '.github/scripts/start-xboard-v2-low-memory.sh',
            '.github/scripts/switch-xboard-v2-low-memory.sh',
            '.github/scripts/rollback-xboard-v2-low-memory.sh',
            '.github/scripts/finalize-xboard-v2-low-memory.sh',
            '.docker/caddy/Caddyfile.maintenance',
        ];

        foreach ($files as $file) {
            $this->assertFileExists(base_path($file), $file);
        }

        $common = file_get_contents(base_path($files[0]));
        $resolvePort = file_get_contents(base_path($files[1]));
        $prepare = file_get_contents(base_path($files[2]));
        $start = file_get_contents(base_path($files[3]));
        $switch = file_get_contents(base_path($files[4]));
        $rollback = file_get_contents(base_path($files[5]));
        $finalize = file_get_contents(base_path($files[6]));
        $maintenance = file_get_contents(base_path($files[7]));

        $this->assertStringContainsString('flock -n', $common);
        $this->assertStringContainsString('.codex-v2-release', $common);
        $this->assertStringContainsString('release_state_open', $common);
        $this->assertStringContainsString('release_state_schema_mismatch', $common);
        $this->assertStringNotContainsString('source "$state_file"', $common);
        $this->assertStringContainsString('v2_open_release', $resolvePort);
        $this->assertStringContainsString("printf '%s\\n' \"\$ACTIVE_PORT\"", $resolvePort);

        $this->assertStringContainsString('release_state_create', $prepare);
        $this->assertStringContainsString('schema_version "$V2_RELEASE_STATE_SCHEMA"', $prepare);
        $this->assertStringContainsString('traffic_state prepared', $prepare);
        $this->assertStringContainsString('XBOARD_REDIS_APPENDONLY=no', $prepare);
        $this->assertStringContainsString('.compose-validation.', $prepare);
        $this->assertStringNotContainsString('$release_dir/compose.json', $prepare);
        $this->assertStringNotContainsString('docker stop "$legacy_', $prepare);
        $this->assertStringNotContainsString('systemctl reload caddy', $prepare);
        $this->assertStringContainsString('--entrypoint redis-check-rdb', $prepare);
        $this->assertStringContainsString('rdb_compatibility_verified true', $prepare);
        $this->assertStringContainsString('--network none', $prepare);
        $this->assertStringContainsString('chown 0:1000 "$redis_password_file"', $prepare);
        $this->assertStringContainsString('--user 1000:1000', $prepare);
        $this->assertStringContainsString('redis_secret_permissions', $common);
        $this->assertStringContainsString('"$legacy_horizon_id" "$legacy_scheduler_id"', $prepare);

        $maintenanceSwitch = strpos($start, 'v2_replace_caddy_upstream "$ACTIVE_PORT" "$MAINTENANCE_PORT"');
        $legacyStop = strpos($start, 'v2_stop_legacy_runtime');
        $v2Start = strpos($start, 'v2_compose up --detach --wait --wait-timeout 120 redis web ws edge');
        $this->assertNotFalse($maintenanceSwitch);
        $this->assertNotFalse($legacyStop);
        $this->assertNotFalse($v2Start);
        $this->assertLessThan($legacyStop, $maintenanceSwitch);
        $this->assertLessThan($v2Start, $legacyStop);
        $this->assertStringContainsString('scheduler_reaper_observation_seconds=${SCHEDULER_REAPER_OBSERVATION_SECONDS:-185}', $start);
        $this->assertSame(2, substr_count($start, '--wait-timeout 120'));
        $this->assertStringContainsString('V2_START=PASS', $start);
        $this->assertStringContainsString('v2_legacy_reserved_jobs', $common);
        $this->assertStringContainsString('v2_reserved_jobs "$horizon_id"', $common);
        $this->assertStringContainsString('rollback_jobs_not_drained', $common);
        $this->assertStringContainsString('legacy_jobs_did_not_drain', $common);
        $this->assertStringContainsString('legacy_runtime_did_not_stop', $common);
        $this->assertStringContainsString('No Horizon queues were configured.', $common);
        $this->assertStringContainsString('insufficient_available_memory', $start);

        $this->assertStringContainsString('[[ "$TRAFFIC_STATE" == ready ]]', $switch);
        $this->assertStringContainsString('traffic_state active_v2', $switch);
        $this->assertStringContainsString('external_smoke_required', $switch);

        $rollbackStart = strpos($common, 'v2_rollback_runtime()');
        $this->assertNotFalse($rollbackStart);
        $rollbackImplementation = substr($common, $rollbackStart) . $rollback;
        $save = strpos($rollbackImplementation, 'v2_redis_save');
        $redisStop = strpos($rollbackImplementation, 'v2_compose stop redis');
        $legacyStart = strpos($rollbackImplementation, 'v2_start_legacy_runtime');
        $restoreCaddy = strpos($rollbackImplementation, 'v2_restore_caddy_backup');
        $this->assertNotFalse($save);
        $this->assertNotFalse($redisStop);
        $this->assertNotFalse($legacyStart);
        $this->assertNotFalse($restoreCaddy);
        $this->assertLessThan($redisStop, $save);
        $this->assertLessThan($legacyStart, $redisStop);
        $this->assertLessThan($restoreCaddy, $legacyStart);
        $this->assertStringContainsString('external_smoke_required', $rollbackImplementation);

        $this->assertStringContainsString('V2_FINALIZE_MIN_AGE_SECONDS:=86400', $finalize);
        $this->assertStringContainsString('traffic_state active_v2', $finalize);
        $this->assertStringNotContainsString('docker volume rm', $finalize);

        $this->assertStringContainsString('respond /health 200', $maintenance);
        $this->assertMatchesRegularExpression('/respond\s+"[^"]+"\s+503/', $maintenance);
        $this->assertStringContainsString('Retry-After', $maintenance);
    }

    public function test_workflow_exposes_guarded_v2_phases_and_auto_rollback(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $parsedWorkflow = Yaml::parseFile(base_path('.github/workflows/docker-publish.yml'));
        $smoke = file_get_contents(base_path('.github/scripts/smoke-distributor-remote.sh'));

        foreach (['v2_prepare', 'v2_start', 'v2_switch', 'v2_rollback', 'v2_finalize'] as $action) {
            $this->assertStringContainsString("inputs.production_release_action == '{$action}'", $workflow, $action);
        }
        foreach ([
            'prepare-xboard-v2-low-memory.sh',
            'start-xboard-v2-low-memory.sh',
            'switch-xboard-v2-low-memory.sh',
            'rollback-xboard-v2-low-memory.sh',
            'finalize-xboard-v2-low-memory.sh',
        ] as $script) {
            $this->assertStringContainsString($script, $workflow, $script);
        }

        $this->assertStringContainsString('auto-rollback-failed-v2-switch:', $workflow);
        $this->assertStringContainsString("needs.smoke-switched-v2.result != 'success'", $workflow);
        $this->assertStringContainsString('SMOKE_VERIFY_PUBLIC_ROUTE', $smoke);
        $this->assertStringContainsString('if [[ "$SMOKE_VERIFY_PUBLIC_ROUTE" == true ]]', $smoke);
        $this->assertStringContainsString('resolve-xboard-v2-port.sh', $smoke);
        $this->assertStringContainsString('git merge-base --is-ancestor', $smoke);
        $this->assertStringContainsString('git show "$V2_EXPECTED_ASSET_VERSION:theme/Xboard/assets/$asset"', $smoke);
        $this->assertSame(4, substr_count($workflow, 'v2_release_id: ${{ inputs.release_id }}'));
        $this->assertGreaterThanOrEqual(4, substr_count($workflow, 'fetch-depth: 0'));

        foreach ([
            'prepare-v2-low-memory',
            'start-v2-low-memory',
            'smoke-started-v2',
            'auto-rollback-failed-v2-start',
            'pre-switch-v2-smoke',
            'switch-v2-low-memory',
            'smoke-switched-v2',
            'auto-rollback-failed-v2-switch',
            'smoke-auto-rolled-back-v2',
            'rollback-v2-low-memory',
            'smoke-manual-v2-rollback',
            'pre-finalize-v2-smoke',
            'finalize-v2-low-memory',
        ] as $job) {
            $condition = $parsedWorkflow['jobs'][$job]['if'] ?? '';
            $this->assertStringContainsString(
                "github.ref == 'refs/heads/codex/distributor'",
                $condition,
                $job
            );
        }
    }
}
