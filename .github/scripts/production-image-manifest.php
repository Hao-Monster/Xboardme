<?php

declare(strict_types=1);

const PRODUCTION_IMAGE_MANIFEST_SCHEMA = 1;
const PRODUCTION_IMAGE_PLATFORM = 'linux/amd64';
const PRODUCTION_IMAGE_WORKFLOW = '.github/workflows/docker-publish.yml';
const PRODUCTION_REF = 'refs/heads/codex/distributor';

function fail(string $message): never
{
    fwrite(STDERR, "PRODUCTION_IMAGE_MANIFEST_FAIL={$message}\n");
    exit(1);
}

function requireMatch(string $value, string $pattern, string $field): string
{
    if (preg_match($pattern, $value) !== 1) {
        fail("invalid_{$field}");
    }

    return $value;
}

function requirePositiveInteger(string $value, string $field): int
{
    if (preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        fail("invalid_{$field}");
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (! is_int($integer)) {
        fail("invalid_{$field}");
    }

    return $integer;
}

function expectedManifest(array $arguments): array
{
    [$repository, $sha, $image, $digest, $runId] = $arguments;

    $repository = requireMatch($repository, '/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', 'repository');
    $sha = requireMatch($sha, '/\A[0-9a-f]{40}\z/', 'sha');
    $image = requireMatch($image, '/\Aghcr\.io\/[a-z0-9._\/-]+\z/', 'image');
    $digest = requireMatch($digest, '/\Asha256:[0-9a-f]{64}\z/', 'digest');
    if ($image !== 'ghcr.io/' . strtolower($repository)) {
        fail('image_repository_mismatch');
    }

    return [
        'schema' => PRODUCTION_IMAGE_MANIFEST_SCHEMA,
        'repository' => $repository,
        'ref' => PRODUCTION_REF,
        'sha' => $sha,
        'image' => $image,
        'digest' => $digest,
        'image_ref' => "{$image}@{$digest}",
        'platform' => PRODUCTION_IMAGE_PLATFORM,
        'workflow' => PRODUCTION_IMAGE_WORKFLOW,
        'run_id' => requirePositiveInteger($runId, 'run_id'),
    ];
}

if ($argc < 2) {
    fail('missing_mode');
}

$mode = $argv[1];
if ($mode === 'create') {
    if ($argc !== 8) {
        fail('invalid_create_arguments');
    }

    $path = $argv[2];
    $manifest = expectedManifest(array_slice($argv, 3));
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        fail('write_failed');
    }

    echo "PRODUCTION_IMAGE_MANIFEST=CREATED sha={$manifest['sha']} digest={$manifest['digest']}\n";
    exit(0);
}

if ($mode === 'verify') {
    if ($argc !== 6) {
        fail('invalid_verify_arguments');
    }

    [$path, $expectedRepository, $expectedSha, $expectedRunId] = array_slice($argv, 2);
    if (! is_file($path) || ! is_readable($path)) {
        fail('unreadable_manifest');
    }

    try {
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            fail('read_failed');
        }
        $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail('invalid_json');
    }
    if (! is_array($decoded) || array_is_list($decoded)) {
        fail('invalid_document');
    }

    $expected = expectedManifest([
        $expectedRepository,
        $expectedSha,
        (string) ($decoded['image'] ?? ''),
        (string) ($decoded['digest'] ?? ''),
        $expectedRunId,
    ]);
    $canonical = json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if ($decoded !== $expected || $contents !== $canonical) {
        fail('contract_mismatch');
    }

    echo "PRODUCTION_IMAGE_MANIFEST=VERIFIED sha={$expected['sha']} digest={$expected['digest']}\n";
    exit(0);
}

fail('invalid_mode');
