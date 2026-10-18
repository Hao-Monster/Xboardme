<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DiagnosticOpsArtifactsTest extends TestCase
{
    public function test_python_diagnostics_config_and_guard_suite_passes(): void
    {
        $process = new Process(
            ['python', '-m', 'unittest', 'discover', '-s', 'deploy/diagnostics', '-p', '*_test.py', '-v'],
            base_path()
        );
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Python D6 diagnostics test suite failed:\n" . $process->getOutput() . "\n" . $process->getErrorOutput()
        );
        $this->assertStringContainsString('OK', $process->getErrorOutput() . $process->getOutput());
    }

    public function test_preflight_storage_script_and_migration_runbook_exist_and_cover_required_contracts(): void
    {
        $scriptPath = base_path('scripts/diagnostics/preflight-storage.sh');
        $runbookPath = base_path('docs/ops/customer-connection-diagnostics-migration.md');
        $readmePath = base_path('deploy/diagnostics/README.md');

        $this->assertFileExists($scriptPath);
        $this->assertFileExists($runbookPath);
        $this->assertFileExists($readmePath);

        $script = (string) file_get_contents($scriptPath);
        $this->assertStringContainsString('fallocate -l', $script);
        $this->assertStringContainsString('storage.ext4', $script);
        $this->assertStringContainsString('findmnt', $script);
        $this->assertStringContainsString('50 * 1024 * 1024 * 1024', $script);

        $runbook = (string) file_get_contents($runbookPath);
        foreach (['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'P1', 'storage.ext4', 'diagnostics-guard'] as $needle) {
            $this->assertStringContainsString($needle, $runbook);
        }
    }
}
