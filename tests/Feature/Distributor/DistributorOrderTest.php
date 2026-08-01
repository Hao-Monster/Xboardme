<?php

namespace Tests\Feature\Distributor;

use App\Http\Resources\OrderResource;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistributorOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_distributor_order_creates_an_independent_subscription_without_mutating_buyer(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true, [
            'balance' => 50000,
            'commission_balance' => 3000,
            'invite_user_id' => $this->makeUser('inviter@example.com')->id,
        ]);
        $plan = $this->makePlan();
        $originalExpiredAt = $distributor->expired_at;

        $first = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_MONTHLY);
        $second = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_MONTHLY);

        $firstDelivery = $first->distributorOrder()->with('subscriber')->firstOrFail();
        $secondDelivery = $second->distributorOrder()->with('subscriber')->firstOrFail();

        $this->assertSame(Order::STATUS_COMPLETED, $first->status);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, $first->type);
        $this->assertSame(3000, $first->total_amount);
        $this->assertNull($first->paid_at);
        $this->assertSame(0, $first->commission_balance);
        $this->assertNotSame($firstDelivery->subscriber_user_id, $secondDelivery->subscriber_user_id);
        $this->assertNotSame($firstDelivery->subscriber->token, $secondDelivery->subscriber->token);
        $this->assertNotSame($firstDelivery->subscriber->uuid, $secondDelivery->subscriber->uuid);
        $this->assertSame($plan->id, $firstDelivery->subscriber->plan_id);
        $this->assertSame(DistributorOrder::DELIVERY_PENDING, $firstDelivery->delivery_status);
        $this->assertSame(DistributorOrder::SETTLEMENT_UNSETTLED, $firstDelivery->settlement_status);

        $distributor->refresh();
        $this->assertSame(50000, $distributor->balance);
        $this->assertSame(3000, $distributor->commission_balance);
        $this->assertNull($distributor->plan_id);
        $this->assertSame($originalExpiredAt, $distributor->expired_at);
    }

    public function test_claim_url_can_only_be_consumed_once(): void
    {
        $order = app(DistributorOrderService::class)->create(
            $this->makeUser('dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $claimToken = $delivery->claim_token;
        $claimPath = parse_url(route('client.distributor.claim', ['token' => $claimToken]), PHP_URL_PATH);

        $first = $this->get($claimPath);
        $first->assertRedirect(Helper::getSubscribeUrl($delivery->subscriber->token));

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNotNull($delivery->config_issued_at);
        $this->assertNull($delivery->claim_token);

        $this->get($claimPath)->assertStatus(410);
    }

    public function test_distributor_cannot_access_normal_subscription_api_and_order_resource_never_exposes_real_token(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/getSubscribe')->assertForbidden();
        $this->getJson('/api/v1/user/plan/fetch')->assertOk();

        $order = app(DistributorOrderService::class)->create($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $order->load(['plan', 'distributorOrder']);
        $resource = (new OrderResource($order))->toArray(Request::create('/'));
        $subscriber = $order->distributorOrder->subscriber()->firstOrFail();

        $encoded = json_encode($resource);
        $this->assertStringNotContainsString($subscriber->token, $encoded);
        $this->assertStringNotContainsString($subscriber->uuid, $encoded);
        $this->assertArrayNotHasKey('subscribe_url', $resource);
        $this->assertTrue($resource['is_distributor_order']);
    }

    public function test_admin_settlement_is_idempotent_and_reveals_subscription_only_in_detail(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $order = app(DistributorOrderService::class)->create($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $admin = $this->makeUser('admin@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);

        $previewUri = $this->adminRouteUri('settlementPreview');
        $settleUri = $this->adminRouteUri('settle');
        $detailUri = $this->adminRouteUri('detail');

        $this->getJson('/' . $previewUri . '?distributor_user_id=' . $distributor->id)
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.total_amount', 3000);

        $this->postJson('/' . $settleUri, ['distributor_user_id' => $distributor->id])
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.total_amount', 3000);
        $this->postJson('/' . $settleUri, ['distributor_user_id' => $distributor->id])
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertSame(
            DistributorOrder::SETTLEMENT_SETTLED,
            $order->distributorOrder()->value('settlement_status')
        );

        $subscriber = $order->distributorOrder()->with('subscriber')->firstOrFail()->subscriber;
        $this->postJson('/' . $detailUri, ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.subscribe_url', Helper::getSubscribeUrl($subscriber->token));
    }

    private function adminRouteUri(string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($method) {
            return $route->getActionName() === AdminOrderController::class . '@' . $method;
        });

        $this->assertNotNull($route, 'Admin order route not found: ' . $method);
        return $route->uri();
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'group_id' => 1,
            'transfer_enable' => 30,
            'name' => 'Distributor Test Plan',
            'speed_limit' => 100,
            'device_limit' => 1,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
    }

    private function makeUser(string $email, bool $isDistributor = false, array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $isDistributor,
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ], $attributes));
    }
}
