<?php

namespace Tests\Unit;

use App\Support\AssetVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssetVersionTest extends TestCase
{
    private const RELEASE_SHA = '0123456789abcdef0123456789abcdef01234567';

    public function test_production_accepts_the_exact_release_commit_sha(): void
    {
        $this->assertSame(
            self::RELEASE_SHA,
            AssetVersion::resolve(self::RELEASE_SHA, 'production')
        );
    }

    #[DataProvider('invalidProductionVersions')]
    public function test_production_rejects_non_immutable_asset_versions(string $version): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssetVersion::resolve($version, 'production');
    }

    public static function invalidProductionVersions(): array
    {
        return [
            'empty' => [''],
            'unknown fallback' => ['20260820-unknown'],
            'short commit' => ['acc5980'],
            'mutable semantic version' => ['1.0.0'],
            'uppercase commit' => [strtoupper(self::RELEASE_SHA)],
        ];
    }

    public function test_non_production_environments_keep_developer_versions_usable(): void
    {
        $this->assertSame('1.0.0', AssetVersion::resolve('1.0.0', 'local'));
        $this->assertSame('test-release', AssetVersion::resolve('test-release', 'testing'));
    }
}
