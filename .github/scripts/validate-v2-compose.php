<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "V2_COMPOSE_FAIL={$message}\n");
    exit(1);
}

if ($argc < 3 || $argc > 5) {
    fail('usage');
}

$payload = file_get_contents($argv[1]);
$config = is_string($payload) ? json_decode($payload, true) : null;
if (!is_array($config)) {
    fail('invalid_json');
}

$services = $config['services'] ?? null;
if (!is_array($services)) {
    fail('services_missing');
}

$expectedServices = ['edge', 'horizon', 'maintenance', 'redis', 'scheduler', 'web', 'ws'];
$actualServices = array_keys($services);
sort($actualServices);
if ($actualServices !== $expectedServices) {
    fail('unexpected_services');
}

$expectedImage = $argv[2];
$expectedPort = isset($argv[3]) ? (int) $argv[3] : 17003;
$mode = $argv[4] ?? 'sample';
if (!preg_match('/\A[^\s@]+@sha256:[0-9a-f]{64}\z/', $expectedImage)) {
    fail('application_image_not_immutable');
}
if ($expectedPort < 1 || $expectedPort > 65535) {
    fail('invalid_expected_port');
}
if (!in_array($mode, ['sample', 'production'], true)) {
    fail('invalid_mode');
}

$roles = ['web', 'ws', 'horizon', 'scheduler', 'maintenance'];
foreach ($roles as $role) {
    $service = $services[$role];
    if (($service['image'] ?? null) !== $expectedImage) {
        fail("{$role}_image_mismatch");
    }
    if (($service['environment']['XBOARD_RUNTIME_ROLE'] ?? null) !== $role) {
        fail("{$role}_role_mismatch");
    }
    if (!str_ends_with((string) ($service['environment']['RUNTIME_INSTANCE_ID'] ?? ''), "-{$role}")) {
        fail("{$role}_instance_missing");
    }
    if (empty($service['mem_limit']) || empty($service['cpus']) || empty($service['pids_limit'])) {
        fail("{$role}_resource_limit_missing");
    }
    if (!in_array('no-new-privileges:true', $service['security_opt'] ?? [], true)) {
        fail("{$role}_security_option_missing");
    }
    if (!empty($service['ports'])) {
        fail("{$role}_must_not_publish_ports");
    }
}

$edgePorts = $services['edge']['ports'] ?? [];
if (count($edgePorts) !== 1
    || (int) ($edgePorts[0]['target'] ?? 0) !== 7001
    || (int) ($edgePorts[0]['published'] ?? 0) !== $expectedPort
    || (string) ($edgePorts[0]['host_ip'] ?? '') !== '127.0.0.1') {
    fail('edge_not_loopback_only');
}

if (!empty($services['redis']['ports'])) {
    fail('redis_port_published');
}
if (array_keys($services['redis']['networks'] ?? []) !== ['backplane']) {
    fail('redis_network_scope');
}
if (($config['networks']['backplane']['internal'] ?? false) !== true
    || ($config['networks']['edge']['internal'] ?? false) !== true) {
    fail('internal_network_missing');
}

foreach (['edge', 'redis'] as $serviceName) {
    if (!preg_match('/@sha256:[0-9a-f]{64}\z/', (string) ($services[$serviceName]['image'] ?? ''))) {
        fail("{$serviceName}_image_not_pinned");
    }
}

foreach (['web', 'ws', 'horizon', 'scheduler'] as $role) {
    if (empty($services[$role]['healthcheck']['test'])) {
        fail("{$role}_healthcheck_missing");
    }
}
if (empty($services['redis']['healthcheck']['test'])) {
    fail('redis_healthcheck_missing');
}

if ($mode === 'production') {
    if (($services['redis']['environment']['XBOARD_REDIS_APPENDONLY'] ?? null) !== 'no') {
        fail('redis_rollback_compatibility_mode');
    }
    foreach ($roles as $role) {
        $volumes = $services[$role]['volumes'] ?? [];
        $targets = array_column($volumes, 'target');
        sort($targets);
        $expectedTargets = [
            '/www/.env',
            '/www/.docker/.data',
            '/www/storage/logs',
            '/www/storage/theme',
            '/www/storage/app/knowledge-attachments',
            '/www/plugins',
        ];
        sort($expectedTargets);
        if ($targets !== $expectedTargets) {
            fail("{$role}_authoritative_mounts");
        }
        foreach ($volumes as $volume) {
            if (($volume['type'] ?? null) !== 'bind') {
                fail("{$role}_non_bind_mount");
            }
        }
    }
}

echo "V2_COMPOSE=PASS services=" . count($services) . "\n";
