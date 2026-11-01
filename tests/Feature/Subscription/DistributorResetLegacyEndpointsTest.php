<?php

namespace Tests\Feature\Subscription;

use App\Models\Server;
use App\Services\DistributorSubscriptionResetService;
use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class DistributorResetLegacyEndpointsTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    private static ?LoopbackRespRedisServer $redisServer = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$redisServer = new LoopbackRespRedisServer();
        self::$redisServer->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$redisServer?->stop();
        self::$redisServer = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$redisServer?->configureApplication($this->app);
        self::$redisServer?->flushAll();
    }

    public static function hwidModes(): array
    {
        return ['enabled' => [true], 'disabled' => [false]];
    }

    #[DataProvider('hwidModes')]
    public function test_legacy_app_config_cannot_bypass_distributor_authorization(bool $hwidEnabled): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture(hwidEnabled: $hwidEnabled);
        $this->makeOnlineServer(['type' => Server::TYPE_TROJAN]);
        $before = $this->get('/api/v1/client/app/getConfig?token=' . $fixture['subscriber']->token)
            ->assertForbidden()->assertContent('');
        $this->assertStringContainsString('no-store', $before->headers->get('Cache-Control'));
        $this->resetFixture($fixture);
        $this->get('/api/v1/client/app/getConfig?token=' . $fixture['subscriber']->token)->assertForbidden();
        $after = $this->get('/api/v1/client/app/getConfig?token=' . $fixture['subscriber']->fresh()->token, [
            'X-HWID' => 'cannot-bypass-device',
        ])->assertForbidden()->assertContent('');
        $this->assertStringContainsString('no-store', $after->headers->get('Cache-Control'));
        $this->assertSame(0, $fixture['delivery']->hwidDevices()->count());
    }

    public function test_legacy_app_config_remains_available_for_ordinary_subscribers(): void
    {
        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer(['type' => Server::TYPE_TROJAN]);
        $response = $this->get('/api/v1/client/app/getConfig?token=' . $subscriber->token)->assertOk();
        $this->assertStringContainsString($subscriber->uuid, $response->getContent());
    }

    public static function managedProtocols(): array
    {
        return ['surge' => ['surge'], 'surfboard' => ['surfboard']];
    }

    #[DataProvider('managedProtocols')]
    public function test_reset_revokes_path_token_and_managed_url_while_new_config_contains_only_new_credentials(string $flag): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->makeOnlineServer(['type' => Server::TYPE_TROJAN]);
        $oldSubscriber = $fixture['subscriber'];
        $headers = ['X-HWID' => 'managed-reset-device', 'User-Agent' => $flag . '/5.0'];
        $oldResponse = $this->get('/s/' . $oldSubscriber->token . '?flag=' . $flag, $headers)->assertOk();
        $oldConfig = $oldResponse->getContent();
        $this->assertStringContainsString($oldSubscriber->uuid, $oldConfig);
        $this->assertSame(1, preg_match('/^#!MANAGED-CONFIG\s+(\S+)/m', $oldConfig, $oldMatch));
        $this->assertStringContainsString('/s/' . $oldSubscriber->token, $oldMatch[1]);

        $this->resetFixture($fixture);
        $newSubscriber = $oldSubscriber->fresh();
        $this->get('/s/' . $oldSubscriber->token . '?flag=' . $flag, $headers)->assertForbidden();
        $this->get($oldMatch[1], $headers)->assertForbidden();
        $newResponse = $this->get('/s/' . $newSubscriber->token . '?flag=' . $flag, $headers)->assertOk();
        $newConfig = $newResponse->getContent();
        $this->assertStringContainsString($newSubscriber->uuid, $newConfig);
        $this->assertStringContainsString('/s/' . $newSubscriber->token, $newConfig);
        $this->assertStringNotContainsString($oldSubscriber->uuid, $newConfig);
        $this->assertStringNotContainsString($oldSubscriber->token, $newConfig);
        $this->assertSame(1, preg_match('/^#!MANAGED-CONFIG\s+(\S+)/m', $newConfig, $newMatch));
        $this->get($newMatch[1], $headers)->assertOk();
    }

    public function test_claim_reset_after_commit_does_not_redirect_to_stale_credentials(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $claimToken = $fixture['delivery']->claim_token;
        $reset = false;
        HookManager::registerFilter('subscribe.url', function (string $url) use ($fixture, &$reset): string {
            if (!$reset) {
                $reset = true;
                $this->resetFixture($fixture);
            }
            return $url;
        });
        try {
            $response = $this->get('/api/v1/client/distributor/claim/' . $claimToken)
                ->assertStatus(410)->assertContent('');
        } finally {
            HookManager::remove('subscribe.url');
        }
        $this->assertTrue($reset);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_hwid_denial_is_not_cacheable(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $response = $this->get('/s/' . $fixture['subscriber']->token, ['User-Agent' => 'ClashMeta/1.0'])
            ->assertNotFound()->assertContent('');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    private function resetFixture(array $fixture): void
    {
        app(DistributorSubscriptionResetService::class)->reset(
            $fixture['distributor'],
            $fixture['order']->trade_no,
            DistributorSubscriptionResetService::credentialVersion($fixture['subscriber']),
            bin2hex(random_bytes(16))
        );
    }
}
