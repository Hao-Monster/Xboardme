<?php

namespace Tests\Feature\Distributor;

use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\V2\Admin\UserController as AdminUserController;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionNameService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Reader\XLSX\Reader;
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

    public function test_renewal_and_merchant_rename_keep_the_first_subscription_name_and_code(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 9, 0, 0, 'Asia/Shanghai'));
        $dealer = $this->makeUser('renewal-name@example.com', '旧商户');
        $rootOrder = $this->createOrder($dealer);
        $delivery = $rootOrder->distributorOrder()->with('subscriber')->firstOrFail();
        $name = $delivery->subscription_name;
        $code = $delivery->subscription_code;
        $token = $delivery->subscriber->token;
        $dealer->update(['distributor_name' => '新商户']);
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

    public function test_legacy_delivery_gets_a_stable_name_using_original_order_date_without_rewriting_business_data(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 6, 9, 0, 0, 'Asia/Shanghai'));
        $dealer = $this->makeUser('legacy-name@example.com', '历史商户');
        $order = $this->createOrder($dealer);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $orderBefore = $order->fresh()->getRawOriginal();
        $subscriberBefore = $delivery->subscriber->fresh()->getRawOriginal();
        DB::table('v2_distributor_order')->where('id', $delivery->id)->update([
            'subscription_code' => null,
            'subscription_name' => null,
        ]);
        $legacyBefore = $delivery->fresh()->getRawOriginal();
        Carbon::setTestNow(Carbon::create(2027, 1, 1, 9, 0, 0, 'Asia/Shanghai'));

        $legacy = $delivery->fresh();
        app(DistributorSubscriptionNameService::class)->ensure($legacy);
        $legacy->refresh();
        $this->assertMatchesRegularExpression('/^历史商户-261006-[A-HJ-NP-Z2-9]{6}$/uD', $legacy->subscription_name);
        $name = $legacy->subscription_name;
        $code = $legacy->subscription_code;
        $dealer->update(['distributor_name' => '改名后商户']);
        app(DistributorSubscriptionNameService::class)->ensure($legacy);

        $this->assertSame($name, $legacy->fresh()->subscription_name);
        $this->assertSame($code, $legacy->fresh()->subscription_code);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
        $after = $legacy->fresh()->getRawOriginal();
        unset($after['subscription_code'], $after['subscription_name']);
        unset($legacyBefore['subscription_code'], $legacyBefore['subscription_name']);
        $this->assertSame($legacyBefore, $after);
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
        $first = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $second = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $firstName = $first->subscription_name;
        DB::table('v2_distributor_order')->where('id', $second->id)->update([
            'subscription_code' => null,
            'subscription_name' => null,
        ]);
        $availableCode = $first->subscription_code === 'A7K9Q2' ? 'B7K9Q2' : 'A7K9Q2';
        $service = $this->nameServiceWithCodes([$first->subscription_code, $availableCode]);

        $service->ensure($second->fresh());

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

    public function test_migration_backfills_legacy_subscriptions_preserves_business_data_and_is_repeatable(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 5, 16, 0, 0, 'UTC'));
        $order = $this->createOrder($this->makeUser('migration-name@example.com', '迁移商户'));
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $orderBefore = $order->fresh()->getRawOriginal();
        $subscriberBefore = $delivery->subscriber->fresh()->getRawOriginal();
        $deliveryBefore = $delivery->getRawOriginal();
        unset($deliveryBefore['subscription_code'], $deliveryBefore['subscription_name']);
        $migration = require database_path('migrations/2026_10_06_000001_add_distributor_subscription_names.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_distributor_order', 'subscription_name'));
        $this->assertFalse(Schema::hasColumn('v2_distributor_order', 'subscription_code'));
        Carbon::setTestNow(Carbon::create(2027, 1, 1, 9, 0, 0, 'Asia/Shanghai'));
        $migration->up();
        $migrated = $delivery->fresh();
        $this->assertMatchesRegularExpression('/^迁移商户-261006-[A-HJ-NP-Z2-9]{6}$/uD', $migrated->subscription_name);
        $migratedBefore = $migrated->getRawOriginal();
        $migration->up();

        $this->assertSame($migratedBefore, $delivery->fresh()->getRawOriginal());
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
        $deliveryAfter = $delivery->fresh()->getRawOriginal();
        unset($deliveryAfter['subscription_code'], $deliveryAfter['subscription_name']);
        $this->assertSame($deliveryBefore, $deliveryAfter);
    }

    public function test_migration_rejects_invalid_legacy_merchant_names_without_publishing_a_substitute(): void
    {
        $migration = require database_path('migrations/2026_10_06_000001_add_distributor_subscription_names.php');
        foreach ([null, '   ', 'a@b.co', str_repeat('甲', 17)] as $index => $invalidName) {
            $dealer = $this->makeUser('invalid-legacy-' . $index . '@example.com', '历史有效商户');
            $order = $this->createOrder($dealer);
            $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
            $orderBefore = $order->fresh()->getRawOriginal();
            $subscriberBefore = $delivery->subscriber->fresh()->getRawOriginal();
            DB::table('v2_distributor_order')->where('id', $delivery->id)->update([
                'subscription_code' => null,
                'subscription_name' => null,
            ]);
            $dealer->update(['distributor_name' => $invalidName]);

            try {
                $migration->up();
                $this->fail('Invalid historical merchant names must block migration.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('distributor_name', $exception->errors());
            }

            $this->assertNull($delivery->fresh()->subscription_name);
            $this->assertNull($delivery->fresh()->subscription_code);
            $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
            $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
            $dealer->update(['distributor_name' => '修正后商户']);
            $migration->up();
            $this->assertStringStartsWith('修正后商户-', $delivery->fresh()->subscription_name);
        }
    }

    public function test_migration_resumes_after_partial_column_or_index_creation_and_restores_uniqueness(): void
    {
        $dealer = $this->makeUser('partial-migration@example.com', '中断迁移商户');
        $first = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $second = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
        $migration = require database_path('migrations/2026_10_06_000001_add_distributor_subscription_names.php');

        foreach ([true, false] as $nameColumnMissing) {
            DB::table('v2_distributor_order')->update(['subscription_code' => null, 'subscription_name' => null]);
            Schema::table('v2_distributor_order', function (Blueprint $table) use ($nameColumnMissing) {
                $table->dropUnique('v2_dist_subscription_code_unique');
                if ($nameColumnMissing) {
                    $table->dropColumn('subscription_name');
                }
            });
            $this->assertTrue(Schema::hasColumn('v2_distributor_order', 'subscription_code'));
            $this->assertFalse(Schema::hasIndex('v2_distributor_order', 'v2_dist_subscription_code_unique'));
            $this->assertSame(!$nameColumnMissing, Schema::hasColumn('v2_distributor_order', 'subscription_name'));

            $migration->up();

            $this->assertTrue(Schema::hasColumn('v2_distributor_order', 'subscription_name'));
            $this->assertTrue(Schema::hasIndex('v2_distributor_order', 'v2_dist_subscription_code_unique', 'unique'));
            $first->refresh();
            $second->refresh();
            $this->assertNotSame($first->subscription_code, $second->subscription_code);
            $this->assertStringStartsWith('中断迁移商户-', $first->subscription_name);
            $this->assertStringStartsWith('中断迁移商户-', $second->subscription_name);
            try {
                DB::transaction(function () use ($first, $second) {
                    DB::table('v2_distributor_order')->where('id', $second->id)
                        ->update(['subscription_code' => $first->subscription_code]);
                });
                $this->fail('Resumed migration must recreate the database uniqueness constraint.');
            } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
                $this->assertNotSame($first->subscription_code, $second->fresh()->subscription_code);
            }
        }
    }

    public function test_direct_distributor_export_backfills_legacy_names_and_supports_short_code_filter_with_tenant_isolation(): void
    {
        $owner = $this->makeUser('export-name-owner@example.com', '导出商户');
        $other = $this->makeUser('export-name-other@example.com', '其他商户');
        $ownOrder = $this->createOrder($owner);
        $otherOrder = $this->createOrder($other);
        $ownDelivery = $ownOrder->distributorOrder()->firstOrFail();
        $otherDelivery = $otherOrder->distributorOrder()->firstOrFail();
        $foreignCode = $otherDelivery->subscription_code;
        DB::table('v2_distributor_order')->update(['subscription_code' => null, 'subscription_name' => null]);
        $other->update(['distributor_name' => null]);
        Sanctum::actingAs($owner);

        $rows = $this->readXlsx($this->get('/api/v1/user/order/export')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame($ownOrder->trade_no, $rows[1][0]);
        $ownDelivery->refresh();
        $this->assertStringStartsWith('导出商户-', $ownDelivery->subscription_name);
        $this->assertExportIdentity($rows, $ownDelivery);
        $this->assertNull($otherDelivery->fresh()->subscription_name);
        $this->assertNull($otherDelivery->fresh()->subscription_code);
        $filteredRows = $this->readXlsx($this->get('/api/v1/user/order/export?' . http_build_query([
            'search' => strtolower($ownDelivery->subscription_code),
        ]))->assertOk());
        $this->assertCount(2, $filteredRows);
        $this->assertSame($ownOrder->trade_no, $filteredRows[1][0]);
        $this->assertExportIdentity($filteredRows, $ownDelivery);
        $this->getJson('/api/v1/user/order/export?' . http_build_query(['search' => $foreignCode]))
            ->assertUnprocessable();
        $this->assertNull($otherDelivery->fresh()->subscription_name);
    }

    public function test_direct_admin_export_backfills_only_selected_merchant_until_an_unfiltered_export_is_requested(): void
    {
        $selected = $this->makeUser('export-admin-selected@example.com', '选择的商户');
        $other = $this->makeUser('export-admin-other@example.com', '其他商户');
        $selectedOrder = $this->createOrder($selected);
        $otherOrder = $this->createOrder($other);
        $selectedDelivery = $selectedOrder->distributorOrder()->firstOrFail();
        $otherDelivery = $otherOrder->distributorOrder()->firstOrFail();
        DB::table('v2_distributor_order')->update(['subscription_code' => null, 'subscription_name' => null]);
        $other->update(['distributor_name' => null]);
        Sanctum::actingAs($this->makeUser('export-name-admin@example.com', null, true));
        $uri = $this->adminRoute(AdminOrderController::class, 'export');
        $filter = ['distributor_user_id' => $selected->id];

        $rows = $this->readXlsx($this->get($uri . '?' . http_build_query($filter))->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame($selectedOrder->trade_no, $rows[1][0]);
        $selectedDelivery->refresh();
        $this->assertStringStartsWith('选择的商户-', $selectedDelivery->subscription_name);
        $this->assertExportIdentity($rows, $selectedDelivery);
        $this->assertNull($otherDelivery->fresh()->subscription_name);
        $this->assertNull($otherDelivery->fresh()->subscription_code);
        $filteredRows = $this->readXlsx($this->get($uri . '?' . http_build_query([
            ...$filter,
            'search' => strtolower($selectedDelivery->subscription_code),
        ]))->assertOk());
        $this->assertCount(2, $filteredRows);
        $this->assertExportIdentity($filteredRows, $selectedDelivery);

        $other->update(['distributor_name' => '其他商户']);
        $allRows = $this->readXlsx($this->get($uri)->assertOk());
        $this->assertCount(3, $allRows);
        $this->assertSame($otherOrder->trade_no, $allRows[1][0]);
        $this->assertExportIdentity($allRows, $otherDelivery->fresh());
        $this->assertSame($selectedDelivery->subscription_name, $selectedDelivery->fresh()->subscription_name);
    }

    public function test_admin_can_save_sixteen_character_merchant_names_and_rejects_long_or_control_character_names(): void
    {
        $admin = $this->makeUser('name-boundary-admin@example.com', null, true);
        $dealer = $this->makeUser('name-boundary@example.com', '初始商户');
        Sanctum::actingAs($admin);
        $uri = $this->adminRoute(AdminUserController::class, 'update');
        foreach ([str_repeat('甲', 16), 'ABCDEFGHIJKLMNOP', str_repeat('😀', 8), '  ABC 中文商户  '] as $name) {
            $this->postJson($uri, ['id' => $dealer->id, 'is_distributor' => true, 'distributor_name' => $name])->assertOk();
            $this->assertSame(trim($name), $dealer->fresh()->distributor_name);
        }

        $savedName = $dealer->fresh()->distributor_name;
        foreach ([str_repeat('甲', 17), 'ABCDEFGHIJKLMNOPQ', str_repeat('😀', 9), "商户\n名称", "商户\t名称", '   ', 'a@b.co'] as $name) {
            $this->postJson($uri, ['id' => $dealer->id, 'is_distributor' => true, 'distributor_name' => $name])
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

    private function readXlsx($response): array
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
        } finally {
            $reader->close();
            @unlink($path);
        }

        return $rows;
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
