<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Http\Middleware\InitializePlugins;
use App\Models\AdminAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticStorageHealthClient;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DiagnosticConfigFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        config(['cache.stores.redis' => ['driver' => 'array']]);
        Cache::flush();
    }

    public function test_admin_saved_endpoint_and_token_are_used_without_leaking_into_audit_or_fetch(): void
    {
        $this->signIn(true);
        $baseUrl = 'https://diagnostics.example.com';
        $token = 'qa-diagnostics-read-token';

        $this->postJson($this->adminUri('config/save'), [
            'diagnostics_base_url' => $baseUrl,
            'diagnostics_read_token' => $token,
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('v2_settings', ['name' => 'diagnostics_base_url', 'value' => $baseUrl]);
        $this->assertDatabaseHas('v2_settings', ['name' => 'diagnostics_read_token', 'value' => $token]);
        $audit = AdminAuditLog::query()->where('action', 'config.save')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($token, (string) $audit->getRawOriginal('request_data'));

        $fetched = $this->getJson($this->adminUri('config/fetch') . '?key=diagnostics')
            ->assertOk()
            ->assertJsonPath('data.diagnostics.diagnostics_base_url', $baseUrl)
            ->assertJsonPath('data.diagnostics.diagnostics_read_token_configured', true);
        $this->assertStringNotContainsString($token, (string) $fetched->getContent());
        $this->assertStringNotContainsString($token, (string) $this->getJson($this->adminUri('config/fetch'))->getContent());

        Http::preventStrayRequests();
        Http::fake([$baseUrl . '/diagnostics/health' => Http::response([], 503)]);
        $this->app->forgetScopedInstances();
        $health = app(DiagnosticStorageHealthClient::class)->fetchHealth();
        Http::assertSent(static fn (Request $request): bool => $request->url() === $baseUrl . '/diagnostics/health'
            && $request->hasHeader('Authorization', 'Bearer ' . $token));
        $this->assertSame('unknown', $health['state']);
    }

    public function test_invalid_endpoint_or_token_is_rejected_without_persisting_partial_settings(): void
    {
        $this->signIn(true);
        foreach ([
            ['diagnostics_base_url' => 'http://diagnostics.example.com'],
            ['diagnostics_base_url' => 'not-a-url'],
            ['diagnostics_base_url' => 'https://user:password@diagnostics.example.com'],
            ['diagnostics_base_url' => 'https://diagnostics.example.com?token=secret'],
            ['diagnostics_base_url' => 'https://diagnostics.example.com#secret'],
            ['diagnostics_read_token' => ['unexpected-array']],
            ['diagnostics_read_token' => str_repeat('x', 513)],
        ] as $invalid) {
            $this->postJson($this->adminUri('config/save'), $invalid)->assertStatus(422);
        }
        $this->assertDatabaseMissing('v2_settings', ['name' => 'diagnostics_base_url']);
        $this->assertDatabaseMissing('v2_settings', ['name' => 'diagnostics_read_token']);
    }

    public function test_clearing_settings_disables_the_endpoint_and_preserves_panel_scope(): void
    {
        $this->signIn(true);
        admin_setting([
            'diagnostics_base_url' => 'https://diagnostics.example.com',
            'diagnostics_read_token' => 'qa-old-read-token',
        ]);
        Setting::create([
            'name' => 'diagnostics_panel_scope',
            'value' => '11111111-1111-4111-8111-111111111111',
        ]);
        $this->postJson($this->adminUri('config/save'), [
            'diagnostics_base_url' => null,
            'diagnostics_read_token' => null,
        ])->assertOk();
        $this->getJson($this->adminUri('config/fetch') . '?key=diagnostics')
            ->assertOk()->assertJsonPath('data.diagnostics.diagnostics_read_token_configured', false);
        $this->assertDatabaseHas('v2_settings', [
            'name' => 'diagnostics_panel_scope',
            'value' => '11111111-1111-4111-8111-111111111111',
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $this->app->forgetScopedInstances();
        $health = app(DiagnosticStorageHealthClient::class)->fetchHealth();
        $this->assertSame('unknown', $health['state']);
        $this->assertContains('health_endpoint_unconfigured', $health['reason_codes']);
        Http::assertNothingSent();
    }

    public function test_non_admin_cannot_configure_the_diagnostics_backend(): void
    {
        $this->signIn(false);
        $this->postJson($this->adminUri('config/save'), [
            'diagnostics_base_url' => 'https://diagnostics.example.com',
            'diagnostics_read_token' => 'qa-read-token',
        ])->assertStatus(403);
        $this->assertDatabaseMissing('v2_settings', ['name' => 'diagnostics_read_token']);
    }

    public function test_query_string_token_is_removed_from_audit_data_and_uri(): void
    {
        $this->signIn(true);
        $token = 'qa-query-read-token';
        $this->postJson($this->adminUri('config/save') . '?' . http_build_query([
            'diagnostics_read_token' => $token,
            'audit_probe' => 'retained',
        ]), ['diagnostics_base_url' => 'https://diagnostics.example.com'])->assertOk();
        $this->assertDatabaseHas('v2_settings', ['name' => 'diagnostics_read_token', 'value' => $token]);
        $audit = AdminAuditLog::query()->where('action', 'config.save')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($token, (string) $audit->getRawOriginal('request_data'));
        $this->assertStringNotContainsString($token, (string) $audit->uri);
        $this->assertStringNotContainsString('diagnostics_read_token', (string) $audit->uri);
        $this->assertStringContainsString('audit_probe=retained', (string) $audit->uri);
    }

    private function signIn(bool $isAdmin): void
    {
        Sanctum::actingAs(User::create([
            'email' => 'diagnostics-config@example.com',
            'password' => password_hash('qa-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $isAdmin,
            'banned' => false,
        ]));
    }

    private function adminUri(string $path): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        return '/api/v2/' . ltrim((string) $prefix, '/') . '/' . $path;
    }
}
