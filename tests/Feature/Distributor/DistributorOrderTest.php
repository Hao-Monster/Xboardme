<?php

namespace Tests\Feature\Distributor;

use App\Http\Resources\OrderResource;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\UserController as AdminUserController;
use App\Models\DistributorOrder;
use App\Models\DistributorHwidDevice;
use App\Models\Knowledge;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorConnectionService;
use App\Services\DistributorHwidService;
use App\Services\DistributorOrderService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

        $first = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY, '客户甲');
        $second = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY, '客户乙');

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
        $this->assertTrue($firstDelivery->hwid_enabled);
        $this->assertSame(1, $firstDelivery->hwid_limit);
        $this->assertSame('客户甲', $firstDelivery->customer_name);
        $this->assertSame('客户乙', $secondDelivery->customer_name);

        $distributor->refresh();
        $this->assertSame(50000, $distributor->balance);
        $this->assertSame(3000, $distributor->commission_balance);
        $this->assertNull($distributor->plan_id);
        $this->assertSame($originalExpiredAt, $distributor->expired_at);
    }

    public function test_distributor_checkout_allows_an_omitted_or_blank_customer_name_and_trims_an_optional_name(): void
    {
        $distributor = $this->makeUser('checkout-name@example.com', true);
        $plan = $this->makePlan();
        Sanctum::actingAs($distributor);

        $withoutNameTradeNo = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
        ])->assertOk()->json('data');
        $this->assertNull(Order::where('trade_no', $withoutNameTradeNo)->firstOrFail()
            ->distributorOrder()->value('customer_name'));

        $blankNameTradeNo = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'customer_name' => '   ',
        ])->assertOk()->json('data');
        $this->assertNull(Order::where('trade_no', $blankNameTradeNo)->firstOrFail()
            ->distributorOrder()->value('customer_name'));

        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'customer_name' => str_repeat('客', 65),
        ])->assertUnprocessable();

        $tradeNo = $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'customer_name' => '  终端客户甲  ',
        ])->assertOk()->json('data');

        $order = Order::where('trade_no', $tradeNo)->firstOrFail();
        $this->assertSame('终端客户甲', $order->distributorOrder()->value('customer_name'));
    }

    public function test_distributor_renews_the_same_active_subscription_and_idempotent_retries_do_not_extend_twice(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0, config('app.timezone')));
        try {
            $distributor = $this->makeUser('renew-active@example.com', true);
            $plan = $this->makePlan();
            $plan->update(['transfer_enable' => 1]);
            $rootOrder = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY, '续费客户');
            $delivery = $rootOrder->distributorOrder()->with('subscriber')->firstOrFail();
            $subscriber = $delivery->subscriber;
            $subscriber->update([
                'expired_at' => Carbon::create(2026, 10, 31, 12, 0, 0, config('app.timezone'))->timestamp,
                'u' => 100,
                'd' => 200,
            ]);
            $originalToken = $subscriber->token;
            $originalUuid = $subscriber->uuid;
            $idempotencyKey = '123e4567-e89b-42d3-a456-426614174000';
            Sanctum::actingAs($distributor);

            $payload = [
                'trade_no' => $rootOrder->trade_no,
                'period' => 'quarter_price',
                'idempotency_key' => $idempotencyKey,
            ];
            $first = $this->postJson('/api/v1/user/order/renew', $payload)
                ->assertOk()
                ->assertJsonPath('data.subscription_trade_no', $rootOrder->trade_no)
                ->assertJsonPath('data.period', 'quarter_price')
                ->assertJsonPath('data.total_amount', 3000)
                ->assertJsonPath(
                    'data.expired_at_after',
                    Carbon::create(2027, 1, 31, 12, 0, 0, config('app.timezone'))->timestamp
                );
            $renewalTradeNo = $first->json('data.trade_no');

            $this->postJson('/api/v1/user/order/renew', $payload)
                ->assertOk()
                ->assertJsonPath('data.trade_no', $renewalTradeNo);

            $renewal = Order::where('trade_no', $renewalTradeNo)->firstOrFail();
            $this->assertSame(Order::TYPE_RENEWAL, $renewal->type);
            $this->assertSame(Order::STATUS_COMPLETED, $renewal->status);
            $this->assertNull($renewal->paid_at);
            $this->assertSame($delivery->id, $renewal->distributor_order_id);
            $this->assertSame($idempotencyKey, $renewal->distributor_idempotency_key);
            $this->assertSame(2, Order::where('user_id', $distributor->id)->count());
            $this->assertSame(1, DistributorOrder::where('subscriber_user_id', $subscriber->id)->count());

            $subscriber->refresh();
            $this->assertSame($originalToken, $subscriber->token);
            $this->assertSame($originalUuid, $subscriber->uuid);
            $this->assertSame(100, $subscriber->u);
            $this->assertSame(200, $subscriber->d);
            $this->assertSame(
                Carbon::create(2027, 1, 31, 12, 0, 0, config('app.timezone'))->timestamp,
                $subscriber->expired_at
            );
            $this->assertSame(1, DistributorOrder::count());

            $this->getJson('/api/v1/user/order/fetch?' . http_build_query([
                'search' => $rootOrder->trade_no,
            ]))->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('data.0.trade_no', $renewalTradeNo)
                ->assertJsonPath('data.0.order_type_label', '续费')
                ->assertJsonPath('data.0.subscription_trade_no', $rootOrder->trade_no)
                ->assertJsonPath('data.0.is_subscription_origin', false)
                ->assertJsonPath('data.0.can_view_subscription_qr', false)
                ->assertJsonPath('data.1.trade_no', $rootOrder->trade_no)
                ->assertJsonPath('data.1.can_renew', true);

            $rows = $this->readXlsx($this->get('/api/v1/user/order/export?' . http_build_query([
                'search' => $rootOrder->trade_no,
            ]))->assertOk());
            $this->assertCount(3, $rows);
            $this->assertSame($renewalTradeNo, $rows[1][0]);
            $this->assertSame('续费', $rows[1][1]);
            $this->assertSame($rootOrder->trade_no, $rows[1][2]);

            $this->postJson('/api/v1/user/order/renew', [
                ...$payload,
                'period' => 'month_price',
            ])->assertStatus(409)
                ->assertJsonPath('message', '续费请求标识已用于其他续费操作');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_distributor_subscription_renews_from_now_and_resets_traffic(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0, config('app.timezone')));
        try {
            $distributor = $this->makeUser('renew-expired@example.com', true);
            $plan = $this->makePlan();
            $plan->update(['transfer_enable' => 1]);
            $rootOrder = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY);
            $subscriber = $rootOrder->distributorOrder()->with('subscriber')->firstOrFail()->subscriber;
            $subscriber->update([
                'expired_at' => Carbon::create(2026, 8, 1, 12, 0, 0, config('app.timezone'))->timestamp,
                'u' => 100,
                'd' => 200,
            ]);
            Sanctum::actingAs($distributor);

            $this->postJson('/api/v1/user/order/renew', [
                'trade_no' => $rootOrder->trade_no,
                'period' => 'month_price',
                'idempotency_key' => '123e4567-e89b-42d3-a456-426614174001',
            ])->assertOk()
                ->assertJsonPath(
                    'data.expired_at_after',
                    Carbon::create(2026, 9, 15, 12, 0, 0, config('app.timezone'))->timestamp
                );

            $subscriber->refresh();
            $this->assertSame(0, $subscriber->u);
            $this->assertSame(0, $subscriber->d);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_distributor_renewal_enforces_ownership_delivery_and_plan_rules(): void
    {
        $owner = $this->makeUser('renew-owner@example.com', true);
        $other = $this->makeUser('renew-other@example.com', true);
        $order = $this->createDistributorOrder($owner, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $payload = [
            'trade_no' => $order->trade_no,
            'period' => 'month_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614174002',
        ];

        Sanctum::actingAs($other);
        $this->postJson('/api/v1/user/order/renew', $payload)->assertNotFound();

        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $delivery->update(['delivery_status' => DistributorOrder::DELIVERY_CLOSED]);
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/user/order/renew', $payload)
            ->assertStatus(409)
            ->assertJsonPath('message', '已关闭的分销订阅不能续费');

        $delivery->update(['delivery_status' => DistributorOrder::DELIVERY_CLAIMED]);
        $delivery->subscriber->update(['expired_at' => null]);
        $this->postJson('/api/v1/user/order/renew', [
            ...$payload,
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614174003',
        ])->assertStatus(400)
            ->assertJsonPath('message', '长期有效订阅无需续费');

        $delivery->subscriber->update(['expired_at' => time() + 86400]);
        $order->plan->update(['renew' => false]);
        $this->postJson('/api/v1/user/order/renew', [
            ...$payload,
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614174005',
        ])->assertStatus(400)
            ->assertJsonPath('message', '该订阅无法续费，请更换其它订阅');

        $this->postJson('/api/v1/user/order/renew', [
            ...$payload,
            'period' => 'onetime_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614174006',
        ])->assertUnprocessable();
    }

    public function test_claim_url_can_only_be_consumed_once(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $claimToken = $delivery->claim_token;
        $claimPath = parse_url(route('client.distributor.claim', ['token' => $claimToken]), PHP_URL_PATH);

        $first = $this->get($claimPath . '?flag=meta');
        $first->assertRedirect(
            str_replace('#', '?flag=meta#', Helper::withSubscriptionRemark(
                Helper::getSubscribeUrl($delivery->subscriber->token),
                $order->trade_no
            ))
        );

        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNull($delivery->config_issued_at);
        $this->assertNull($delivery->claim_token);

        $this->get($claimPath)->assertStatus(410);

        $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
        $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
        $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
        $this->makeServer();
        $this->withHeaders(['X-HWID' => 'preview-device-01'])
            ->call('HEAD', $subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertStatus(405);
        $this->withHeaders(['X-HWID' => 'prefetch-device-01', 'Purpose' => 'prefetch'])
            ->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertStatus(425);
        $this->flushHeaders();
        $this->assertSame(0, DistributorHwidDevice::where('distributor_order_id', $delivery->id)->count());

        $this->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertNotFound()
            ->assertHeader('x-hwid-not-supported', 'true')
            ->assertHeaderMissing('x-order-no')
            ->assertHeaderMissing('profile-title');
        $this->assertNull($delivery->refresh()->config_issued_at);

        $response = $this->withHeaders([
            'User-Agent' => 'Happ/3.21.1 Android',
            'X-HWID' => 'legacy-device-001',
        ])
            ->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertOk()
            ->assertHeader('x-hwid-active', 'true')
            ->assertHeader('x-order-no', $order->trade_no);
        $this->assertSame(
            '订单号：' . $order->trade_no,
            base64_decode(substr((string) $response->headers->get('profile-title'), 7), true)
        );
        $this->assertOrderContentDisposition($response, $order->trade_no);
        $this->flushHeaders();

        $this->assertNotNull($delivery->refresh()->config_issued_at);
        $this->withHeaders(['X-HWID' => 'another-device-002'])
            ->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertNotFound()
            ->assertHeader('x-hwid-max-devices-reached', 'true')
            ->assertHeaderMissing('x-order-no')
            ->assertHeaderMissing('profile-title');
    }

    public function test_karing_receives_a_sing_box_config_with_real_server_outbounds(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');

        $order = $this->createDistributorOrder(
            $this->makeUser('karing-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY,
            'Karing customer'
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();

        $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
        $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
        $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
        $headers = [
            'User-Agent' => 'Karing/1.2.22.2502 Android',
            'X-HWID' => 'karing-device-001',
        ];
        $uri = $subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : '');

        $emptyResponse = $this->withHeaders($headers)->get($uri);
        $emptyResponse->assertOk()->assertHeader('x-hwid-active', 'true');
        $this->assertFalse(collect($emptyResponse->json('outbounds') ?? [])->contains(
            fn ($outbound) => ($outbound['type'] ?? null) === Server::TYPE_SOCKS
        ));
        $delivery->refresh();
        $this->assertSame(DistributorOrder::DELIVERY_CLAIMED, $delivery->delivery_status);
        $this->assertNotNull($delivery->claimed_at);
        $this->assertNull($delivery->config_issued_at);

        $this->makeServer();
        $response = $this->withHeaders($headers)->get($uri);

        $response->assertOk()
            ->assertHeader('x-hwid-active', 'true')
            ->assertHeader('x-order-no', $order->trade_no);
        $this->assertSame(
            '订单号：' . $order->trade_no,
            base64_decode(substr((string) $response->headers->get('profile-title'), 7), true)
        );
        $this->assertOrderContentDisposition($response, $order->trade_no);
        $config = $response->json();
        $this->assertIsArray($config);
        $this->assertTrue(collect($config['outbounds'] ?? [])->contains(
            fn ($outbound) => ($outbound['type'] ?? null) === Server::TYPE_SOCKS
                && ($outbound['tag'] ?? null) === 'HWID Test Node'
        ));
        $this->assertNotNull($delivery->refresh()->config_issued_at);
    }

    public function test_normal_subscription_does_not_receive_distributor_order_headers(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');

        $plan = $this->makePlan();
        $user = $this->makeUser('normal-subscriber@example.com', false, [
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 30 * 1073741824,
            'expired_at' => time() + 86400,
        ]);
        $this->makeServer();

        $subscribeUrl = Helper::getSubscribeUrl($user->token);
        $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
        $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
        $uri = $subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : '');

        $this->get($uri)
            ->assertOk()
            ->assertHeaderMissing('x-order-no')
            ->assertHeaderMissing('profile-title');

        $response = $this->withHeader('User-Agent', 'Karing/1.2.22.2502 Android')
            ->get($uri)
            ->assertOk()
            ->assertHeaderMissing('x-order-no');
        $this->assertSame(
            admin_setting('app_name', 'XBoard'),
            base64_decode(substr((string) $response->headers->get('profile-title'), 7), true)
        );
    }

    public function test_distributor_subscription_order_headers_do_not_cross_between_requests(): void
    {
        $plan = $this->makePlan();
        $firstOrder = $this->createDistributorOrder(
            $this->makeUser('first-header-dealer@example.com', true),
            $plan,
            Plan::PERIOD_MONTHLY,
            'First customer'
        );
        $secondOrder = $this->createDistributorOrder(
            $this->makeUser('second-header-dealer@example.com', true),
            $plan,
            Plan::PERIOD_MONTHLY,
            'Second customer'
        );
        $firstDelivery = $firstOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $secondDelivery = $secondOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $this->makeServer();

        $subscriptions = [
            [$firstOrder, $firstDelivery, 'isolated-device-a01'],
            [$secondOrder, $secondDelivery, 'isolated-device-b02'],
            [$firstOrder, $firstDelivery, 'isolated-device-a01'],
        ];

        foreach ($subscriptions as [$order, $delivery, $hwid]) {
            $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
            $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
            $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
            $response = $this->withHeaders([
                'User-Agent' => 'Happ/3.21.1 Android',
                'X-HWID' => $hwid,
            ])->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''));

            $response->assertOk()->assertHeader('x-order-no', $order->trade_no);
            $this->assertSame(
                '订单号：' . $order->trade_no,
                base64_decode(substr((string) $response->headers->get('profile-title'), 7), true)
            );
        }

        $this->assertNotSame($firstOrder->trade_no, $secondOrder->trade_no);
    }

    public function test_distributor_clash_subscription_uses_order_number_in_all_title_headers(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');

        $order = $this->createDistributorOrder(
            $this->makeUser('clash-title-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY,
            'Clash title customer'
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $this->makeServer();

        $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
        $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
        $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
        $response = $this->withHeaders([
            'User-Agent' => 'clash.meta/1.19.0',
            'X-HWID' => 'clash-title-device-001',
        ])->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''));

        $response->assertOk()->assertHeader('x-order-no', $order->trade_no);
        $this->assertSame(
            '订单号：' . $order->trade_no,
            base64_decode(substr((string) $response->headers->get('profile-title'), 7), true)
        );
        $this->assertOrderContentDisposition($response, $order->trade_no);
        $this->assertStringNotContainsString(
            rawurlencode(admin_setting('app_name', 'XBoard')),
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_delivery_returns_only_an_embedded_qr_and_never_exposes_the_subscription_as_plain_json(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('long-url-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with(['order', 'subscriber'])->firstOrFail();

        $data = app(DistributorOrderService::class)->deliveryData($delivery);
        $this->assertArrayNotHasKey('claim_url', $data);
        $this->assertArrayHasKey('qr_code', $data);
        $this->assertSame($order->plan_id, $data['plan_id']);
        $this->assertSame('Distributor Test Plan', $data['plan_name']);
        $this->assertSame('month_price', $data['period']);

        [, $encodedSvg] = explode(',', $data['qr_code'], 2);
        $svg = base64_decode($encodedSvg, true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('/client/distributor/claim/', $svg);

        $delivery->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'claimed_at' => time(),
        ]);
        $claimedData = app(DistributorOrderService::class)->deliveryData($delivery->fresh(['order', 'subscriber']));
        $this->assertArrayNotHasKey('qr_code', $claimedData);
        $this->assertFalse($claimedData['can_open']);
    }

    public function test_distributor_subscription_urls_use_order_number_as_karing_remark(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('karing-url-title-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with(['order', 'subscriber'])->firstOrFail();

        $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);

        $this->assertSame($order->trade_no, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
        $this->assertSame(
            parse_url(Helper::getSubscribeUrl($delivery->subscriber->token), PHP_URL_PATH),
            parse_url($url, PHP_URL_PATH)
        );
        $this->assertSame(
            Helper::getSubscribeUrl($delivery->subscriber->token),
            preg_replace('/#.*$/', '', $url)
        );
    }

    public function test_hwid_rejects_unsupported_and_extra_devices_but_allows_registered_device_updates(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('hwid-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $service = app(DistributorHwidService::class);

        $missing = $service->authorizeSubscription($delivery->subscriber, Request::create('/s/test'));
        $this->assertFalse($missing['allowed']);
        $this->assertSame('true', $missing['headers']['x-hwid-not-supported']);

        $firstRequest = Request::create('/s/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_HWID' => 'device-primary-001',
            'HTTP_X_DEVICE_OS' => 'iOS',
            'HTTP_X_VER_OS' => '18.1',
            'HTTP_X_DEVICE_MODEL' => 'iPhone',
            'HTTP_USER_AGENT' => 'Shadowrocket/2.2',
        ]);
        $first = $service->authorizeSubscription($delivery->subscriber, $firstRequest);
        $this->assertTrue($first['allowed']);
        $this->assertSame('true', $first['headers']['x-hwid-active']);
        $this->assertDatabaseHas('v2_distributor_hwid_device', [
            'distributor_order_id' => $delivery->id,
            'hwid' => 'device-primary-001',
            'device_os' => 'iOS',
            'device_model' => 'iPhone',
        ]);

        $this->assertTrue($service->authorizeSubscription($delivery->subscriber, $firstRequest)['allowed']);
        $this->assertSame(1, DistributorHwidDevice::where('distributor_order_id', $delivery->id)->count());

        $extraRequest = Request::create('/s/test', 'GET', [], [], [], [
            'HTTP_X_HWID' => 'device-secondary-02',
        ]);
        $extra = $service->authorizeSubscription($delivery->subscriber, $extraRequest);
        $this->assertFalse($extra['allowed']);
        $this->assertSame('true', $extra['headers']['x-hwid-max-devices-reached']);
        $this->assertSame('true', $extra['headers']['x-hwid-limit']);
    }

    public function test_admin_can_disable_change_search_and_delete_order_hwid_devices(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('hwid-admin-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $service = app(DistributorHwidService::class);
        $service->authorizeSubscription($delivery->subscriber, Request::create('/s/test', 'GET', [], [], [], [
            'HTTP_X_HWID' => 'searchable-device-01',
        ]));

        Sanctum::actingAs($this->makeUser('hwid-admin@example.com', false, ['is_admin' => true]));
        $this->postJson('/' . $this->adminRouteUri('updateHwid'), [
            'order_id' => $order->id,
            'enabled' => false,
            'limit' => 3,
        ])->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.limit', 3)
            ->assertJsonPath('data.registered_count', 1);

        $devices = $this->getJson('/' . $this->adminRouteUri('hwidDevices') . '?' . http_build_query([
            'order_id' => $order->id,
            'search' => 'searchable',
        ]))->assertOk()->json('data');
        $this->assertCount(1, $devices);

        $this->postJson('/' . $this->adminRouteUri('deleteHwidDevice'), [
            'order_id' => $order->id,
            'device_id' => $devices[0]['id'],
        ])->assertOk();
        $this->assertDatabaseMissing('v2_distributor_hwid_device', ['id' => $devices[0]['id']]);

        $disabledRequest = Request::create('/s/test');
        $this->assertTrue($service->authorizeSubscription($delivery->subscriber, $disabledRequest)['allowed']);
    }

    public function test_first_positive_node_traffic_records_connection_once_after_configuration_is_issued(): void
    {
        $order = $this->createDistributorOrder(
            $this->makeUser('traffic-dealer@example.com', true),
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $delivery = $order->distributorOrder()->firstOrFail();
        $service = app(DistributorConnectionService::class);

        $service->recordFirstTraffic(['id' => 8, 'name' => '东京 A'], [
            $delivery->subscriber_user_id => [100, 200],
        ]);
        $this->assertNull($delivery->refresh()->connected_at);

        $delivery->update(['config_issued_at' => time()]);
        $service->recordFirstTraffic(['id' => 8, 'name' => '东京 A'], [
            $delivery->subscriber_user_id => [0, 0],
        ]);
        $this->assertNull($delivery->refresh()->connected_at);

        $service->recordFirstTraffic(['id' => 8, 'name' => '东京 A'], [
            $delivery->subscriber_user_id => [1, 0],
        ]);
        $delivery->refresh();
        $this->assertNotNull($delivery->connected_at);
        $this->assertSame(8, $delivery->connected_node_id);
        $this->assertSame('东京 A', $delivery->connected_node_name);

        $service->recordFirstTraffic(['id' => 9, 'name' => '新加坡 B'], [
            $delivery->subscriber_user_id => [1, 1],
        ]);
        $this->assertSame('东京 A', $delivery->refresh()->connected_node_name);
    }

    public function test_distributor_restricted_route_keeps_its_forbidden_api_contract(): void
    {
        Sanctum::actingAs($this->makeUser('restricted-dealer@example.com', true));

        $this->getJson('/api/v1/user/getSubscribe')
            ->assertForbidden()
            ->assertJsonPath('message', '分销商账号无权访问该功能');
    }

    public function test_distributor_cannot_access_normal_subscription_api_and_order_resource_never_exposes_real_token(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $plan = $this->makePlan();
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/getSubscribe')->assertForbidden();
        $this->getJson('/api/v1/user/plan/fetch')->assertOk();

        $order = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY);
        $order->load(['plan', 'distributorOrder']);
        $resource = (new OrderResource($order))->toArray(Request::create('/'));
        $subscriber = $order->distributorOrder->subscriber()->firstOrFail();

        $encoded = json_encode($resource);
        $this->assertStringNotContainsString($subscriber->token, $encoded);
        $this->assertStringNotContainsString($subscriber->uuid, $encoded);
        $this->assertArrayNotHasKey('subscribe_url', $resource);
        $this->assertTrue($resource['is_distributor_order']);
        $this->assertSame($plan->id, $resource['subscription_entitlement']['plan_id']);
        $this->assertSame(30 * 1073741824, $resource['subscription_entitlement']['transfer_enable']);
        $this->assertArrayNotHasKey('subscriber_user_id', $resource['subscription_entitlement']);
        $this->assertArrayNotHasKey('token', $resource['subscription_entitlement']);
        $this->assertArrayNotHasKey('uuid', $resource['subscription_entitlement']);
    }

    public function test_distributor_can_read_public_knowledge_without_unlocking_subscription_only_content(): void
    {
        $distributor = $this->makeUser('dealer-docs@example.com', true);
        $knowledge = Knowledge::create([
            'language' => 'zh-CN',
            'category' => '使用文档',
            'title' => '快速开始',
            'body' => "# 快速开始\n\n公开内容\n\n<!--access start-->订阅用户专属内容<!--access end-->",
            'sort' => 1,
            'show' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        Sanctum::actingAs($distributor);

        $list = $this->getJson('/api/v1/user/knowledge/fetch?language=zh-CN');
        $list->assertOk();
        $this->assertSame('快速开始', $list->json('data.使用文档.0.title'));

        $detail = $this->getJson('/api/v1/user/knowledge/fetch?id=' . $knowledge->id . '&language=zh-CN&render=html');
        $detail->assertOk();
        $body = (string) $detail->json('data.body');
        $this->assertStringContainsString('<h1>快速开始</h1>', $body);
        $this->assertStringContainsString('公开内容', $body);
        $this->assertStringNotContainsString('订阅用户专属内容', $body);

        $this->getJson('/api/v1/user/getSubscribe')->assertForbidden();
    }

    public function test_distributor_order_fetch_exposes_read_only_entitlement_without_credentials(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $order = $this->createDistributorOrder(
            $distributor,
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $subscriber = $order->distributorOrder()->with('subscriber')->firstOrFail()->subscriber;
        $subscriber->update([
            'u' => 2 * 1073741824,
            'd' => 3 * 1073741824,
        ]);
        $otherOrder = $this->createDistributorOrder(
            $this->makeUser('other-dealer@example.com', true),
            Plan::findOrFail($order->plan_id),
            Plan::PERIOD_MONTHLY
        );
        Sanctum::actingAs($distributor);

        $response = $this->getJson('/api/v1/user/order/fetch');

        $response->assertOk()
            ->assertJsonPath('data.0.trade_no', $order->trade_no)
            ->assertJsonPath('data.0.subscription_entitlement.plan_name', 'Distributor Test Plan')
            ->assertJsonPath('data.0.subscription_entitlement.used_traffic', 5 * 1073741824)
            ->assertJsonPath('data.0.subscription_entitlement.remaining_traffic', 25 * 1073741824);

        $encoded = $response->getContent();
        $this->assertStringNotContainsString($subscriber->token, $encoded);
        $this->assertStringNotContainsString($subscriber->uuid, $encoded);
        $this->assertStringNotContainsString($subscriber->email, $encoded);
        $this->assertStringNotContainsString($otherOrder->trade_no, $encoded);
    }

    public function test_distributor_order_fetch_and_subscription_qr_expose_all_bound_hwids_without_plain_credentials(): void
    {
        $distributor = $this->makeUser('qr-owner@example.com', true);
        $order = $this->createDistributorOrder($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $delivery->update(['hwid_limit' => 3]);
        foreach ([
            ['hwid' => 'device-primary-001', 'last_seen_at' => 100],
            ['hwid' => 'device-secondary-02', 'device_model' => 'vivo V2227A', 'last_seen_at' => 200],
        ] as $device) {
            DistributorHwidDevice::create($device + [
                'distributor_order_id' => $delivery->id,
                'first_seen_at' => 50,
            ]);
        }
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/order/fetch')
            ->assertOk()
            ->assertJsonPath('data.0.trade_no', $order->trade_no)
            ->assertJsonPath('data.0.hwid_enabled', true)
            ->assertJsonPath('data.0.hwid_limit', 3)
            ->assertJsonPath('data.0.bound_devices.0', 'vivo V2227A device-secondary-02')
            ->assertJsonPath('data.0.bound_devices.1', 'device-primary-001')
            ->assertJsonPath('data.0.can_view_subscription_qr', true);

        $deliveryResponse = $this->getJson('/api/v1/user/distributor/delivery?' . http_build_query([
            'trade_no' => $order->trade_no,
        ]))->assertOk()
            ->assertJsonPath('data.trade_no', $order->trade_no)
            ->assertJsonPath('data.customer_name', '测试客户')
            ->assertJsonPath('data.hwid_devices.0', 'vivo V2227A device-secondary-02')
            ->assertJsonPath('data.hwid_devices.1', 'device-primary-001');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $deliveryResponse->json('data.qr_code'));
        $this->assertArrayNotHasKey('subscribe_url', $deliveryResponse->json('data'));
        $this->assertStringNotContainsString($delivery->subscriber->token, $deliveryResponse->getContent());

        $response = $this->getJson('/api/v1/user/distributor/subscription-qr?' . http_build_query([
            'trade_no' => $order->trade_no,
        ]))->assertOk()
            ->assertJsonPath('data.trade_no', $order->trade_no)
            ->assertJsonPath('data.customer_name', '测试客户')
            ->assertJsonPath('data.hwid_enabled', true)
            ->assertJsonPath('data.hwid_devices.0', 'vivo V2227A device-secondary-02')
            ->assertJsonPath('data.hwid_devices.1', 'device-primary-001');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_code'));
        $this->assertArrayNotHasKey('subscribe_url', $response->json('data'));
        $this->assertStringNotContainsString($delivery->subscriber->token, $response->getContent());
    }

    public function test_subscription_qr_is_owner_only_and_reports_disabled_unbound_and_missing_subscription_states(): void
    {
        $owner = $this->makeUser('qr-state-owner@example.com', true);
        $other = $this->makeUser('qr-state-other@example.com', true);
        $order = $this->createDistributorOrder($owner, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $uri = '/api/v1/user/distributor/subscription-qr?' . http_build_query(['trade_no' => $order->trade_no]);

        Sanctum::actingAs($other);
        $this->getJson($uri)->assertNotFound();

        Sanctum::actingAs($this->makeUser('qr-normal@example.com'));
        $this->getJson($uri)->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson($uri)->assertOk()
            ->assertJsonPath('data.hwid_enabled', true)
            ->assertJsonCount(0, 'data.hwid_devices');

        $delivery->update(['hwid_enabled' => false]);
        $this->getJson($uri)->assertOk()
            ->assertJsonPath('data.hwid_enabled', false)
            ->assertJsonCount(0, 'data.hwid_devices');

        $delivery->subscriber->update(['token' => '']);
        $this->getJson('/api/v1/user/order/fetch')
            ->assertOk()
            ->assertJsonPath('data.0.can_view_subscription_qr', false);
        $this->getJson($uri)->assertStatus(409)
            ->assertJsonPath('message', '订阅尚未生成');
    }

    public function test_distributor_can_search_only_their_orders_by_number_or_customer_name_and_export_the_result(): void
    {
        $distributor = $this->makeUser('search-own@example.com', true);
        $otherDistributor = $this->makeUser('search-other@example.com', true);
        $plan = $this->makePlan();
        $namedOrder = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY, '售后客户张三');
        $numberOrder = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY, '客户李四');
        $otherOrder = $this->createDistributorOrder($otherDistributor, $plan, Plan::PERIOD_MONTHLY, '售后客户张三');
        $namedOrder->distributorOrder()->update([
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
        ]);
        $namedOrder->update(['paid_at' => time()]);
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/order/fetch?' . http_build_query([
            'search' => '张三',
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $namedOrder->trade_no)
            ->assertJsonPath('data.0.customer_name', '售后客户张三');

        $this->getJson('/api/v1/user/order/fetch?' . http_build_query([
            'search' => substr($numberOrder->trade_no, -8),
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $numberOrder->trade_no);

        $this->getJson('/api/v1/user/order/fetch?' . http_build_query([
            'search' => $otherOrder->trade_no,
        ]))->assertOk()->assertJsonCount(0, 'data');

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export?' . http_build_query([
            'search' => '李四',
        ]))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame($numberOrder->trade_no, $rows[1][0]);
        $this->assertSame('客户李四', $rows[1][3]);
    }

    public function test_admin_can_search_distributor_orders_by_number_customer_name_or_subscription_link(): void
    {
        $firstDistributor = $this->makeUser('admin-search-first@example.com', true);
        $secondDistributor = $this->makeUser('admin-search-second@example.com', true);
        $plan = $this->makePlan();
        $firstOrder = $this->createDistributorOrder($firstDistributor, $plan, Plan::PERIOD_MONTHLY, '链接查询客户');
        $secondOrder = $this->createDistributorOrder($secondDistributor, $plan, Plan::PERIOD_MONTHLY, '普通查询客户');
        $firstDelivery = $firstOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $subscribeUrl = Helper::getSubscribeUrl($firstDelivery->subscriber->token);

        Sanctum::actingAs($this->makeUser('admin-search@example.com', false, ['is_admin' => true]));
        $fetchUri = '/' . $this->adminRouteUri('fetch');

        foreach ([
            [substr($secondOrder->trade_no, -8), $secondOrder->trade_no],
            ['链接查询', $firstOrder->trade_no],
            [$subscribeUrl, $firstOrder->trade_no],
            [$firstDelivery->subscriber->token, $firstOrder->trade_no],
        ] as [$search, $expectedTradeNo]) {
            $this->postJson($fetchUri, [
                'current' => 1,
                'pageSize' => 20,
                'distributor_only' => true,
                'search' => $search,
            ])->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.trade_no', $expectedTradeNo);
        }

        $this->postJson($fetchUri, [
            'current' => 1,
            'pageSize' => 20,
            'distributor_only' => true,
            'distributor_user_id' => $firstDistributor->id,
            'search' => '查询客户',
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $firstOrder->trade_no);

        $rows = $this->readXlsx($this->get('/' . $this->adminRouteUri('export') . '?' . http_build_query([
            'search' => $subscribeUrl,
        ]))->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame($firstOrder->trade_no, $rows[1][0]);
        $this->assertSame('链接查询客户', $rows[1][3]);
    }

    public function test_admin_can_save_trim_and_clear_a_distributor_order_remark_while_distributor_can_only_read_it(): void
    {
        $distributor = $this->makeUser('remark-dealer@example.com', true);
        $order = $this->createDistributorOrder(
            $distributor,
            $this->makePlan(),
            Plan::PERIOD_MONTHLY,
            '备注客户'
        );
        $admin = $this->makeUser('remark-admin@example.com', false, ['is_admin' => true]);
        $uri = '/' . $this->adminRouteUri('updateRemark');

        Sanctum::actingAs($admin);
        $this->postJson($uri, [
            'order_id' => $order->id,
            'remark' => "  线下补款\n下次结算核对  ",
        ])->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.remark', "线下补款\n下次结算核对");

        $this->assertSame(
            "线下补款\n下次结算核对",
            $order->distributorOrder()->value('remark')
        );
        $this->postJson('/' . $this->adminRouteUri('fetch'), [
            'current' => 1,
            'pageSize' => 10,
            'distributor_only' => true,
        ])->assertOk()
            ->assertJsonPath('data.0.remark', "线下补款\n下次结算核对");

        $this->postJson($uri, [
            'order_id' => $order->id,
            'remark' => str_repeat('备', 501),
        ])->assertUnprocessable();
        $this->postJson($uri, [
            'order_id' => 999999,
            'remark' => '不存在的订单',
        ])->assertNotFound()
            ->assertJsonPath('message', '分销订单不存在');
        $this->postJson($uri, [
            'order_id' => $order->id,
            'remark' => '   ',
        ])->assertOk()
            ->assertJsonPath('data.remark', null);
        $this->assertNull($order->distributorOrder()->value('remark'));

        $order->distributorOrder()->update(['remark' => '管理员共享备注']);
        Sanctum::actingAs($distributor);
        $this->getJson('/api/v1/user/order/fetch')->assertOk()
            ->assertJsonPath('data.0.trade_no', $order->trade_no)
            ->assertJsonPath('data.0.remark', '管理员共享备注');
        $this->postJson($uri, [
            'order_id' => $order->id,
            'remark' => '分销商不得修改',
        ])->assertForbidden();
        $this->assertSame('管理员共享备注', $order->distributorOrder()->value('remark'));
    }

    public function test_admin_xlsx_export_contains_only_distributor_orders_with_exact_columns_and_numeric_amounts(): void
    {
        $firstDistributor = $this->makeUser('first-dealer@example.com', true, ['distributor_name' => '第一分销商']);
        $secondDistributor = $this->makeUser('second-dealer@example.com', true, ['distributor_name' => '第二分销商']);
        $plan = $this->makePlan();
        $older = $this->createDistributorOrder($firstDistributor, $plan, Plan::PERIOD_MONTHLY, '老客户');
        $newer = $this->createDistributorOrder($secondDistributor, $plan, Plan::PERIOD_QUARTERLY, '新客户');
        $older->distributorOrder()->update(['remark' => '旧订单备注']);
        $newer->distributorOrder()->update(['remark' => '=HYPERLINK("https://invalid.example","新订单备注")']);
        $older->update(['created_at' => 100]);
        $newer->update(['created_at' => 200]);

        Order::create([
            'user_id' => $this->makeUser('consumer@example.com')->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'NORMAL-ORDER-MUST-NOT-EXPORT',
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);

        Sanctum::actingAs($this->makeUser('admin-export@example.com', false, ['is_admin' => true]));
        $response = $this->get('/' . $this->adminRouteUri('export'));
        $response->assertOk();

        $rows = $this->readXlsx($response);
        $this->assertSame(['订单号', '订单类型', '关联原订单', '用户名称', '分销商', '套餐', '周期', '原价', '结算状态', '备注'], $rows[0]);
        $this->assertSame($newer->trade_no, $rows[1][0]);
        $this->assertSame('新购', $rows[1][1]);
        $this->assertSame('-', $rows[1][2]);
        $this->assertSame('新客户', $rows[1][3]);
        $this->assertSame('第二分销商', $rows[1][4]);
        $this->assertSame('Distributor Test Plan', $rows[1][5]);
        $this->assertSame('季付', $rows[1][6]);
        $this->assertTrue(is_int($rows[1][7]) || is_float($rows[1][7]));
        $this->assertEquals(30.0, $rows[1][7]);
        $this->assertSame('未结算', $rows[1][8]);
        $this->assertSame('=HYPERLINK("https://invalid.example","新订单备注")', $rows[1][9]);
        $this->assertSame($older->trade_no, $rows[2][0]);
        $this->assertSame('旧订单备注', $rows[2][9]);
        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('NORMAL-ORDER-MUST-NOT-EXPORT', json_encode($rows));
    }

    public function test_admin_xlsx_export_applies_distributor_and_settlement_filters_and_rejects_empty_results(): void
    {
        $firstDistributor = $this->makeUser('filter-dealer@example.com', true);
        $secondDistributor = $this->makeUser('other-filter-dealer@example.com', true);
        $plan = $this->makePlan();
        $unsettled = $this->createDistributorOrder($firstDistributor, $plan, Plan::PERIOD_MONTHLY);
        $settled = $this->createDistributorOrder($firstDistributor, $plan, Plan::PERIOD_MONTHLY);
        $settled->distributorOrder()->update(['settlement_status' => DistributorOrder::SETTLEMENT_SETTLED]);
        $settled->update(['paid_at' => time()]);
        $this->createDistributorOrder($secondDistributor, $plan, Plan::PERIOD_MONTHLY);

        Sanctum::actingAs($this->makeUser('admin-filter@example.com', false, ['is_admin' => true]));
        $uri = '/' . $this->adminRouteUri('export');
        $response = $this->get($uri . '?' . http_build_query([
            'distributor_user_id' => $firstDistributor->id,
            'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
        ]));

        $rows = $this->readXlsx($response->assertOk());
        $this->assertCount(2, $rows);
        $this->assertSame($unsettled->trade_no, $rows[1][0]);

        $this->getJson($uri . '?' . http_build_query([
            'distributor_user_id' => 999999,
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
        ]))->assertStatus(422)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('message', '当前筛选条件下没有可导出的订单');
    }

    public function test_distributor_settlement_filter_and_xlsx_export_are_limited_to_the_authenticated_distributor(): void
    {
        $distributor = $this->makeUser('own-orders@example.com', true);
        $otherDistributor = $this->makeUser('other-orders@example.com', true);
        $plan = $this->makePlan();
        $unsettled = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY);
        $settled = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_QUARTERLY, '导出客户');
        $settled->distributorOrder()->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
            'remark' => '分销商可见备注',
        ]);
        $settled->update(['paid_at' => time()]);
        $otherOrder = $this->createDistributorOrder($otherDistributor, $plan, Plan::PERIOD_QUARTERLY);

        Sanctum::actingAs($distributor);
        $this->getJson('/api/v1/user/order/fetch?settlement_status=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $settled->trade_no);

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export?settlement_status=1')->assertOk());
        $this->assertSame(['订单号', '订单类型', '关联原订单', '用户名称', '订阅计划', '周期', '订单金额', '结算状态', '备注'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame($settled->trade_no, $rows[1][0]);
        $this->assertSame('新购', $rows[1][1]);
        $this->assertSame('-', $rows[1][2]);
        $this->assertSame('导出客户', $rows[1][3]);
        $this->assertSame('Distributor Test Plan', $rows[1][4]);
        $this->assertSame('季付', $rows[1][5]);
        $this->assertTrue(is_int($rows[1][6]) || is_float($rows[1][6]));
        $this->assertEquals(30.0, $rows[1][6]);
        $this->assertSame('已结算', $rows[1][7]);
        $this->assertSame('分销商可见备注', $rows[1][8]);
        $this->assertStringNotContainsString($unsettled->trade_no, json_encode($rows));
        $this->assertStringNotContainsString($otherOrder->trade_no, json_encode($rows));

        $subscriber = $settled->distributorOrder()->with('subscriber')->firstOrFail()->subscriber;
        $encoded = json_encode($rows);
        $this->assertStringNotContainsString($subscriber->token, $encoded);
        $this->assertStringNotContainsString($subscriber->uuid, $encoded);
    }

    public function test_normal_user_cannot_export_distributor_orders(): void
    {
        Sanctum::actingAs($this->makeUser('normal-export@example.com'));

        $this->get('/api/v1/user/order/export')->assertForbidden();
    }

    public function test_admin_can_update_only_live_entitlement_for_any_distributor_order_state(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $plan = $this->makePlan();
        $otherPlan = Plan::create([
            'group_id' => 2,
            'transfer_enable' => 100,
            'name' => 'Forbidden Replacement Plan',
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 2,
            'prices' => [Plan::PERIOD_MONTHLY => 50],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
        $order = $this->createDistributorOrder($distributor, $plan, Plan::PERIOD_MONTHLY);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $subscriber = $delivery->subscriber;
        $originalToken = $subscriber->token;
        $originalUuid = $subscriber->uuid;
        $originalAmount = $order->total_amount;
        $originalPeriod = $order->period;
        $subscriber->update([
            'u' => 4 * 1073741824,
            'd' => 3 * 1073741824,
        ]);
        $delivery->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLOSED,
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
            'settled_at' => time(),
        ]);
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $admin = $this->makeUser('admin@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);
        $expiredAt = time() + 86400;

        $response = $this->postJson('/' . $this->adminRouteUri('updateEntitlement'), [
            'order_id' => $order->id,
            'transfer_enable' => 5 * 1073741824,
            'expired_at' => $expiredAt,
            'speed_limit' => 20,
            'device_limit' => 2,
            'plan_id' => $otherPlan->id,
            'u' => 0,
            'd' => 0,
            'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
            'total_amount' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.plan_id', $plan->id)
            ->assertJsonPath('data.transfer_enable', 5 * 1073741824)
            ->assertJsonPath('data.used_traffic', 7 * 1073741824)
            ->assertJsonPath('data.remaining_traffic', 0)
            ->assertJsonPath('data.expired_at', $expiredAt)
            ->assertJsonPath('data.speed_limit', 20)
            ->assertJsonPath('data.device_limit', 2);

        $subscriber->refresh();
        $this->assertSame($plan->id, $subscriber->plan_id);
        $this->assertSame(4 * 1073741824, $subscriber->u);
        $this->assertSame(3 * 1073741824, $subscriber->d);
        $this->assertSame($originalToken, $subscriber->token);
        $this->assertSame($originalUuid, $subscriber->uuid);

        $order->refresh();
        $delivery->refresh();
        $this->assertSame($plan->id, $order->plan_id);
        $this->assertSame($originalAmount, $order->total_amount);
        $this->assertSame($originalPeriod, $order->period);
        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertSame(DistributorOrder::SETTLEMENT_SETTLED, $delivery->settlement_status);
    }

    public function test_entitlement_update_rejects_normal_orders_and_invalid_values(): void
    {
        $user = $this->makeUser('user@example.com');
        $plan = $this->makePlan();
        $order = Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);
        Sanctum::actingAs($this->makeUser('admin@example.com', false, ['is_admin' => true]));
        $uri = '/' . $this->adminRouteUri('updateEntitlement');

        $this->postJson($uri, [
            'order_id' => $order->id,
            'transfer_enable' => 1073741824,
            'expired_at' => null,
            'speed_limit' => null,
            'device_limit' => null,
        ])->assertStatus(422);

        $distributorOrder = $this->createDistributorOrder(
            $this->makeUser('dealer@example.com', true),
            $plan,
            Plan::PERIOD_MONTHLY
        );
        $this->postJson($uri, [
            'order_id' => $distributorOrder->id,
            'transfer_enable' => -1,
            'expired_at' => null,
            'speed_limit' => -1,
            'device_limit' => -1,
        ])->assertStatus(422);

        $delivery = $distributorOrder->distributorOrder()->firstOrFail();
        User::whereKey($delivery->subscriber_user_id)->delete();
        $this->postJson($uri, [
            'order_id' => $distributorOrder->id,
            'transfer_enable' => 1073741824,
            'expired_at' => null,
            'speed_limit' => null,
            'device_limit' => null,
        ])->assertStatus(422);
    }

    public function test_claimed_delivery_is_recovered_until_the_real_subscription_response_is_issued(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $order = $this->createDistributorOrder($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $delivery = $order->distributorOrder()->firstOrFail();
        $delivery->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'claimed_at' => time(),
            'claim_token' => null,
            'config_issued_at' => null,
        ]);
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/distributor/delivery')
            ->assertOk()
            ->assertJsonPath('data.trade_no', $order->trade_no)
            ->assertJsonPath('data.config_issued_at', null);

        $delivery->update(['config_issued_at' => time()]);

        $this->getJson('/api/v1/user/distributor/delivery')->assertNotFound();
    }

    public function test_admin_settlement_is_idempotent_and_reveals_subscription_only_in_detail(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $order = $this->createDistributorOrder($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY, '详情客户');
        $renewal = app(DistributorOrderService::class)->renew(
            $distributor,
            $order->trade_no,
            Plan::PERIOD_QUARTERLY,
            '123e4567-e89b-42d3-a456-426614174004'
        );
        $normalOrder = Order::create([
            'user_id' => $this->makeUser('normal-unsettled@example.com')->id,
            'plan_id' => $order->plan_id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);
        $admin = $this->makeUser('admin@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);

        $previewUri = $this->adminRouteUri('settlementPreview');
        $settleUri = $this->adminRouteUri('settle');
        $detailUri = $this->adminRouteUri('detail');

        $unsettled = $this->postJson('/' . $this->adminRouteUri('fetch'), [
            'current' => 1,
            'pageSize' => 20,
            'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
        ])->assertOk()->json('data');
        $this->assertCount(2, $unsettled);
        $this->assertNotContains($normalOrder->trade_no, collect($unsettled)->pluck('trade_no')->all());

        $this->getJson('/' . $previewUri . '?distributor_user_id=' . $distributor->id)
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_amount', 6000);

        $this->postJson('/' . $settleUri, ['distributor_user_id' => $distributor->id])
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_amount', 6000);
        $this->postJson('/' . $settleUri, ['distributor_user_id' => $distributor->id])
            ->assertOk()
            ->assertJsonPath('data.count', 0);

        $order->refresh();
        $renewal->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($renewal->paid_at);
        $this->assertSame($admin->id, $renewal->distributor_settled_by);
        $this->assertSame(
            DistributorOrder::SETTLEMENT_SETTLED,
            $order->distributorOrder()->value('settlement_status')
        );

        $delivery = $order->distributorOrder()->with(['order', 'subscriber'])->firstOrFail();
        $this->postJson('/' . $detailUri, ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.customer_name', '详情客户')
            ->assertJsonPath(
                'data.subscribe_url',
                app(DistributorOrderService::class)->subscriptionUrl($delivery)
            );
        $this->postJson('/' . $detailUri, ['id' => $renewal->id])
            ->assertOk()
            ->assertJsonPath('data.order_type_label', '续费')
            ->assertJsonPath('data.subscription_trade_no', $order->trade_no)
            ->assertJsonPath(
                'data.subscribe_url',
                app(DistributorOrderService::class)->subscriptionUrl($delivery)
            );
    }

    public function test_distributor_role_can_be_added_without_revoking_admin_or_staff_roles(): void
    {
        $operator = $this->makeUser('operator@example.com', false, ['is_admin' => true]);
        $target = $this->makeUser('hybrid@example.com', false, [
            'is_admin' => true,
            'is_staff' => true,
        ]);
        Sanctum::actingAs($operator);

        $this->postJson('/' . $this->adminUserRouteUri('update'), [
            'id' => $target->id,
            'is_distributor' => true,
            'distributor_name' => '混合权限分销商',
        ])->assertOk();

        $target->refresh();
        $this->assertTrue($target->is_admin);
        $this->assertTrue($target->is_staff);
        $this->assertTrue($target->is_distributor);
        $this->assertSame('混合权限分销商', $target->distributor_name);

        $order = $this->createDistributorOrder($target, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $this->assertSame($target->id, $order->user_id);
    }

    public function test_distributor_name_is_required_managed_and_exposed_by_admin_apis(): void
    {
        $admin = $this->makeUser('name-admin@example.com', false, ['is_admin' => true]);
        $target = $this->makeUser('name-target@example.com');
        Sanctum::actingAs($admin);

        $updateUri = '/' . $this->adminUserRouteUri('update');
        $this->postJson($updateUri, [
            'id' => $target->id,
            'is_distributor' => true,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'fail');

        $this->postJson($updateUri, [
            'id' => $target->id,
            'is_distributor' => true,
            'distributor_name' => '  华东渠道  ',
        ])->assertOk();

        $target->refresh();
        $this->assertTrue($target->is_distributor);
        $this->assertSame('华东渠道', $target->distributor_name);

        $options = $this->getJson('/' . $this->adminUserRouteUri('distributorOptions'))
            ->assertOk()
            ->json('data');
        $option = collect($options)->firstWhere('id', $target->id);
        $this->assertSame('华东渠道', $option['distributor_name']);
        $this->assertSame($target->email, $option['email']);

        $order = $this->createDistributorOrder($target, $this->makePlan(), Plan::PERIOD_MONTHLY, '渠道客户');
        $this->postJson('/' . $this->adminRouteUri('fetch'), [
            'current' => 1,
            'pageSize' => 10,
            'distributor_only' => true,
        ])->assertOk()
            ->assertJsonPath('data.0.customer_name', '渠道客户')
            ->assertJsonPath('data.0.distributor_name', '华东渠道')
            ->assertJsonPath('data.0.distributor_email', $target->email);
        $this->postJson('/' . $this->adminRouteUri('detail'), ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.customer_name', '渠道客户')
            ->assertJsonPath('data.distributor_name', '华东渠道');

        $this->postJson($updateUri, [
            'id' => $target->id,
            'is_distributor' => false,
            'distributor_name' => '不应保留',
        ])->assertOk();
        $this->assertFalse($target->refresh()->is_distributor);
        $this->assertNull($target->distributor_name);
    }

    public function test_admin_can_create_a_named_distributor_and_blank_name_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser('generate-admin@example.com', false, ['is_admin' => true]));
        $uri = '/' . $this->adminUserRouteUri('generate');

        $this->postJson($uri, [
            'email_prefix' => 'blank-name-dealer',
            'email_suffix' => 'example.com',
            'is_distributor' => true,
            'distributor_name' => '   ',
        ])->assertUnprocessable();

        $this->postJson($uri, [
            'email_prefix' => 'named-dealer',
            'email_suffix' => 'example.com',
            'is_distributor' => true,
            'distributor_name' => '北区合作伙伴',
        ])->assertOk();

        $created = User::byEmail('named-dealer@example.com')->firstOrFail();
        $this->assertTrue($created->is_distributor);
        $this->assertSame('北区合作伙伴', $created->distributor_name);
    }

    public function test_admin_cannot_revoke_their_own_admin_role(): void
    {
        $admin = $this->makeUser('admin@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/' . $this->adminUserRouteUri('update'), [
            'id' => $admin->id,
            'is_admin' => false,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'fail');

        $this->assertTrue($admin->refresh()->is_admin);
    }

    private function adminRouteUri(string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($method) {
            return $route->getActionName() === AdminOrderController::class . '@' . $method;
        });

        $this->assertNotNull($route, 'Admin order route not found: ' . $method);
        return $route->uri();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsx($response): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = $response->baseResponse->getFile()->getPathname();
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path) === true);
        $styles = (string) $archive->getFromName('xl/styles.xml');
        $sheetXml = (string) $archive->getFromName('xl/worksheets/sheet1.xml');
        $archive->close();
        $this->assertStringContainsString('numFmtId="2"', $styles);
        $this->assertMatchesRegularExpression('/<autoFilter ref="A1:[A-Z]+[0-9]+"/', $sheetXml);
        $this->assertStringNotContainsString('<f>', $sheetXml);
        $this->assertStringContainsString('state="frozen"', $sheetXml);

        $reader = new Reader();
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(
                        static fn ($cell) => $cell->getValue(),
                        $row->getCells()
                    );
                }
                break;
            }
        } finally {
            $reader->close();
            @unlink($path);
        }

        return $rows;
    }

    private function adminUserRouteUri(string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(function ($route) use ($method) {
            return $route->getActionName() === AdminUserController::class . '@' . $method;
        });

        $this->assertNotNull($route, 'Admin user route not found: ' . $method);
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
            'prices' => [
                Plan::PERIOD_MONTHLY => 30,
                Plan::PERIOD_QUARTERLY => 30,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'HWID Test Node',
            'type' => Server::TYPE_SOCKS,
            'host' => '127.0.0.1',
            'port' => 1080,
            'server_port' => 1080,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ]);
    }

    private function createDistributorOrder(
        User $distributor,
        Plan $plan,
        string $period,
        string $customerName = '测试客户'
    ): Order {
        return app(DistributorOrderService::class)->create(
            $distributor,
            $plan,
            $period,
            $customerName
        );
    }

    private function assertOrderContentDisposition($response, string $tradeNo, string $extension = ''): void
    {
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('filename="' . $tradeNo . $extension . '"', $disposition);
        $this->assertStringContainsString(
            "filename*=UTF-8''" . rawurlencode('订单号：' . $tradeNo . $extension),
            $disposition
        );
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
