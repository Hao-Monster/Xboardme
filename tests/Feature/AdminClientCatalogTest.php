<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\User;
use App\Services\ClientCatalogService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminClientCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        config()->set('client_catalog.clients', [[
            'id' => 'sample', 'name' => 'Sample', 'core' => 'Mihomo', 'description' => 'Sample',
            'downloads' => [
                'android' => ['url' => 'https://downloads.example.com/default.apk', 'source' => 'website'],
                'windows' => ['url' => 'https://downloads.example.com/default.exe', 'source' => 'website'],
            ],
        ]]);
    }

    public function test_only_admins_can_read_or_update_client_button_links(): void
    {
        $this->getJson(route('admin.client-catalog.index', [], false))->assertForbidden();

        Sanctum::actingAs($this->user('member-client-catalog@example.com'));
        $this->postJson(route('admin.client-catalog.save', [], false), ['links' => []])->assertForbidden();

        Sanctum::actingAs($this->user('admin-client-catalog@example.com', true));
        $this->getJson(route('admin.client-catalog.index', [], false))
            ->assertOk()
            ->assertJsonPath('data.clients.0.id', 'sample')
            ->assertJsonPath('data.clients.0.platforms.0.links.cloud', '');
    }

    public function test_admin_can_save_four_links_per_supported_platform(): void
    {
        Sanctum::actingAs($this->user('admin-client-save@example.com', true));
        $links = [
            'sample' => [
                'android' => [
                    'direct' => 'https://downloads.example.com/custom.apk',
                    'qr' => 'https://qr.example.com/sample-android',
                    'cloud' => 'https://pan.example.com/sample-android',
                    'tutorial' => '/guide/8/sample-android',
                ],
                'windows' => ['direct' => '', 'qr' => '', 'cloud' => '', 'tutorial' => ''],
            ],
        ];

        $this->postJson(route('admin.client-catalog.save', [], false), ['links' => $links])
            ->assertOk()
            ->assertJsonPath('data.clients.0.platforms.0.links.qr', 'https://qr.example.com/sample-android')
            ->assertJsonPath('data.clients.0.platforms.0.links.tutorial', '/guide/8/sample-android');

        $this->assertSame(
            'https://pan.example.com/sample-android',
            app(ClientCatalogService::class)->resolveAction('sample', 'android', 'cloud')
        );
        $this->assertSame(
            'https://qr.example.com/sample-android',
            app(ClientCatalogService::class)->resolveAction('sample', 'android', 'qr')
        );
    }

    public function test_admin_cannot_configure_unknown_platforms_or_unsafe_urls(): void
    {
        Sanctum::actingAs($this->user('admin-client-invalid@example.com', true));

        $this->postJson(route('admin.client-catalog.save', [], false), [
            'links' => ['sample' => ['ios' => ['direct' => 'https://example.com/app']]],
        ])->assertUnprocessable();

        foreach (['http://example.com/app', 'javascript:alert(1)', '//example.com/app'] as $unsafe) {
            $this->postJson(route('admin.client-catalog.save', [], false), [
                'links' => ['sample' => ['android' => ['cloud' => $unsafe]]],
            ])->assertUnprocessable();
        }

        $this->postJson(route('admin.client-catalog.save', [], false), [
            'links' => ['sample' => ['android' => ['tutorial' => '/guide/sample']]],
        ])->assertOk();
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $admin,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
        ]);
    }
}
