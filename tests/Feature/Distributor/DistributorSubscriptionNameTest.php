<?php

namespace Tests\Feature\Distributor;

use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\UserController as AdminUserController;
use App\Models\DistributorOrder;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionNameService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\Common\Helper\CellHelper;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class DistributorSubscriptionNameTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_new_subscription_name_uses_merchant_and_shanghai_creation_day_without_order_prefix(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 16, 0, 0, 'UTC'));
        $dealer = $this->makeUser('merchant@example.com', 'GZXBL小北Mustafa');
        $first = $this->createOrder($dealer);
        $second = $this->createOrder($dealer);
        $firstDelivery = $first->distributorOrder()->firstOrFail();
        $secondDelivery = $second->distributorOrder()->firstOrFail();

        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/D', (string) $firstDelivery->subscription_code);
        $this->assertSame('GZXBL小北Mustafa-261006-' . $firstDelivery->subscription_code, $firstDelivery->subscription_name);
        $this->assertNotSame($firstDelivery->subscription_code, $secondDelivery->subscription_code);
        $this->assertStringNotContainsString('订单号', $firstDelivery->subscription_name);
        $this->assertMatchesRegularExpression('/^[0-9]{25}$/D', $first->trade_no);
        $this->assertNotSame($first->trade_no, $second->trade_no);
    }

    public function test_subscription_headers_and_url_remark_show_the_same_name_without_changing_access_identity(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');
        $order = $this->createOrder($this->makeUser('client-merchant@example.com', '小北Mustafa'));
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $name = $delivery->subscription_name;
        $originalTradeNo = $order->trade_no;
        $originalToken = $delivery->subscriber->token;
        $originalUuid = $delivery->subscriber->uuid;
        $baseUrl = Helper::getSubscribeUrl($originalToken);
        $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);

        $this->assertSame($name, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
        $this->assertSame($baseUrl, preg_replace('/#.*$/', '', $url));
        $path = parse_url($baseUrl, PHP_URL_PATH);
        $query = parse_url($baseUrl, PHP_URL_QUERY);
        $uri = $path . ($query ? '?' . $query : '');
        $this->makeServer();

        $this->get($uri)->assertNotFound()->assertHeaderMissing('profile-title');

        foreach (['Karing/1.2.22.2502 Android', 'FlClash/0.8.92', 'ClashVerge/2.4.2'] as $userAgent) {
            $response = $this->withHeaders([
                'User-Agent' => $userAgent,
                'X-HWID' => 'subscription-name-device-001',
            ])->get($uri)->assertOk()->assertHeader('x-order-no', $originalTradeNo);

            $title = (string) $response->headers->get('profile-title');
            $this->assertStringStartsWith('base64:', $title);
            $this->assertSame($name, base64_decode(substr($title, 7), true));
            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertSame(1, preg_match("/filename\\*=UTF-8''([^;]+)/", $disposition, $matches));
            $filename = rawurldecode($matches[1]);
            $this->assertSame($name, preg_replace('/\.(conf|yaml|yml|json|txt)$/i', '', $filename));
            $this->assertStringNotContainsString('订单号', $filename);
        }

        $this->assertSame($originalTradeNo, $order->fresh()->trade_no);
        $this->assertSame($originalToken, $delivery->subscriber->fresh()->token);
        $this->assertSame($originalUuid, $delivery->subscriber->fresh()->uuid);
        $this->assertSame(1, $delivery->hwidDevices()->count());
    }

    public function test_public_subscription_labels_cannot_authenticate_or_mutate_subscription_state(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');
        $order = $this->createOrder($this->makeUser('public-label-auth@example.com', '公开名称商户'));
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $this->makeServer();
        $before = $this->purchaseState();

        foreach (['Karing/1.2.22.2502 Android', 'FlClash/0.8.92', 'ClashVerge/2.4.2'] as $userAgent) {
            foreach ([$delivery->subscription_code, $delivery->subscription_name] as $publicLabel) {
                foreach (['client.subscribe', 'client.subscribe.legacy'] as $routeName) {
                    $this->withHeaders([
                        'User-Agent' => $userAgent,
                        'X-HWID' => 'public-label-must-not-bind',
                    ])->get(route($routeName, ['token' => $publicLabel], false))
                        ->assertForbidden()
                        ->assertHeaderMissing('profile-title')
                        ->assertHeaderMissing('x-order-no');

                    $this->assertSame($before, $this->purchaseState());
                    $this->assertSame(0, $delivery->hwidDevices()->count());
                }
            }
        }

        $this->withHeaders([
            'User-Agent' => 'FlClash/0.8.92',
            'X-HWID' => 'real-token-authorized-device',
        ])->get(route('client.subscribe', ['token' => $delivery->subscriber->token], false))
            ->assertOk()->assertHeader('x-order-no', $order->trade_no);
        $this->assertSame(1, $delivery->hwidDevices()->count());
        $this->assertSame('real-token-authorized-device', $delivery->hwidDevices()->firstOrFail()->hwid);
    }

    public function test_new_purchase_rejects_invalid_stored_merchant_names_without_partial_writes(): void
    {
        $dealer = $this->makeUser('invalid-stored-purchase@example.com', '历史商户');
        $legacyOrder = $this->createOrder($dealer);
        $legacyDelivery = $this->asLegacySubscription($legacyOrder);
        $legacyBefore = $legacyDelivery->getRawOriginal();
        $legacyUrl = app(DistributorOrderService::class)->subscriptionUrl($legacyDelivery);
        Sanctum::actingAs($dealer);

        foreach ([null, '   ', str_repeat('甲', 17), str_repeat('😀', 9), "商户\n名称", 'a@b.co'] as $invalidName) {
            $dealer->update(['distributor_name' => $invalidName]);
            $before = $this->purchaseState();

            $this->postJson('/api/v1/user/order/save', [
                'plan_id' => $legacyOrder->plan_id,
                'period' => 'month_price',
                'customer_name' => '不应创建的客户',
            ])->assertUnprocessable()->assertJsonValidationErrors('distributor_name');

            $this->assertSame($before, $this->purchaseState());
            $this->getJson('/api/v1/user/order/detail?' . http_build_query(['trade_no' => $legacyOrder->trade_no]))
                ->assertOk()->assertJsonPath('data.subscription_name', null);
            $this->assertSame($legacyBefore, $legacyDelivery->fresh()->getRawOriginal());
            $this->assertSame($legacyUrl, app(DistributorOrderService::class)->subscriptionUrl($legacyDelivery->fresh()));
        }

        $this->postJson('/api/v1/user/order/renew', [
            'trade_no' => $legacyOrder->trade_no,
            'period' => 'quarter_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614175201',
        ])->assertOk();
        $this->assertSame(1, DistributorOrder::count());
        $this->assertNull($legacyDelivery->fresh()->subscription_name);
        $this->assertNull($legacyDelivery->fresh()->subscription_code);
        $this->assertSame($legacyUrl, app(DistributorOrderService::class)->subscriptionUrl($legacyDelivery->fresh()));
    }

    public function test_boundary_and_reserved_character_names_survive_purchase_and_all_delivery_encodings(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 9, 0, 0, 'Asia/Shanghai'));
        $dealer = $this->makeUser('encoded-name-purchase@example.com', '初始商户');
        $seedOrder = $this->createOrder($dealer);
        $seedDelivery = $seedOrder->distributorOrder()->firstOrFail();
        $seedBefore = $seedDelivery->getRawOriginal();
        $this->makeServer();

        foreach ([str_repeat('甲', 16), str_repeat('😀', 8), '=甲&乙#<北>'] as $merchantName) {
            $this->flushHeaders();
            $dealer->update(['distributor_name' => $merchantName]);
            Sanctum::actingAs($dealer);
            $tradeNo = $this->postJson('/api/v1/user/order/save', [
                'plan_id' => $seedOrder->plan_id,
                'period' => 'month_price',
                'customer_name' => '编码验收客户',
            ])->assertOk()->json('data');
            $order = Order::where('trade_no', $tradeNo)->firstOrFail();
            $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
            $expectedName = $merchantName . '-261006-' . $delivery->subscription_code;

            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/D', $delivery->subscription_code);
            $this->assertSame($expectedName, $delivery->subscription_name);
            $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);
            $this->assertSame($expectedName, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
            $this->assertSame(Helper::getSubscribeUrl($delivery->subscriber->token), preg_replace('/#.*$/', '', $url));

            foreach (['Karing/1.2.22.2502 Android', 'FlClash/0.8.92', 'ClashVerge/2.4.2'] as $userAgent) {
                $response = $this->withHeaders([
                    'User-Agent' => $userAgent,
                    'X-HWID' => 'encoded-name-device-001',
                ])->get(route('client.subscribe', ['token' => $delivery->subscriber->token], false))
                    ->assertOk()->assertHeader('x-order-no', $tradeNo);
                $this->assertSame($expectedName, base64_decode(substr((string) $response->headers->get('profile-title'), 7), true));
                $disposition = (string) $response->headers->get('content-disposition');
                $this->assertSame(1, preg_match("/filename\\*=UTF-8''([^;]+)/", $disposition, $matches));
                $filename = rawurldecode($matches[1]);
                $this->assertSame($expectedName, preg_replace('/\.(conf|yaml|yml|json|txt)$/i', '', $filename));
                $this->assertStringContainsString('filename="' . $delivery->subscription_code, $disposition);
            }

            $this->flushHeaders();
            Sanctum::actingAs($dealer);
            $rows = $this->readXlsx($this->get('/api/v1/user/order/export?' . http_build_query([
                'search' => $delivery->subscription_code,
            ]))->assertOk(), [
                '订阅名称' => $expectedName,
                '短订阅号' => $delivery->subscription_code,
            ]);
            $this->assertCount(2, $rows);
            $this->assertExportIdentity($rows, $delivery);
            $this->assertSame($seedBefore, $seedDelivery->fresh()->getRawOriginal());
        }
    }

    public function test_renewal_and_merchant_rename_keep_the_first_subscription_name_and_code(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 9, 0, 0, 'Asia/Shanghai'));
        $dealer = $this->makeUser('renewal-name@example.com', '旧商户');
        $rootOrder = $this->createOrder($dealer);
        $delivery = $rootOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $name = $delivery->subscription_name;
        $code = $delivery->subscription_code;
        $token = $delivery->subscriber->token;
        Sanctum::actingAs($this->makeUser('rename-renewal-admin@example.com', null, true));
        $this->postJson($this->adminRoute(AdminUserController::class, 'update'), [
            'id' => $dealer->id, 'distributor_name' => '新商户',
        ])->assertOk()->assertJsonPath('data', true);
        $dealer->refresh();
        Carbon::setTestNow(Carbon::create(2026, 10, 8, 9, 0, 0, 'Asia/Shanghai'));
        Sanctum::actingAs($dealer);

        $renewalTradeNo = $this->postJson('/api/v1/user/order/renew', [
            'trade_no' => $rootOrder->trade_no,
            'period' => 'quarter_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614175100',
        ])->assertOk()->json('data.trade_no');

        $this->assertSame('旧商户-261006-' . $code, $name);
        $this->assertSame($name, $delivery->fresh()->subscription_name);
        $this->assertSame($code, $delivery->fresh()->subscription_code);
        $this->assertSame($token, $delivery->subscriber->fresh()->token);
        $this->assertNotSame($rootOrder->trade_no, $renewalTradeNo);
        $this->assertSame(1, DistributorOrder::count());
        $this->getJson('/api/v1/user/order/fetch?' . http_build_query(['search' => $code]))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subscription_name', $name)
            ->assertJsonPath('data.0.subscription_code', $code)
            ->assertJsonPath('data.1.subscription_name', $name)
            ->assertJsonPath('data.1.subscription_code', $code);

        $newOrder = $this->createOrder($dealer);
        $this->assertStringStartsWith('新商户-261008-', $newOrder->distributorOrder()->firstOrFail()->subscription_name);
    }

    public function test_short_code_and_full_name_search_preserve_distributor_ownership_and_admin_access(): void
    {
        $owner = $this->makeUser('owner-name@example.com', '同名商户');
        $other = $this->makeUser('other-name@example.com', '同名商户');
        $ownOrder = $this->createOrder($owner);
        $otherOrder = $this->createOrder($other);
        $ownDelivery = $ownOrder->distributorOrder()->firstOrFail();
        $otherDelivery = $otherOrder->distributorOrder()->firstOrFail();
        Sanctum::actingAs($owner);

        foreach ([$ownDelivery->subscription_code, strtolower((string) $ownDelivery->subscription_code), $ownDelivery->subscription_name] as $search) {
            $this->getJson('/api/v1/user/order/fetch?' . http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.trade_no', $ownOrder->trade_no)
                ->assertJsonPath('data.0.subscription_code', $ownDelivery->subscription_code)
                ->assertJsonPath('data.0.subscription_name', $ownDelivery->subscription_name);
        }
        foreach ([$otherDelivery->subscription_code, $otherDelivery->subscription_name] as $search) {
            $this->getJson('/api/v1/user/order/fetch?' . http_build_query(['search' => $search]))
                ->assertOk()->assertJsonCount(0, 'data');
        }

        Sanctum::actingAs($this->makeUser('name-search-admin@example.com', null, true));
        foreach ([$otherDelivery->subscription_code, strtolower((string) $otherDelivery->subscription_code), $otherDelivery->subscription_name] as $search) {
            $this->postJson($this->adminRoute(AdminOrderController::class, 'fetch'), [
                'current' => 1,
                'pageSize' => 20,
                'distributor_only' => true,
                'search' => $search,
            ])->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.trade_no', $otherOrder->trade_no)
                ->assertJsonPath('data.0.subscription_name', $otherDelivery->subscription_name);
        }
    }

    public function test_legacy_reads_keep_original_headers_url_remark_and_null_metadata(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('redis');
        $dealer = $this->makeUser('legacy-name@example.com', '历史商户');
        $order = $this->createOrder($dealer);
        $delivery = $this->asLegacySubscription($order);
        $orderBefore = $order->fresh()->getRawOriginal();
        $subscriberBefore = $delivery->subscriber->fresh()->getRawOriginal();
        $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);
        $this->assertSame($order->trade_no, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
        $baseUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
        $this->assertSame($baseUrl, preg_replace('/#.*$/', '', $url));
        $uri = parse_url($baseUrl, PHP_URL_PATH);
        $query = parse_url($baseUrl, PHP_URL_QUERY);
        $this->makeServer();
        foreach (['Karing/1.2.22.2502 Android', 'FlClash/0.8.92', 'ClashVerge/2.4.2'] as $userAgent) {
            $response = $this->withHeaders([
                'User-Agent' => $userAgent,
                'X-HWID' => 'legacy-subscription-device-001',
            ])->get($uri . ($query ? '?' . $query : ''))->assertOk()
                ->assertHeader('x-order-no', $order->trade_no);
            $this->assertSame('订单号：' . $order->trade_no, base64_decode(substr((string) $response->headers->get('profile-title'), 7), true));
            $disposition = (string) $response->headers->get('content-disposition');
            $this->assertStringContainsString('filename="' . $order->trade_no, $disposition);
            $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('订单号：' . $order->trade_no), $disposition);
            $this->assertNull($delivery->fresh()->subscription_name);
            $this->assertNull($delivery->fresh()->subscription_code);
        }
        $this->flushHeaders();
        $legacyBefore = $delivery->fresh()->getRawOriginal();
        Sanctum::actingAs($dealer);
        $this->getJson('/api/v1/user/order/fetch')->assertOk()
            ->assertJsonPath('data.0.subscription_name', null)
            ->assertJsonPath('data.0.subscription_code', null);
        $this->getJson('/api/v1/user/order/detail?' . http_build_query(['trade_no' => $order->trade_no]))
            ->assertOk()->assertJsonPath('data.subscription_name', null);
        foreach (['delivery', 'subscription-qr'] as $endpoint) {
            $qr = $this->getJson('/api/v1/user/distributor/' . $endpoint . '?' . http_build_query(['trade_no' => $order->trade_no]))
                ->assertOk()->assertJsonPath('data.subscription_name', null)
                ->assertJsonPath('data.subscription_code', null);
            if ($endpoint === 'subscription-qr') {
                $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr->json('data.qr_code'));
            }
        }
        Sanctum::actingAs($this->makeUser('legacy-read-admin@example.com', null, true));
        $this->postJson($this->adminRoute(AdminOrderController::class, 'fetch'), ['distributor_only' => true])
            ->assertOk()->assertJsonPath('data.0.subscription_name', null);
        $this->postJson($this->adminRoute(AdminOrderController::class, 'detail'), ['id' => $order->id])
            ->assertOk()->assertJsonPath('data.subscription_name', null)
            ->assertJsonPath('data.subscribe_url', $url);

        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
        $this->assertSame($legacyBefore, $delivery->fresh()->getRawOriginal());
    }

    public function test_legacy_renewal_keeps_original_name_token_uuid_and_null_metadata(): void
    {
        $dealer = $this->makeUser('legacy-renewal@example.com', '历史商户');
        $order = $this->createOrder($dealer);
        $delivery = $this->asLegacySubscription($order);
        $token = $delivery->subscriber->token;
        $uuid = $delivery->subscriber->uuid;
        $url = app(DistributorOrderService::class)->subscriptionUrl($delivery);
        $dealer->update(['distributor_name' => null]);
        Sanctum::actingAs($dealer);

        $renewalTradeNo = $this->postJson('/api/v1/user/order/renew', [
            'trade_no' => $order->trade_no,
            'period' => 'quarter_price',
            'idempotency_key' => '123e4567-e89b-42d3-a456-426614175101',
        ])->assertOk()->json('data.trade_no');

        $this->assertNotSame($order->trade_no, $renewalTradeNo);
        $this->assertSame(1, DistributorOrder::count());
        $this->assertNull($delivery->fresh()->subscription_code);
        $this->assertNull($delivery->fresh()->subscription_name);
        $this->assertSame($url, app(DistributorOrderService::class)->subscriptionUrl($delivery->fresh()));
        $this->assertSame($order->trade_no, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
        $this->assertSame($token, $delivery->subscriber->fresh()->token);
        $this->assertSame($uuid, $delivery->subscriber->fresh()->uuid);
        $this->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subscription_name', null)
            ->assertJsonPath('data.1.subscription_name', null);
    }

    public function test_name_assignment_cannot_be_applied_to_a_persisted_legacy_subscription(): void
    {
        $order = $this->createOrder($this->makeUser('legacy-guard@example.com', '历史商户'));
        $delivery = $this->asLegacySubscription($order);
        $before = $delivery->getRawOriginal();

        try {
            app(DistributorSubscriptionNameService::class)->assignToNewSubscription($delivery);
            $this->fail('A persisted legacy subscription must never acquire a new display identity.');
        } catch (\LogicException $exception) {
            $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        }
    }

    public function test_database_rejects_duplicate_short_codes(): void
    {
        $dealer = $this->makeUser('duplicate-code@example.com', '唯一编号商户');
        $first = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $second = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('v2_distributor_order')->where('id', $second->id)
            ->update(['subscription_code' => $first->subscription_code]);
    }

    public function test_short_code_collision_retries_against_the_real_database_and_keeps_existing_identity(): void
    {
        $dealer = $this->makeUser('collision-retry@example.com', '冲突重试商户');
        $firstOrder = $this->createOrder($dealer);
        $first = $firstOrder->distributorOrder()->firstOrFail();
        $firstName = $first->subscription_name;
        $availableCode = $first->subscription_code === 'A7K9Q2' ? 'B7K9Q2' : 'A7K9Q2';
        $service = $this->nameServiceWithCodes([$first->subscription_code, $availableCode]);
        $this->app->instance(DistributorSubscriptionNameService::class, $service);

        $secondOrder = app(DistributorOrderService::class)->create($dealer, $firstOrder->plan, Plan::PERIOD_MONTHLY);
        $second = $secondOrder->distributorOrder()->firstOrFail();

        $this->assertSame(2, $service->attempts);
        $this->assertSame($availableCode, $second->fresh()->subscription_code);
        $this->assertSame($firstName, $first->fresh()->subscription_name);
        $this->assertStringEndsWith('-' . $availableCode, $second->fresh()->subscription_name);
    }

    public function test_exhausted_short_code_collisions_roll_back_the_entire_purchase(): void
    {
        $dealer = $this->makeUser('collision-exhausted@example.com', '冲突失败商户');
        $firstOrder = $this->createOrder($dealer);
        $first = $firstOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $orderCount = Order::count();
        $userCount = User::count();
        $deliveryCount = DistributorOrder::count();
        $firstBefore = $first->getRawOriginal();
        $service = $this->nameServiceWithCodes([$first->subscription_code]);
        $this->app->instance(DistributorSubscriptionNameService::class, $service);

        try {
            app(DistributorOrderService::class)->create(
                $dealer,
                $firstOrder->plan,
                Plan::PERIOD_MONTHLY,
                '不应创建的客户'
            );
            $this->fail('Exhausted unique-code collisions must fail the purchase.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            $this->assertSame(10, $service->attempts);
        }

        $this->assertSame($orderCount, Order::count());
        $this->assertSame($userCount, User::count());
        $this->assertSame($deliveryCount, DistributorOrder::count());
        $this->assertSame($firstBefore, $first->fresh()->getRawOriginal());
    }

    public function test_distributor_export_preserves_legacy_metadata_and_filters_new_short_codes_with_tenant_isolation(): void
    {
        $owner = $this->makeUser('export-name-owner@example.com', '导出商户');
        $other = $this->makeUser('export-name-other@example.com', '其他商户');
        $legacyOrder = $this->createOrder($owner);
        $legacyDelivery = $this->asLegacySubscription($legacyOrder);
        $legacyBefore = $legacyDelivery->getRawOriginal();
        $ownOrder = $this->createOrder($owner);
        $ownDelivery = $ownOrder->distributorOrder()->firstOrFail();
        $otherOrder = $this->createOrder($other);
        $otherDelivery = $otherOrder->distributorOrder()->firstOrFail();
        $foreignCode = $otherDelivery->subscription_code;
        $otherLegacy = $this->asLegacySubscription($this->createOrder($other));
        $otherLegacyBefore = $otherLegacy->getRawOriginal();
        $other->update(['distributor_name' => null]);
        Sanctum::actingAs($owner);

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export')->assertOk());

        $this->assertCount(3, $rows);
        $this->assertSame($ownOrder->trade_no, $rows[1][0]);
        $this->assertExportIdentity($rows, $ownDelivery);
        $this->assertSame($legacyOrder->trade_no, $rows[2][0]);
        $this->assertLegacyExportIdentity($rows, 2);
        $filteredRows = $this->readXlsx($this->get('/api/v1/user/order/export?' . http_build_query([
            'search' => strtolower($ownDelivery->subscription_code),
        ]))->assertOk());
        $this->assertCount(2, $filteredRows);
        $this->assertSame($ownOrder->trade_no, $filteredRows[1][0]);
        $this->assertExportIdentity($filteredRows, $ownDelivery);
        $this->getJson('/api/v1/user/order/export?' . http_build_query(['search' => $foreignCode]))
            ->assertUnprocessable();
        $this->assertSame($legacyBefore, $legacyDelivery->fresh()->getRawOriginal());
        $this->assertSame($otherLegacyBefore, $otherLegacy->fresh()->getRawOriginal());
        $this->assertSame($foreignCode, $otherDelivery->fresh()->subscription_code);
    }

    public function test_admin_exports_preserve_legacy_metadata_for_selected_and_all_merchants(): void
    {
        $selected = $this->makeUser('export-admin-selected@example.com', '选择的商户');
        $other = $this->makeUser('export-admin-other@example.com', '其他商户');
        $selectedOrder = $this->createOrder($selected);
        $selectedDelivery = $this->asLegacySubscription($selectedOrder);
        $selectedBefore = $selectedDelivery->getRawOriginal();
        $otherOrder = $this->createOrder($other);
        $otherDelivery = $this->asLegacySubscription($otherOrder);
        $otherBefore = $otherDelivery->getRawOriginal();
        $other->update(['distributor_name' => null]);
        Sanctum::actingAs($this->makeUser('export-name-admin@example.com', null, true));
        $uri = $this->adminRoute(AdminOrderController::class, 'export');
        $filter = ['distributor_user_id' => $selected->id];

        $rows = $this->readXlsx($this->get($uri . '?' . http_build_query($filter))->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame($selectedOrder->trade_no, $rows[1][0]);
        $this->assertLegacyExportIdentity($rows);

        $allRows = $this->readXlsx($this->get($uri)->assertOk());
        $this->assertCount(3, $allRows);
        $this->assertSame($otherOrder->trade_no, $allRows[1][0]);
        $this->assertLegacyExportIdentity($allRows, 1);
        $this->assertLegacyExportIdentity($allRows, 2);
        $this->assertSame($selectedBefore, $selectedDelivery->fresh()->getRawOriginal());
        $this->assertSame($otherBefore, $otherDelivery->fresh()->getRawOriginal());
    }

    public function test_admin_can_save_sixteen_character_merchant_names_and_rejects_long_or_control_character_names(): void
    {
        $admin = $this->makeUser('name-boundary-admin@example.com', null, true);
        $dealer = $this->makeUser('name-boundary@example.com', '初始商户');
        Sanctum::actingAs($admin);
        $uri = $this->adminRoute(AdminUserController::class, 'update');
        foreach ([str_repeat('甲', 16), 'ABCDEFGHIJKLMNOP', str_repeat('😀', 8), '  ABC 中文商户  '] as $name) {
            $this->postJson($uri, ['id' => $dealer->id, 'distributor_name' => $name])->assertOk();
            $this->assertSame(trim($name), $dealer->fresh()->distributor_name);
        }

        $savedName = $dealer->fresh()->distributor_name;
        foreach ([str_repeat('甲', 17), 'ABCDEFGHIJKLMNOPQ', str_repeat('😀', 9), "商户\n名称", "商户\t名称", '   ', 'a@b.co'] as $name) {
            $this->postJson($uri, ['id' => $dealer->id, 'distributor_name' => $name])
                ->assertUnprocessable();
            $this->assertSame($savedName, $dealer->fresh()->distributor_name);
        }
    }

    public function test_generated_distributor_accounts_enforce_the_same_name_limit(): void
    {
        Sanctum::actingAs($this->makeUser('generate-name-admin@example.com', null, true));
        $uri = $this->adminRoute(AdminUserController::class, 'generate');
        $this->postJson($uri, [
            'email_prefix' => 'generated-valid-name',
            'email_suffix' => 'example.com',
            'is_distributor' => true,
            'distributor_name' => str_repeat('甲', 16),
        ])->assertOk();
        $this->assertSame(str_repeat('甲', 16), User::byEmail('generated-valid-name@example.com')->firstOrFail()->distributor_name);
        $this->postJson($uri, [
            'email_prefix' => 'generated-invalid-name',
            'email_suffix' => 'example.com',
            'is_distributor' => true,
            'distributor_name' => str_repeat('甲', 17),
        ])->assertUnprocessable();
        $this->assertNull(User::byEmail('generated-invalid-name@example.com')->first());
    }

    public function test_minimal_admin_rename_preserves_account_sessions_visibility_and_existing_orders(): void
    {
        $admin = $this->makeUser('qa-rename-admin@example.com', null, true);
        $dealer = $this->makeUser('qa-rename-dealer@example.com', '原商户');
        $dealer->update(['balance' => 12345, 'commission_balance' => 6789, 'discount' => 90]);
        $order = $this->createOrder($dealer);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $session = $dealer->createToken('qa-rename-session')->accessToken;
        $visibility = ['user_id' => $dealer->id, 'plan_id' => $order->plan_id, 'audience' => 'distributor'];
        DB::table('v2_plan_visibility_user')->insert($visibility);
        $beforeAccount = $dealer->fresh()->getRawOriginal();
        $beforeOrder = $order->fresh()->getRawOriginal();
        $beforeDelivery = $delivery->getRawOriginal();
        $beforeSubscriber = $delivery->subscriber->getRawOriginal();
        $beforeSession = $session->fresh()->getRawOriginal();
        $beforeUrl = app(DistributorOrderService::class)->subscriptionUrl($delivery);

        Sanctum::actingAs($admin);
        $payload = ['id' => $dealer->id, 'distributor_name' => '新商户'];
        $uri = $this->adminRoute(AdminUserController::class, 'update');
        $this->postJson($uri, $payload)->assertOk()->assertJsonPath('data', true);
        $this->getJson($this->adminRoute(AdminUserController::class, 'getUserInfoById') . '?id=' . $dealer->id)
            ->assertOk()->assertJsonPath('data.distributor_name', '新商户')->assertJsonPath('data.is_distributor', true);
        $afterAccount = $dealer->fresh()->getRawOriginal();
        $this->assertTrue(\Illuminate\Support\Str::isUuid($afterAccount['distributor_revision']));
        $this->assertNotSame($beforeAccount['distributor_revision'], $afterAccount['distributor_revision']);
        foreach (['distributor_name', 'distributor_revision', 'updated_at'] as $key) {
            unset($beforeAccount[$key], $afterAccount[$key]);
        }
        $this->assertSame($beforeAccount, $afterAccount);
        $this->assertSame($beforeOrder, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeDelivery, $delivery->fresh()->getRawOriginal());
        $this->assertSame($beforeSubscriber, $delivery->subscriber->fresh()->getRawOriginal());
        $this->assertSame($beforeSession, $session->fresh()->getRawOriginal());
        $this->assertDatabaseHas('v2_plan_visibility_user', $visibility);
        $this->assertSame($beforeUrl, app(DistributorOrderService::class)->subscriptionUrl($delivery->fresh()));
        $audit = AdminAuditLog::where('admin_id', $admin->id)->where('action', 'user.update')->firstOrFail();
        $this->assertSame($payload, json_decode($audit->request_data, true, 512, JSON_THROW_ON_ERROR));
        $this->postJson($this->adminRoute(AdminOrderController::class, 'detail'), ['id' => $order->id])
            ->assertOk()->assertJsonPath('data.distributor_name', '新商户')
            ->assertJsonPath('data.subscription_name', $delivery->subscription_name);
    }

    public function test_guest_customer_distributor_and_staff_cannot_use_admin_rename(): void
    {
        $dealer = $this->makeUser('qa-rename-target@example.com', '受保护商户');
        $uri = $this->adminRoute(AdminUserController::class, 'update');
        $payload = ['id' => $dealer->id, 'distributor_name' => '未授权改名'];
        $this->postJson($uri, $payload)->assertForbidden();
        $customer = $this->makeUser('qa-rename-customer@example.com');
        $staff = $this->makeUser('qa-rename-staff@example.com');
        $staff->update(['is_staff' => true]);
        foreach ([$customer, $dealer, $staff] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($uri, $payload)->assertForbidden();
            $this->assertSame('受保护商户', $dealer->fresh()->distributor_name);
        }
    }

    private function adminRoute(string $controller, string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->getActionName() === $controller . '@' . $method
        );
        $this->assertNotNull($route, 'Admin route not found: ' . $method);

        return '/' . $route->uri();
    }

    private function assertExportIdentity(array $rows, DistributorOrder $delivery): void
    {
        $nameIndex = array_search('订阅名称', $rows[0], true);
        $codeIndex = array_search('短订阅号', $rows[0], true);
        $this->assertNotFalse($nameIndex);
        $this->assertNotFalse($codeIndex);
        $this->assertNotEmpty($rows[1][$nameIndex]);
        $this->assertNotEmpty($rows[1][$codeIndex]);
        $this->assertSame($delivery->subscription_name, $rows[1][$nameIndex]);
        $this->assertSame($delivery->subscription_code, $rows[1][$codeIndex]);
    }

    private function assertLegacyExportIdentity(array $rows, int $rowIndex = 1): void
    {
        $nameIndex = array_search('订阅名称', $rows[0], true);
        $codeIndex = array_search('短订阅号', $rows[0], true);
        $this->assertNotFalse($nameIndex);
        $this->assertNotFalse($codeIndex);
        $this->assertSame('', $rows[$rowIndex][$nameIndex]);
        $this->assertSame('', $rows[$rowIndex][$codeIndex]);
    }

    private function asLegacySubscription(Order $order): DistributorOrder
    {
        $delivery = $order->distributorOrder()->firstOrFail();
        DB::table('v2_distributor_order')->where('id', $delivery->id)->update([
            'subscription_code' => null,
            'subscription_name' => null,
        ]);

        return $delivery->fresh(['subscriber']);
    }

    private function purchaseState(): array
    {
        $snapshot = [];
        foreach (['v2_order', 'v2_user', 'v2_distributor_order', 'v2_distributor_hwid_device', 'v2_traffic_reset_logs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function readXlsx($response, array $expectedIdentity = []): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );
        $path = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader();
        $rows = [];
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(static fn ($cell) => $cell->getValue(), $row->getCells());
                }
                break;
            }
            if ($expectedIdentity !== []) {
                $this->assertXlsxIdentityIsLiteral($path, $rows[0], $expectedIdentity);
            }
        } finally {
            $reader->close();
            @unlink($path);
        }

        return $rows;
    }

    private function assertXlsxIdentityIsLiteral(string $path, array $headers, array $expectedIdentity): void
    {
        // OpenSpout's reader infers FormulaCell from any leading '=', even for stored strings.
        // Inspect the actual worksheet so a literal value cannot conceal a written formula.
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open($path));
        try {
            $worksheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
            $this->assertIsString($worksheetXml);
            $worksheet = new \DOMDocument();
            $this->assertTrue($worksheet->loadXML($worksheetXml, LIBXML_NONET));
            $xpath = new \DOMXPath($worksheet);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $this->assertSame(0, $xpath->query('//s:f')->length);

            foreach ($expectedIdentity as $header => $expectedValue) {
                $columnIndex = array_search($header, $headers, true);
                $this->assertNotFalse($columnIndex);
                $address = CellHelper::getColumnLettersFromColumnIndex($columnIndex) . '2';
                $cell = $xpath->query('//s:c[@r="' . $address . '"]')->item(0);
                $this->assertInstanceOf(\DOMElement::class, $cell);
                $this->assertContains($cell->getAttribute('t'), ['inlineStr', 's']);
                $this->assertSame(0, $xpath->query('.//s:f', $cell)->length);

                if ($cell->getAttribute('t') === 's') {
                    $sharedStringsXml = $archive->getFromName('xl/sharedStrings.xml');
                    $this->assertIsString($sharedStringsXml);
                    $sharedStrings = new \DOMDocument();
                    $this->assertTrue($sharedStrings->loadXML($sharedStringsXml, LIBXML_NONET));
                    $sharedXPath = new \DOMXPath($sharedStrings);
                    $sharedXPath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $stringIndex = $xpath->evaluate('string(s:v)', $cell);
                    $this->assertMatchesRegularExpression('/^[0-9]+$/D', $stringIndex);
                    $textNodes = $sharedXPath->query('/s:sst/s:si[' . ((int) $stringIndex + 1) . ']//s:t');
                } else {
                    $textNodes = $xpath->query('s:is//s:t', $cell);
                }
                $actualValue = '';
                foreach ($textNodes as $textNode) {
                    $actualValue .= $textNode->textContent;
                }
                $this->assertSame($expectedValue, $actualValue);
            }
        } finally {
            $archive->close();
        }
    }

    private function nameServiceWithCodes(array $codes): DistributorSubscriptionNameService
    {
        return new class($codes) extends DistributorSubscriptionNameService {
            public int $attempts = 0;

            public function __construct(private array $codes)
            {
            }

            protected function generateCode(): string
            {
                $index = min($this->attempts++, count($this->codes) - 1);

                return $this->codes[$index];
            }
        };
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'Subscription name test server',
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

    private function makeUser(string $email, ?string $merchantName = null, bool $admin = false): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $merchantName !== null,
            'distributor_name' => $merchantName,
            'is_admin' => $admin,
            'is_staff' => false,
            'banned' => false,
        ]);
    }

    private function createOrder(User $dealer): Order
    {
        $plan = Plan::create([
            'group_id' => 1,
            'transfer_enable' => 30,
            'name' => 'Subscription name test plan',
            'speed_limit' => 100,
            'device_limit' => 1,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 30, Plan::PERIOD_QUARTERLY => 30],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);

        return app(DistributorOrderService::class)->create($dealer, $plan, Plan::PERIOD_MONTHLY, '测试客户');
    }
}
