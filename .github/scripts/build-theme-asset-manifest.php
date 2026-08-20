<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$revision = trim((string) ($argv[1] ?? ''));
$output = $argv[2] ?? $root . '/theme/Xboard/assets/release-manifest.json';
$assetRoot = $root . '/theme/Xboard/assets';
$requiredAssets = [
    'auth-session.js',
    'client-center.css',
    'client-center.js',
    'distributor-message-guard.js',
    'distributor.css',
    'distributor.js',
    'umi.js',
];

if ($revision !== 'local' && preg_match('/^[a-f0-9]{40}$/', $revision) !== 1) {
    throw new RuntimeException('Asset manifest revision must be "local" or a full lowercase commit SHA.');
}

$assets = [];
foreach ($requiredAssets as $asset) {
    $path = $assetRoot . '/' . $asset;
    if (!is_file($path) || filesize($path) === 0) {
        throw new RuntimeException("Required theme asset is missing or empty: {$asset}");
    }

    $assets[$asset] = [
        'sha256' => hash_file('sha256', $path),
        'bytes' => filesize($path),
    ];
}

$manifest = json_encode([
    'schema' => 1,
    'revision' => $revision,
    'assets' => $assets,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

$outputDirectory = dirname($output);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException("Unable to create asset manifest directory: {$outputDirectory}");
}

$temporary = $output . '.tmp-' . getmypid();
if (file_put_contents($temporary, $manifest, LOCK_EX) === false || !rename($temporary, $output)) {
    @unlink($temporary);
    throw new RuntimeException("Unable to write asset manifest: {$output}");
}

fwrite(STDOUT, "Theme asset manifest built for {$revision}." . PHP_EOL);
