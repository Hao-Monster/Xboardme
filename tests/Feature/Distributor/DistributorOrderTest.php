<?php

namespace Tests\Feature\Distributor;

use App\Http\Resources\OrderResource;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\UserController as AdminUserController;
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
        $this->assertNull($delivery->config_issued_at);
        $this->assertNull($delivery->claim_token);

        $this->get($claimPath)->assertStatus(410);

        $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
        $subscribePath = parse_url($subscribeUrl, PHP_URL_PATH);
        $subscribeQuery = parse_url($subscribeUrl, PHP_URL_QUERY);
        $this->get($subscribePath . ($subscribeQuery ? '?' . $subscribeQuery : ''))->assertOk();

        $this->assertNotNull($delivery->refresh()->config_issued_at);
    }

    public function test_distributor_cannot_access_normal_subscription_api_and_order_resource_never_exposes_real_token(): void
    {
        $distributor = $this->makeUser('dealer@example.com', true);
        $plan = $this->makePlan();
        Sanctum::actingAs($distributor);

        $this->getJson('/api/v1/user/getSubscribe')->assertForbidden();
        $this->getJson('/api/v1/user/plan/fetch')->assertOk();

        $order = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_MONTHLY);
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
        $order = app(DistributorOrderService::class)->create(
            $distributor,
            $this->makePlan(),
            Plan::PERIOD_MONTHLY
        );
        $subscriber = $order->distributorOrder()->with('subscriber')->firstOrFail()->subscriber;
        $subscriber->update([
            'u' => 2 * 1073741824,
            'd' => 3 * 1073741824,
        ]);
        $otherOrder = app(DistributorOrderService::class)->create(
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

    public function test_admin_xlsx_export_contains_only_distributor_orders_with_exact_columns_and_numeric_amounts(): void
    {
        $firstDistributor = $this->makeUser('first-dealer@example.com', true);
        $secondDistributor = $this->makeUser('second-dealer@example.com', true);
        $plan = $this->makePlan();
        $older = app(DistributorOrderService::class)->create($firstDistributor, $plan, Plan::PERIOD_MONTHLY);
        $newer = app(DistributorOrderService::class)->create($secondDistributor, $plan, Plan::PERIOD_QUARTERLY);
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
        $this->assertSame(['订单号', '分销商', '套餐', '原价', '交付状态', '结算状态'], $rows[0]);
        $this->assertSame($newer->trade_no, $rows[1][0]);
        $this->assertSame('second-dealer@example.com', $rows[1][1]);
        $this->assertSame('Distributor Test Plan', $rows[1][2]);
        $this->assertTrue(is_int($rows[1][3]) || is_float($rows[1][3]));
        $this->assertEquals(30.0, $rows[1][3]);
        $this->assertSame('待领取', $rows[1][4]);
        $this->assertSame('未结算', $rows[1][5]);
        $this->assertSame($older->trade_no, $rows[2][0]);
        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('NORMAL-ORDER-MUST-NOT-EXPORT', json_encode($rows));
    }

    public function test_admin_xlsx_export_applies_distributor_and_settlement_filters_and_rejects_empty_results(): void
    {
        $firstDistributor = $this->makeUser('filter-dealer@example.com', true);
        $secondDistributor = $this->makeUser('other-filter-dealer@example.com', true);
        $plan = $this->makePlan();
        $unsettled = app(DistributorOrderService::class)->create($firstDistributor, $plan, Plan::PERIOD_MONTHLY);
        $settled = app(DistributorOrderService::class)->create($firstDistributor, $plan, Plan::PERIOD_MONTHLY);
        $settled->distributorOrder()->update(['settlement_status' => DistributorOrder::SETTLEMENT_SETTLED]);
        app(DistributorOrderService::class)->create($secondDistributor, $plan, Plan::PERIOD_MONTHLY);

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
        $unsettled = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_MONTHLY);
        $settled = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_QUARTERLY);
        $settled->distributorOrder()->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
        ]);
        $otherOrder = app(DistributorOrderService::class)->create($otherDistributor, $plan, Plan::PERIOD_QUARTERLY);

        Sanctum::actingAs($distributor);
        $this->getJson('/api/v1/user/order/fetch?settlement_status=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trade_no', $settled->trade_no);

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export?settlement_status=1')->assertOk());
        $this->assertSame(['订单号', '订阅计划', '周期', '订单金额', '交付状态', '结算状态'], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame($settled->trade_no, $rows[1][0]);
        $this->assertSame('Distributor Test Plan', $rows[1][1]);
        $this->assertSame('季付', $rows[1][2]);
        $this->assertTrue(is_int($rows[1][3]) || is_float($rows[1][3]));
        $this->assertEquals(30.0, $rows[1][3]);
        $this->assertSame('已领取', $rows[1][4]);
        $this->assertSame('已结算', $rows[1][5]);
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
        $order = app(DistributorOrderService::class)->create($distributor, $plan, Plan::PERIOD_MONTHLY);
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

        $distributorOrder = app(DistributorOrderService::class)->create(
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
        $order = app(DistributorOrderService::class)->create($distributor, $this->makePlan(), Plan::PERIOD_MONTHLY);
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
        ])->assertOk();

        $target->refresh();
        $this->assertTrue($target->is_admin);
        $this->assertTrue($target->is_staff);
        $this->assertTrue($target->is_distributor);

        $order = app(DistributorOrderService::class)->create($target, $this->makePlan(), Plan::PERIOD_MONTHLY);
        $this->assertSame($target->id, $order->user_id);
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
        $this->assertStringContainsString('<autoFilter ref="A1:F', $sheetXml);
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
