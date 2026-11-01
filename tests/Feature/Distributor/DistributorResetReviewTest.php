<?php

namespace Tests\Feature\Distributor;

use App\Exceptions\ApiException;
use App\Http\Middleware\InitializePlugins;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\TestCase;

class DistributorResetReviewTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Bus::fake();
    }

    public function test_disabled_dealers_cannot_read_credentials_or_close_deliveries(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        foreach ([['is_distributor' => false, 'banned' => false], ['is_distributor' => true, 'banned' => true]] as $attributes) {
            $fixture['distributor']->update($attributes);
            Sanctum::actingAs($fixture['distributor']->fresh());
            $tradeNo = $fixture['order']->trade_no;
            $this->getJson('/api/v1/user/distributor/delivery?trade_no=' . $tradeNo)->assertForbidden();
            $this->getJson('/api/v1/user/distributor/subscription-qr?trade_no=' . $tradeNo)->assertForbidden();
            $this->postJson('/api/v1/user/distributor/delivery/batch', ['trade_nos' => [$tradeNo]])->assertForbidden();
            $this->postJson('/api/v1/user/distributor/delivery/close', ['trade_no' => $tradeNo, 'confirm' => true])->assertForbidden();
        }
        $this->assertSame($fixture['delivery']->delivery_status, $fixture['delivery']->fresh()->delivery_status);
    }

    public function test_missing_subscriber_cannot_produce_a_reset_qr(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $fixture['subscriber']->delete();
        $service = app(DistributorOrderService::class);
        $data = $service->deliveryData($fixture['delivery']->fresh(), false);
        $this->assertFalse($data['can_reset_subscription']);
        $this->assertNull($data['credential_version']);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('订阅尚未生成');
        $service->subscriptionQrData($fixture['delivery']->fresh());
    }

    public function test_reset_generates_portable_uuid_and_random_token(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $original = $fixture['subscriber']->only(['token', 'uuid']);
        app(DistributorSubscriptionResetService::class)->reset(
            $fixture['distributor'], $fixture['order']->trade_no,
            hash('sha256', $original['token']), (string) Str::uuid()
        );
        $subscriber = $fixture['subscriber']->fresh();
        $this->assertTrue(Str::isUuid($subscriber->uuid));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $subscriber->token);
        $this->assertNotSame($original['token'], $subscriber->token);
        $this->assertNotSame($original['uuid'], $subscriber->uuid);
    }
}
