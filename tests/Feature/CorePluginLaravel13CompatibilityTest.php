<?php

namespace Tests\Feature;

use App\Contracts\PaymentInterface;
use App\Models\Plugin;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorePluginLaravel13CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance('hook.actions');
        $this->app->forgetInstance('hook.filters');
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance('hook.actions');
        $this->app->forgetInstance('hook.filters');

        parent::tearDown();
    }

    public function test_all_production_core_plugins_install_and_boot_on_laravel_13(): void
    {
        PluginManager::installDefaultPlugins();

        $expectedCodes = [
            'alipay_f2f',
            'btcpay',
            'coin_payments',
            'coinbase',
            'epay',
            'mgate',
            'telegram',
        ];

        $installedCodes = Plugin::query()
            ->where('is_enabled', true)
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame($expectedCodes, $installedCodes);

        /** @var PluginManager $manager */
        $manager = $this->app->make(PluginManager::class);
        $enabledPlugins = $manager->getEnabledPlugins();
        ksort($enabledPlugins);

        $this->assertSame($expectedCodes, array_keys($enabledPlugins));
        foreach ($enabledPlugins as $code => $plugin) {
            $this->assertTrue($manager->isCorePlugin($code));
            $this->assertSame($code, $plugin->getPluginCode());
        }

        $paymentPlugins = $manager->getEnabledPaymentPlugins();
        $this->assertCount(6, $paymentPlugins);
        foreach ($paymentPlugins as $paymentPlugin) {
            $this->assertInstanceOf(PaymentInterface::class, $paymentPlugin);
            $this->assertNotEmpty($paymentPlugin->form());
        }

        $paymentMethods = HookManager::filter('available_payment_methods', []);
        $this->assertSame([
            'AlipayF2F',
            'BTCPay',
            'CoinPayments',
            'Coinbase',
            'EPay',
            'MGate',
        ], array_keys($paymentMethods));

        foreach ($paymentMethods as $method) {
            $this->assertSame('plugin', $method['type']);
            $this->assertContains($method['plugin_code'], $expectedCodes);
        }

        $this->assertArrayHasKey('telegram.message.handle', HookManager::getFilters());
        $this->assertArrayHasKey('payment.notify.success', HookManager::getActions());
    }
}
