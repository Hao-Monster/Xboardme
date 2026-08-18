<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, "admin_asset_verification_failed: {$message}\n");
    exit(1);
}

function verifyAsset(string $adminRoot, mixed $relativePath, string $source): string
{
    if (!is_string($relativePath) || $relativePath === '') {
        fail("{$source} must reference a non-empty string");
    }

    $normalized = str_replace('\\', '/', $relativePath);
    if (str_starts_with($normalized, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1) {
        fail("{$source} contains an unsafe path: {$relativePath}");
    }

    $candidate = $adminRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_file($resolved)) {
        fail("{$source} is missing: {$relativePath}");
    }

    $rootPrefix = rtrim($adminRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($resolved, $rootPrefix)) {
        fail("{$source} resolves outside the admin asset directory: {$relativePath}");
    }

    $size = filesize($resolved);
    if ($size === false || $size === 0) {
        fail("{$source} is empty: {$relativePath}");
    }

    return $normalized;
}

$adminRoot = realpath(__DIR__ . '/../../public/assets/admin');
if ($adminRoot === false || !is_dir($adminRoot)) {
    fail('public/assets/admin is absent; initialize the Git submodule before building');
}

$manifestPath = $adminRoot . DIRECTORY_SEPARATOR . 'manifest.json';
if (!is_file($manifestPath) || filesize($manifestPath) === 0) {
    fail('manifest.json is absent or empty');
}

try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fail('manifest.json is invalid JSON: ' . $exception->getMessage());
}

if (!is_array($manifest) || !isset($manifest['index.html']) || !is_array($manifest['index.html'])) {
    fail('manifest.json does not contain the index.html entry');
}

$entry = $manifest['index.html'];
if (($entry['isEntry'] ?? false) !== true) {
    fail('manifest index.html is not marked as an entry');
}

$entryFile = verifyAsset($adminRoot, $entry['file'] ?? null, 'index.html.file');
if (!str_ends_with($entryFile, '.js')) {
    fail('manifest index.html entry is not JavaScript');
}

$verified = [$entryFile => true];
foreach ($manifest as $chunkName => $chunk) {
    if (!is_array($chunk)) {
        fail("manifest chunk {$chunkName} is not an object");
    }

    foreach (['file', 'css', 'assets'] as $field) {
        if (!array_key_exists($field, $chunk)) {
            continue;
        }

        $values = $field === 'file' ? [$chunk[$field]] : $chunk[$field];
        if (!is_array($values)) {
            fail("manifest {$chunkName}.{$field} is not an array");
        }

        foreach ($values as $index => $relativePath) {
            $asset = verifyAsset($adminRoot, $relativePath, "{$chunkName}.{$field}[{$index}]");
            $verified[$asset] = true;
        }
    }
}

foreach (['en-US.js', 'zh-CN.js'] as $locale) {
    $asset = verifyAsset($adminRoot, 'locales/' . $locale, "required locale {$locale}");
    $verified[$asset] = true;
}

fwrite(STDOUT, sprintf("Admin frontend assets verified: %d files.\n", count($verified)));
