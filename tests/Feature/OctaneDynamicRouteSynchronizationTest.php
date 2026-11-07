<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Setting;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\TestCase;

class OctaneDynamicRouteSynchronizationTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    public function test_runtime_changes_to_secure_path_and_subscribe_path_update_live_octane_routes_immediately(): void
    {
        admin_setting([
            'app_url' => 'https://cloud.thinderbox.com',
            'subscribe_url' => 'https://sub.thinderbox.uk',
        ]);

        $bootSecurePath = (string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', (string) config('app.key')))
        );

        $admin = User::query()->create([
            'email' => 'octane-admin@example.com',
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => true,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
            't' => 0,
        ]);
        $adminHeaders = [
            'Authorization' => (new AuthService($admin))->generateAuthData()['auth_data'],
        ];

        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer();

        // 1. Baseline boot-time routes respond as expected
        $this->get("/{$bootSecurePath}")->assertOk();
        $this->getJson("/api/v2/{$bootSecurePath}/config/fetch", $adminHeaders)->assertOk();
        $this->get("/s/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])->assertOk();
        Auth::forgetGuards();
        $this->assertSame(
            "https://sub.thinderbox.uk/s/{$subscriber->token}",
            Helper::getSubscribeUrl($subscriber->token)
        );

        // 2. Mutate secure_path and subscribe_path in-flight without rebooting the worker/app
        admin_setting([
            'secure_path' => 'octanesecure88',
            'subscribe_path' => 'sub-v2',
        ]);

        // Same-process URL generation must immediately reflect the updated subscribe_path
        $this->assertSame(
            "/sub-v2/{$subscriber->token}",
            route('client.subscribe', ['token' => $subscriber->token], false)
        );
        $this->assertSame(
            "https://sub.thinderbox.uk/sub-v2/{$subscriber->token}",
            Helper::getSubscribeUrl($subscriber->token)
        );

        // Updated web admin entry, V2 admin API prefix, and subscription route must match; retired URIs must 404
        $adminHtml = $this->get('/octanesecure88');
        $adminHtml->assertOk();
        $adminHtml->assertSee('secure_path: "octanesecure88"', false);
        $this->get("/{$bootSecurePath}")->assertNotFound();

        $this->getJson('/api/v2/octanesecure88/config/fetch', $adminHeaders)->assertOk();
        $this->getJson("/api/v2/{$bootSecurePath}/config/fetch", $adminHeaders)->assertNotFound();

        $subResp = $this->get("/sub-v2/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0']);
        $subResp->assertOk();
        $this->assertSame('client.subscribe', $this->app['router']->currentRouteName());
        Auth::forgetGuards();
        $this->get("/s/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])->assertNotFound();

        // 3. Simulate cross-Octane-worker mutation (another worker updates DB + flushes shared cache;
        //    this worker only flushes scoped instances on RequestReceived without calling Setting::save locally)
        SettingModel::createOrUpdate('secure_path', 'peerworker99');
        SettingModel::createOrUpdate('subscribe_path', 'sub-peer');
        Cache::store(config('cache.settings_store', 'redis'))->forget(Setting::CACHE_KEY);
        $this->app->forgetScopedInstances();
        Auth::forgetGuards();

        $peerHtml = $this->get('/peerworker99');
        $peerHtml->assertOk();
        $peerHtml->assertSee('secure_path: "peerworker99"', false);
        $this->get('/octanesecure88')->assertNotFound();

        $this->getJson('/api/v2/peerworker99/config/fetch', $adminHeaders)->assertOk();
        $this->getJson('/api/v2/octanesecure88/config/fetch', $adminHeaders)->assertNotFound();

        $peerSubResp = $this->get("/sub-peer/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0']);
        $peerSubResp->assertOk();
        $this->assertSame('client.subscribe', $this->app['router']->currentRouteName());
        Auth::forgetGuards();
        $this->get("/sub-v2/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])->assertNotFound();

        // 4. Subscription gateway route guard follows runtime subscribe_path and blocks retired /s/{token}
        $this->withServerVariables(['REMOTE_ADDR' => '103.133.179.159'])
            ->get("/sub-peer/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])
            ->assertOk();
        Auth::forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '103.133.179.159'])
            ->get("/s/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])
            ->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '103.133.179.159'])
            ->get('/peerworker99')
            ->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        // 5. Slash-only or non-scalar values fall back cleanly to defaults without corrupting routes
        admin_setting([
            'secure_path' => '///',
            'subscribe_path' => '///',
        ]);
        $this->get("/{$bootSecurePath}")->assertOk();
        $this->get('/peerworker99')->assertNotFound();
        $this->get("/s/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])->assertOk();
        Auth::forgetGuards();
        $this->get("/sub-peer/{$subscriber->token}", ['User-Agent' => 'Clash.Meta/v1.18.0'])->assertNotFound();
    }
}
