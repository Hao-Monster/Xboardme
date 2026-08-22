<?php

namespace Tests\Unit;

use App\Support\EnvironmentSecret;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvironmentSecretTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_inline_value_is_used_when_no_secret_file_is_configured(): void
    {
        $this->assertSame('inline-secret', EnvironmentSecret::resolveValue('inline-secret', null, 'REDIS_PASSWORD'));
        $this->assertNull(EnvironmentSecret::resolveValue('null', null, 'REDIS_PASSWORD'));
    }

    public function test_secret_file_is_trimmed_only_at_the_line_ending(): void
    {
        $path = $this->temporaryFile(" file-secret \r\n");

        $this->assertSame(' file-secret ', EnvironmentSecret::resolveValue(null, $path, 'REDIS_PASSWORD'));
    }

    public function test_ambiguous_inline_and_file_configuration_is_rejected(): void
    {
        $path = $this->temporaryFile("file-secret\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_PASSWORD and REDIS_PASSWORD_FILE cannot both be set');

        EnvironmentSecret::resolveValue('inline-secret', $path, 'REDIS_PASSWORD');
    }

    public function test_missing_or_empty_secret_file_is_rejected(): void
    {
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xboard-missing-' . bin2hex(random_bytes(8));

        try {
            EnvironmentSecret::resolveValue(null, $missing, 'REDIS_PASSWORD');
            $this->fail('Missing secret file was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('is not a readable file', $exception->getMessage());
        }

        $empty = $this->temporaryFile("\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be empty');

        EnvironmentSecret::resolveValue(null, $empty, 'REDIS_PASSWORD');
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xboard-secret-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary test file.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
