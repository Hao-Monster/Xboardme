<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Support\ProtocolManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Tests\TestCase;

class LaravelUpgradeCompatibilityTest extends TestCase
{
    public function test_public_route_contract_matches_the_laravel_12_baseline(): void
    {
        $defaultSecurePath = hash('crc32b', config('app.key'));

        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->reject(fn (Route $route): bool => str_starts_with($route->uri(), '_debugbar'))
            ->map(function (Route $route) use ($defaultSecurePath): string {
                $methods = $route->methods();
                sort($methods, SORT_STRING);

                $uri = str_replace($defaultSecurePath, '{secure_path}', $route->uri());

                return implode(',', $methods) . '|' . $uri . '|' . ($route->getName() ?? '');
            })
            ->sort(SORT_STRING)
            ->values();

        $this->assertSame(
            '1d5429a7766e680af368ee739def17dca2a6566409c89b9d4dc8d14f877e14d8',
            hash('sha256', $routes->implode("\n")),
            sprintf('The normalized public route contract contains %d routes.', $routes->count())
        );
    }

    public function test_authentication_and_state_prefixes_preserve_online_sessions(): void
    {
        $this->assertNull(config('sanctum.expiration'));
        $this->assertSame('', config('sanctum.token_prefix'));
        $this->assertSame('laravel_cache', config('cache.prefix'));
        $this->assertSame('laravel_database_', config('database.redis.options.prefix'));
        $this->assertSame('laravel_session', config('session.cookie'));
        $this->assertSame('php', config('session.serialization'));
        $this->assertSame(storage_path('framework/sessions'), config('session.files'));
        $this->assertSame(storage_path('logs/octane-server-state.json'), config('octane.state_file'));
        $this->assertSame(storage_path('logs/xboard-ws-server.pid'), config('app.ws_pid_file'));
        $this->assertNull(config('cache.serializable_classes'));
        $this->assertTrue(is_subclass_of(VerifyCsrfToken::class, PreventRequestForgery::class));
    }

    public function test_scheduler_is_enabled_by_default_and_can_be_disabled_for_green_web_instances(): void
    {
        $this->assertTrue(config('app.scheduler_enabled'));

        $provider = file_get_contents(app_path('Providers/OctaneServiceProvider.php'));
        $this->assertStringContainsString("config('app.scheduler_enabled', true)", $provider);
        $this->assertStringContainsString('return;', $provider);
    }

    public function test_framework_upgrade_adds_no_business_database_migrations(): void
    {
        $migrations = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => basename($path))
            ->sort()
            ->values();

        $this->assertSame(51, $migrations->count());
        $this->assertSame(
            'b90f32ed1df374c7ee55f611809dd717d474503a0f96f61ffa998daa94f69219',
            hash('sha256', $migrations->implode("\n"))
        );
    }

    public function test_all_subscription_protocols_remain_registered(): void
    {
        /** @var ProtocolManager $manager */
        $manager = $this->app->make('protocols.manager');

        $this->assertSame([
            'App\\Protocols\\Clash',
            'App\\Protocols\\ClashMeta',
            'App\\Protocols\\General',
            'App\\Protocols\\Loon',
            'App\\Protocols\\QuantumultX',
            'App\\Protocols\\Shadowrocket',
            'App\\Protocols\\Shadowsocks',
            'App\\Protocols\\SingBox',
            'App\\Protocols\\Stash',
            'App\\Protocols\\Surfboard',
            'App\\Protocols\\Surge',
        ], $manager->getProtocolClasses());

        $this->assertSame([
            'clash', 'meta', 'verge', 'flclash', 'nekobox', 'clashmetaforandroid',
            'general', 'v2rayn', 'v2rayng', 'passwall', 'ssrplus', 'sagernet',
            'loon', 'quantumult%20x', 'quantumult-x', 'shadowrocket', 'shadowsocks',
            'sing-box', 'hiddify', 'sfm', 'karing', 'stash', 'surfboard', 'surge',
        ], $manager->getAllFlags());
    }
}
