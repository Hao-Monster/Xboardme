<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Tests\TestCase;

class OctanePluginLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN_CODE = 'octane_probe_test';
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureDir = base_path('plugins/OctaneProbeTest');
        $this->writePluginFixture();
        $this->resetProbeCounters();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureDir) && File::isDirectory($this->fixtureDir)) {
            File::deleteDirectory($this->fixtureDir);
        }

        parent::tearDown();
    }

    public function test_repeated_octane_requests_do_not_duplicate_routes_providers_or_closure_hooks_and_preserve_non_plugin_hooks(): void
    {
        /** @var PluginManager $pluginManager */
        $pluginManager = $this->app->make(PluginManager::class);
        $pluginManager->install(self::PLUGIN_CODE);
        $pluginManager->enable(self::PLUGIN_CODE);

        HookManager::registerFilter(
            'octane.probe.filter',
            static fn (array $items): array => [...$items, 'core:sticky'],
            10
        );

        $this->get('/octane-plugin-probe/web')
            ->assertOk()
            ->assertSeeText('probe-web-ok');
        $this->getJson('/api/octane-plugin-probe/ping')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $baselineRouteCount = count($this->app['router']->getRoutes()->getRoutes());

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->forgetScopedInstances();

            $this->get('/octane-plugin-probe/web')
                ->assertOk()
                ->assertSeeText('probe-web-ok');
            $this->getJson('/api/octane-plugin-probe/ping')
                ->assertOk()
                ->assertJson(['ok' => true]);
        }

        $this->assertSame(
            1,
            \Plugin\OctaneProbeTest\Plugin::$bootCalls,
            'Enabled plugin must boot once per worker state rather than re-booting on enable() + every scoped request.'
        );
        $this->assertSame(
            1,
            \Plugin\OctaneProbeTest\Providers\PluginServiceProvider::$registerCalls,
            'Plugin ServiceProvider must be registered at most once on the worker container.'
        );
        $this->assertSame(
            $baselineRouteCount,
            count($this->app['router']->getRoutes()->getRoutes()),
            'RouteCollection must not accumulate duplicate plugin routes across requests.'
        );

        $events = new \ArrayObject();
        HookManager::call('octane.probe.action', $events);
        $this->assertSame(['plugin:v1'], $events->getArrayCopy());

        $filtered = HookManager::filter('octane.probe.filter', []);
        $this->assertSame(['core:sticky', 'plugin:v1'], $filtered);
    }

    public function test_config_update_and_plugin_disable_reconcile_live_routes_and_hooks_across_octane_workers_while_preserving_dynamic_routes(): void
    {
        admin_setting([
            'secure_path' => 'pluginadmin77',
            'subscribe_path' => 'pluginsub77',
        ]);

        /** @var PluginManager $pluginManager */
        $pluginManager = $this->app->make(PluginManager::class);
        $pluginManager->install(self::PLUGIN_CODE);
        $pluginManager->enable(self::PLUGIN_CODE);

        $this->get('/octane-plugin-probe/web')->assertOk();
        $this->getJson('/api/octane-plugin-probe/ping')->assertOk();
        $this->get('/pluginadmin77')->assertOk();
        $this->assertSame(['plugin:v1'], HookManager::filter('octane.probe.filter', []));

        $routeCountWithPlugin = count($this->app['router']->getRoutes()->getRoutes());

        // 1. In-worker config update immediately refreshes plugin hooks without duplicating routes
        $this->app->make(PluginConfigService::class)->updateConfig(self::PLUGIN_CODE, [
            'tag' => 'v2-live',
        ]);

        $this->assertSame(['plugin:v2-live'], HookManager::filter('octane.probe.filter', []));
        $this->assertSame($routeCountWithPlugin, count($this->app['router']->getRoutes()->getRoutes()));

        // 2. Cross-worker DB config update reconciles on next request
        Plugin::query()->where('code', self::PLUGIN_CODE)->update([
            'config' => json_encode(['tag' => 'v3-peer']),
        ]);
        $this->app->forgetScopedInstances();
        $this->get('/octane-plugin-probe/web')->assertOk();

        $this->assertSame(['plugin:v3-peer'], HookManager::filter('octane.probe.filter', []));
        $this->assertSame($routeCountWithPlugin, count($this->app['router']->getRoutes()->getRoutes()));

        // 3. In-worker disable unmounts web + API routes, clears named route lookups & plugin hooks, preserves dynamic routes
        $pluginManager->disable(self::PLUGIN_CODE);

        $this->get('/octane-plugin-probe/web')->assertNotFound();
        $this->getJson('/api/octane-plugin-probe/ping')->assertNotFound();
        $this->assertSame([], HookManager::filter('octane.probe.filter', []));
        $this->get('/pluginadmin77')->assertOk();
        $this->assertSame('/pluginsub77/tok123', route('client.subscribe', ['token' => 'tok123'], false));

        try {
            route('octane.probe.web');
            $this->fail('Disabled plugin named route octane.probe.web should no longer resolve.');
        } catch (RouteNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // 4. Re-enable, then simulate cross-worker DB disable; next request to plugin URI must immediately 404
        $pluginManager->enable(self::PLUGIN_CODE);
        $this->get('/octane-plugin-probe/web')->assertOk();
        $this->assertSame(['plugin:v3-peer'], HookManager::filter('octane.probe.filter', []));

        Plugin::query()->where('code', self::PLUGIN_CODE)->update([
            'is_enabled' => false,
        ]);
        $this->app->forgetScopedInstances();

        $this->get('/octane-plugin-probe/web')->assertNotFound();
        $this->getJson('/api/octane-plugin-probe/ping')->assertNotFound();
        $this->assertSame([], HookManager::filter('octane.probe.filter', []));
        $this->get('/pluginadmin77')->assertOk();
    }

    public function test_octane_sandbox_clone_and_flush_retains_plugin_hooks_without_rebooting_every_request(): void
    {
        // Ensure router and PluginManager are pre-warmed on the root worker container just like Octane Worker::boot()
        $this->app->make('router');
        /** @var PluginManager $pluginManager */
        $pluginManager = $this->app->make(PluginManager::class);
        $pluginManager->install(self::PLUGIN_CODE);
        $pluginManager->enable(self::PLUGIN_CODE);

        $rootApp = $this->app;

        for ($i = 0; $i < 2; $i++) {
            /** @var Application $sandbox */
            $sandbox = clone $rootApp;
            Container::setInstance($sandbox);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($sandbox);

            try {
                $kernel = $sandbox->make(\Illuminate\Contracts\Http\Kernel::class);
                $request = Request::create('/octane-plugin-probe/web', 'GET');
                $response = $kernel->handle($request);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame(['plugin:v1'], HookManager::filter('octane.probe.filter', []));
                $kernel->terminate($request, $response);
            } finally {
                $sandbox->flush();
                $rootApp->forgetScopedInstances();
                Container::setInstance($rootApp);
                Facade::clearResolvedInstances();
                Facade::setFacadeApplication($rootApp);
            }
        }

        $this->assertSame(1, \Plugin\OctaneProbeTest\Plugin::$bootCalls);
        $this->assertSame(['plugin:v1'], HookManager::filter('octane.probe.filter', []));
    }

    private function resetProbeCounters(): void
    {
        if (class_exists(\Plugin\OctaneProbeTest\Plugin::class, false)) {
            \Plugin\OctaneProbeTest\Plugin::$bootCalls = 0;
            \Plugin\OctaneProbeTest\Plugin::$cleanupCalls = 0;
        }
        if (class_exists(\Plugin\OctaneProbeTest\Providers\PluginServiceProvider::class, false)) {
            \Plugin\OctaneProbeTest\Providers\PluginServiceProvider::$registerCalls = 0;
        }
    }

    private function writePluginFixture(): void
    {
        File::ensureDirectoryExists($this->fixtureDir . '/Providers');
        File::ensureDirectoryExists($this->fixtureDir . '/routes');

        File::put(
            $this->fixtureDir . '/config.json',
            json_encode([
                'name' => 'Octane Probe Test',
                'code' => self::PLUGIN_CODE,
                'version' => '1.0.0',
                'description' => 'Synthetic plugin fixture for Octane lifecycle regression tests',
                'author' => 'Xboard TDD',
                'type' => 'feature',
                'config' => [
                    'tag' => [
                        'type' => 'string',
                        'label' => 'Tag',
                        'default' => 'v1',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        File::put(
            $this->fixtureDir . '/Providers/PluginServiceProvider.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Plugin\OctaneProbeTest\Providers;

use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public static int $registerCalls = 0;

    public function register(): void
    {
        self::$registerCalls++;
        $this->app->instance('octane_probe_test.provider_ready', true);
    }
}
PHP
        );

        File::put(
            $this->fixtureDir . '/Plugin.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Plugin\OctaneProbeTest;

use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;

class Plugin extends AbstractPlugin
{
    public static int $bootCalls = 0;
    public static int $cleanupCalls = 0;

    public function boot(): void
    {
        self::$bootCalls++;

        HookManager::register('octane.probe.action', function (mixed $events): void {
            if ($events instanceof \ArrayObject) {
                $events->append('plugin:' . (string) $this->getConfig('tag', 'v1'));
            }
        });

        HookManager::registerFilter('octane.probe.filter', function (array $items): array {
            $items[] = 'plugin:' . (string) $this->getConfig('tag', 'v1');
            return $items;
        });
    }

    public function cleanup(): void
    {
        self::$cleanupCalls++;
    }
}
PHP
        );

        File::put(
            $this->fixtureDir . '/routes/web.php',
            <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/octane-plugin-probe/web', function () {
    return response('probe-web-ok', 200);
})->name('octane.probe.web');
PHP
        );

        File::put(
            $this->fixtureDir . '/routes/api.php',
            <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/api/octane-plugin-probe/ping', function () {
    return response()->json(['ok' => true]);
})->name('octane.probe.api');
PHP
        );
    }
}
