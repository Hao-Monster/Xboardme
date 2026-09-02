<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expectedRevision = trim((string) ($argv[1] ?? ''));
$manifestPath = $argv[2] ?? $root . '/theme/Xboard/assets/release-manifest.json';
$assetRoot = $root . '/theme/Xboard/assets';
$dashboardPath = $root . '/theme/Xboard/dashboard.blade.php';
$configPath = $root . '/config/app.php';
$requiredAssets = [
    'auth-session.js',
    'client-center.css',
    'client-center.js',
    'distributor-message-guard.js',
    'distributor.css',
    'distributor.js',
    'umi.js',
];

if ($expectedRevision !== 'local' && preg_match('/^[a-f0-9]{40}$/', $expectedRevision) !== 1) {
    throw new RuntimeException('Expected asset revision must be "local" or a full lowercase commit SHA.');
}
if (!is_file($manifestPath)) {
    throw new RuntimeException("Theme asset manifest is missing: {$manifestPath}");
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['schema'] ?? null) !== 1 || ($manifest['revision'] ?? null) !== $expectedRevision) {
    throw new RuntimeException('Theme asset manifest schema or revision does not match the release.');
}

foreach ($requiredAssets as $asset) {
    $path = $assetRoot . '/' . $asset;
    $entry = $manifest['assets'][$asset] ?? null;
    if (!is_file($path) || !is_array($entry)) {
        throw new RuntimeException("Required theme asset is absent from the release: {$asset}");
    }

    $actualHash = hash_file('sha256', $path);
    $actualBytes = filesize($path);
    if (!is_string($entry['sha256'] ?? null) || !hash_equals($entry['sha256'], $actualHash)) {
        throw new RuntimeException("Theme asset digest mismatch: {$asset}");
    }
    if (($entry['bytes'] ?? null) !== $actualBytes) {
        throw new RuntimeException("Theme asset size mismatch: {$asset}");
    }
}

$dashboard = (string) file_get_contents($dashboardPath);
foreach ($requiredAssets as $asset) {
    $reference = "/theme/{{\$theme}}/assets/{$asset}?v={{\$version}}";
    if (!str_contains($dashboard, $reference)) {
        throw new RuntimeException("Dashboard does not use the release version for {$asset}.");
    }
}
if (!str_contains($dashboard, '<meta name="xboard-release" content="{{$version}}"')) {
    throw new RuntimeException('Dashboard release identity marker is missing.');
}

$distributorJavaScript = (string) file_get_contents($assetRoot . '/distributor.js');
$distributorCss = (string) file_get_contents($assetRoot . '/distributor.css');
foreach (['dist-orders-table', 'dist-order-identity', 'data-subscription-qr', 'data-entitlement-toggle', 'data-renew'] as $marker) {
    if (!str_contains($distributorJavaScript, $marker)) {
        throw new RuntimeException("Distributor mobile order marker is missing: {$marker}");
    }
}
foreach (['min-width:1517px', 'touch-action:pan-x pan-y', '.dist-entitlement-row[hidden] { display:none!important; }'] as $marker) {
    if (!str_contains($distributorCss, $marker)) {
        throw new RuntimeException("Distributor mobile CSS marker is missing: {$marker}");
    }
}

if ($expectedRevision !== 'local') {
    $config = (string) file_get_contents($configPath);
    if (preg_match("/'version'\\s*=>\\s*'([^']+)'/", $config, $matches) !== 1 || $matches[1] !== $expectedRevision) {
        throw new RuntimeException('Application asset version does not match the image revision.');
    }
}

fwrite(STDOUT, sprintf("Theme assets verified: %d files for %s.%s", count($requiredAssets), $expectedRevision, PHP_EOL));
