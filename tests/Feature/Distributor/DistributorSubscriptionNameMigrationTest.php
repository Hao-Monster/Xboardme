<?php

namespace Tests\Feature\Distributor;

use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistributorSubscriptionNameMigrationTest extends TestCase
{
    // MySQL DDL commits implicitly, so migration tests cannot share an outer test transaction.
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();

        $this->beforeApplicationDestroyed(function (): void {
            try {
                // The target migration's down() is tested explicitly. Cleanup must not
                // run unrelated historical down() methods against today's fixtures.
                $this->artisan('db:wipe')->assertExitCode(0);
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_migration_preserves_legacy_subscriptions_without_assigning_names_and_is_repeatable(): void
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
        $this->assertNull($migrated->subscription_name);
        $this->assertNull($migrated->subscription_code);
        $migratedBefore = $migrated->getRawOriginal();
        $migration->up();

        $this->assertSame($migratedBefore, $delivery->fresh()->getRawOriginal());
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
        $deliveryAfter = $delivery->fresh()->getRawOriginal();
        unset($deliveryAfter['subscription_code'], $deliveryAfter['subscription_name']);
        $this->assertSame($deliveryBefore, $deliveryAfter);
    }

    public function test_invalid_legacy_merchant_names_do_not_block_migration_or_legacy_reads(): void
    {
        $migration = require database_path('migrations/2026_10_06_000001_add_distributor_subscription_names.php');
        foreach ([null, '   ', 'a@b.co', str_repeat('甲', 17)] as $index => $invalidName) {
            $dealer = $this->makeUser('invalid-legacy-' . $index . '@example.com', '历史有效商户');
            $order = $this->createOrder($dealer);
            $delivery = $this->asLegacySubscription($order);
            $orderBefore = $order->fresh()->getRawOriginal();
            $subscriberBefore = $delivery->subscriber->fresh()->getRawOriginal();
            $deliveryBefore = $delivery->getRawOriginal();
            $dealer->update(['distributor_name' => $invalidName]);

            $migration->up();
            Sanctum::actingAs($dealer);
            $this->getJson('/api/v1/user/order/fetch')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.subscription_name', null);
            $url = app(DistributorOrderService::class)->subscriptionUrl($delivery->fresh());
            $this->assertSame($order->trade_no, rawurldecode((string) parse_url($url, PHP_URL_FRAGMENT)));
            $this->getJson('/api/v1/user/distributor/subscription-qr?' . http_build_query(['trade_no' => $order->trade_no]))
                ->assertOk()->assertJsonPath('data.subscription_name', null);

            $this->assertNull($delivery->fresh()->subscription_name);
            $this->assertNull($delivery->fresh()->subscription_code);
            $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
            $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
            $this->assertSame($deliveryBefore, $delivery->fresh()->getRawOriginal());
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
            $this->assertNull($first->subscription_code);
            $this->assertNull($first->subscription_name);
            $this->assertNull($second->subscription_code);
            $this->assertNull($second->subscription_name);
            $newFirst = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
            $newSecond = $this->createOrder($dealer)->distributorOrder()->firstOrFail();
            try {
                DB::transaction(function () use ($newFirst, $newSecond) {
                    DB::table('v2_distributor_order')->where('id', $newSecond->id)
                        ->update(['subscription_code' => $newFirst->subscription_code]);
                });
                $this->fail('Resumed migration must recreate the database uniqueness constraint.');
            } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
                $this->assertNotSame($newFirst->subscription_code, $newSecond->fresh()->subscription_code);
            }
        }
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
