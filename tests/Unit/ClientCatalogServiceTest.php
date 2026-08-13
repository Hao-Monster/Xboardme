<?php

namespace Tests\Unit;

use App\Services\ClientCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClientCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertNull($catalog[0]['downloads'][0]['cloud_url']);
        $this->assertNull($catalog[0]['downloads'][0]['tutorial_url']);
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

    public function test_qr_defaults_to_direct_and_configured_actions_are_isolated(): void
    {
        admin_setting([ClientCatalogService::SETTING_KEY => [
            'sample' => ['android' => [
                'direct' => 'https://downloads.example.com/sample.apk',
                'cloud' => 'https://pan.example.com/sample',
                'tutorial' => '/guide/12/sample',
            ]],
        ]]);

        $service = app(ClientCatalogService::class);
        $this->assertSame('https://downloads.example.com/sample.apk', $service->resolveAction('sample', 'android', 'qr'));
        $this->assertSame('https://pan.example.com/sample', $service->resolveAction('sample', 'android', 'cloud'));
        $this->assertSame('/guide/12/sample', $service->resolveAction('sample', 'android', 'tutorial'));
        $catalog = $service->catalog();
        $this->assertStringEndsWith('/client-link/sample/android/cloud', $catalog[0]['downloads'][0]['cloud_url']);
        $this->assertStringEndsWith('/client-link/sample/android/tutorial', $catalog[0]['downloads'][0]['tutorial_url']);
    }

    public function test_production_catalog_starts_with_requested_client_order(): void
    {
        $productionConfig = require base_path('config/client_catalog.php');
        config()->set('client_catalog.clients', $productionConfig['clients']);

        $ids = array_column(app(ClientCatalogService::class)->catalog(), 'id');
        $this->assertSame(['karing', 'happ', 'clash-mi', 'koalaclash'], array_slice($ids, 0, 4));
    }
}
