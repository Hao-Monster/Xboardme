<?php

namespace Tests\Unit;

use App\Services\ClientCatalogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClientCatalogServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('client_catalog.clients', [[
            'id' => 'sample', 'name' => 'Sample', 'core' => 'Sing-box', 'description' => 'Sample client',
            'downloads' => [
                'android' => ['repo' => 'vendor/sample', 'patterns' => ['/universal\.apk$/i']],
                'ios' => ['url' => 'https://apps.apple.com/app/sample/id1', 'source' => 'app-store'],
            ],
        ]]);
    }

    public function test_catalog_exposes_only_stable_controlled_download_routes(): void
    {
        $catalog = app(ClientCatalogService::class)->catalog();
        $this->assertSame('sample', $catalog[0]['id']);
        $this->assertTrue($catalog[0]['hwid']);
        $this->assertStringEndsWith('/client-download/sample/android', $catalog[0]['downloads'][0]['download_url']);
        $this->assertSame('github', $catalog[0]['downloads'][0]['source']);
    }

    public function test_github_release_resolves_to_matching_install_asset_and_is_cached(): void
    {
        Http::fake(['api.github.com/repos/vendor/sample/releases/latest' => Http::response(['assets' => [
            ['name' => 'sample-arm64.apk', 'browser_download_url' => 'https://github.com/vendor/sample/releases/download/v1/sample-arm64.apk'],
            ['name' => 'sample-universal.apk', 'browser_download_url' => 'https://github.com/vendor/sample/releases/download/v1/sample-universal.apk'],
        ]])]);
        $service = app(ClientCatalogService::class);
        $expected = 'https://github.com/vendor/sample/releases/download/v1/sample-universal.apk';
        $this->assertSame($expected, $service->resolve('sample', 'android'));
        $this->assertSame($expected, $service->resolve('sample', 'android'));
        Http::assertSentCount(1);
    }

    public function test_direct_store_url_is_returned_without_remote_lookup(): void
    {
        Http::fake();
        $this->assertSame('https://apps.apple.com/app/sample/id1', app(ClientCatalogService::class)->resolve('sample', 'ios'));
        Http::assertNothingSent();
    }

    public function test_unknown_client_or_platform_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        app(ClientCatalogService::class)->resolve('sample', 'windows');
    }
}
