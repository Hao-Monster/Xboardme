<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Services\ClientCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientCatalogDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Cache::flush();
        config()->set('client_catalog.clients', [[
            'id' => 'sample', 'name' => 'Sample', 'core' => 'Xray', 'description' => 'Sample',
            'downloads' => ['android' => ['repo' => 'vendor/sample', 'patterns' => ['/universal\.apk$/i']]],
        ]]);
    }

    public function test_public_download_route_redirects_to_real_release_asset(): void
    {
        Http::fake(['api.github.com/repos/vendor/sample/releases/latest' => Http::response(['assets' => [[
            'name' => 'sample-universal.apk',
            'browser_download_url' => 'https://github.com/vendor/sample/releases/download/v2/sample-universal.apk',
        ]]])]);
        $this->get('/client-download/sample/android')
            ->assertRedirect('https://github.com/vendor/sample/releases/download/v2/sample-universal.apk')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_unknown_client_does_not_become_an_open_redirect(): void
    {
        $this->get('/client-download/unknown/android')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_configured_cloud_and_tutorial_routes_redirect_safely(): void
    {
        admin_setting([ClientCatalogService::SETTING_KEY => [
            'sample' => ['android' => [
                'cloud' => 'https://pan.example.com/sample',
                'tutorial' => '/guide/9/sample',
            ]],
        ]]);

        $this->get('/client-link/sample/android/cloud')
            ->assertRedirect('https://pan.example.com/sample')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/client-link/sample/android/tutorial')
            ->assertRedirect('/guide/9/sample');
    }

    public function test_unconfigured_optional_action_is_not_available(): void
    {
        $this->get('/client-link/sample/android/cloud')->assertNotFound();
    }
}
