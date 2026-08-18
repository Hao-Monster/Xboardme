<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$requireClean = in_array('--require-clean', $argv, true);
$approvalFile = dirname(__DIR__) . '/release/approved-migrations.txt';
$approved = [];

foreach (file($approvalFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (!preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/', $line)) {
        fwrite(STDERR, "Invalid approved migration entry: {$line}\n");
        exit(2);
    }
    $approved[$line] = true;
}

$migrator = $application->make('migrator');
$files = $migrator->getMigrationFiles([database_path('migrations')]);
$ran = array_fill_keys($migrator->getRepository()->getRan(), true);
$pending = array_values(array_filter(
    array_keys($files),
    static fn (string $migration): bool => !isset($ran[$migration])
));
$unapproved = array_values(array_filter(
    $pending,
    static fn (string $migration): bool => !isset($approved[$migration])
));

if ($unapproved !== []) {
    fwrite(STDERR, 'Unapproved pending migrations: ' . implode(', ', $unapproved) . "\n");
    exit(1);
}
if ($requireClean && $pending !== []) {
    fwrite(STDERR, 'Pending migrations remain: ' . implode(', ', $pending) . "\n");
    exit(1);
}

echo json_encode([
    'status' => 'pass',
    'pending' => $pending,
    'approved_pending' => array_values(array_intersect($pending, array_keys($approved))),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
