<?php

namespace Tests\Feature\Distributor;

use App\Http\Resources\OrderResource;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\UserController as AdminUserController;
use App\Models\DistributorOrder;
use App\Models\DistributorHwidDevice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorConnectionService;
use App\Services\DistributorHwidService;
use App\Services\DistributorOrderService;
use App\Utils\Helper;
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

    public function test_distributor_checkout_requires_and_trims_customer_name(): void
    {
        $distributor = $this->makeUser('checkout-name@example.com', true);
        $plan = $this->makePlan();
        Sanctum::actingAs($distributor);

        foreach ([null, '   '] as $customerName) {
            $payload = [
                'plan_id' => $plan->id,
                'period' => 'month_price',
            ];
            if ($customerName !== null) {
                $payload['customer_name'] = $customerName;
            }

            $response = $this->postJson('/api/v1/user/order/save', $payload);
            $response->assertUnprocessable()
                ->assertJsonPath(
                    'errors.customer_name.0',
                    '为了售后方便，请输入备注清楚用户'
                );
        }

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

        $first = $this->get($claimPath);
        $first->assertRedirect(Helper::getSubscribeUrl($delivery->subscriber->token));

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
            ->assertHeader('x-hwid-not-supported', 'true');
        $this->assertNull($delivery->refresh()->config_issued_at);

        $this->withHeaders(['X-HWID' => 'legacy-device-001'])
            ->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertOk()
            ->assertHeader('x-hwid-active', 'true');
        $this->flushHeaders();

        $this->assertNotNull($delivery->refresh()->config_issued_at);
        $this->withHeaders(['X-HWID' => 'another-device-002'])
            ->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))
            ->assertNotFound()
            ->assertHeader('x-hwid-max-devices-reached', 'true');
    }

    public function test_karing_receives_a_sing_box_config_with_real_server_outbounds(): void
    {
        config(['cache.default' => 'array']);

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
        $this->assertNull($delivery->refresh()->config_issued_at);

        $this->makeServer();
        $response = $this->withHeaders($headers)->get($uri);

        $response->assertOk()->assertHeader('x-hwid-active', 'true');
        $config = $response->json();
        $this->assertIsArray($config);
        $this->assertTrue(collect($config['outbounds'] ?? [])->contains(
            fn ($outbound) => ($outbound['type'] ?? null) === Server::TYPE_SOCKS
                && ($outbound['tag'] ?? null) === 'HWID Test Node'
        ));
        $this->assertNotNull($delivery->refresh()->config_issued_at);
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

        [, $encodedSvg] = explode(',', $data['qr_code'], 2);
        $svg = base64_decode($encodedSvg, true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('/client/distributor/claim/', $svg);
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
        $this->assertSame('客户李四', $rows[1][1]);
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
        $this->assertSame('链接查询客户', $rows[1][1]);
    }

    public function test_admin_xlsx_export_contains_only_distributor_orders_with_exact_columns_and_numeric_amounts(): void
    {
        $firstDistributor = $this->makeUser('first-dealer@example.com', true, ['distributor_name' => '第一分销商']);
        $secondDistributor = $this->makeUser('second-dealer@example.com', true, ['distributor_name' => '第二分销商']);
        $plan = $this->makePlan();
        $older = $this->createDistributorOrder($firstDistributor, $plan, Plan::PERIOD_MONTHLY, '老客户');
        $newer = $this->createDistributorOrder($secondDistributor, $plan, Plan::PERIOD_QUARTERLY, '新客户');
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
        $this->assertSame(['订单号', '用户名称', '分销商', '套餐', '原价', '交付状态', '结算状态'], $rows[0]);
        $this->assertSame($newer->trade_no, $rows[1][0]);
        $this->assertSame('新客户', $rows[1][1]);
        $this->assertSame('第二分销商', $rows[1][2]);
        $this->assertSame('Distributor Test Plan', $rows[1][3]);
        $this->assertTrue(is_int($rows[1][4]) || is_float($rows[1][4]));
        $this->assertEquals(30.0, $rows[1][4]);
        $this->assertSame('待领取', $rows[1][5]);
        $this->assertSame('未结算', $rows[1][6]);
        $this->assertSame($older->trade_no, $rows[2][0]);
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
        ]);
        $otherOrder = $this->createDistributorOrder($otherDistributor, $plan, Plan::PERIOD_QUARTERLY);

        Sanctum::actingAs($distributor);
        $this->getJson('/api/v1/user/order/fetch?settlement_status=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $settled->trade_no);

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export?settlement_status=1')->assertOk());
        $this->assertSame(['订单号', '用户名称', '订阅计划', '周期', '订单金额', '交付状态', '结算状态'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame($settled->trade_no, $rows[1][0]);
        $this->assertSame('导出客户', $rows[1][1]);
        $this->assertSame('Distributor Test Plan', $rows[1][2]);
        $this->assertSame('季付', $rows[1][3]);
        $this->assertTrue(is_int($rows[1][4]) || is_float($rows[1][4]));
        $this->assertEquals(30.0, $rows[1][4]);
        $this->assertSame('已领取', $rows[1][5]);
        $this->assertSame('已结算', $rows[1][6]);
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

        $this->getJson('/api/v1/user/distributor/delivery')
            ->assertOk()
            ->assertJsonPath('data.connected_at', null);

        $delivery->update(['connected_at' => time(), 'connected_node_id' => 1, 'connected_node_name' => '测试节点']);
        $this->getJson('/api/v1/user/distributor/delivery')->assertNotFound();
    }

    public function test_admin_settlement_is_idempotent_and_reveals_subscription_only_in_detail(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $order = $this->createDistributorOrder($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY, '详情客户');
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
            ->assertJsonPath('data.customer_name', '详情客户')
            ->assertJsonPath('data.subscribe_url', Helper::getSubscribeUrl($subscriber->token));
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
        $this->assertStringContainsString('<autoFilter ref="A1:G', $sheetXml);
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
