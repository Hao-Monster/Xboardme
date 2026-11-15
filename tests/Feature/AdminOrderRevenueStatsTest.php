<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderRevenueStatService;
use App\Services\StatisticalService;
use App\Utils\Helper;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderRevenueStatsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Cache::flush();
        $this->now = Carbon::create(2026, 11, 11, 20, 0, 0, 'Asia/Shanghai');
        Carbon::setTestNow($this->now);
        CarbonImmutable::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_revenue_overview_and_stats_align_across_distributor_monthly_settlement_and_include_today(): void
    {
        $admin = $this->user('admin@example.com', ['is_admin' => true]);
        $distributor = $this->user('distributor@example.com', ['is_distributor' => true]);
        $directUser = $this->user('direct@example.com');
        $inviter = $this->user('inviter@example.com');
        $plan = $this->plan();

        $settledOnNov3 = $this->timestamp('2026-11-03 14:30:00');

        // Oct 20 distributor order that was settled later on Nov 3:
        // must belong to 2026-10-20, never spiking 2026-11-03.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 1800,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 101,
            'paid_at' => $settledOnNov3,
            'created_at' => $this->timestamp('2026-10-20 10:00:00'),
        ]);

        // Oct 31 23:59:59 Asia/Shanghai (15:59:59 UTC) unsettled distributor order:
        // must land in 2026-10-31 and October lastMonthIncome.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 1200,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 102,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-10-31 23:59:59'),
        ]);

        // Nov 1 00:00:00 Asia/Shanghai (Oct 31 16:00:00 UTC) unsettled distributor order:
        // must land in 2026-11-01 and November currentMonthIncome.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 2400,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 103,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-11-01 00:00:00'),
        ]);

        // Nov 10 (yesterday) completed distributor order for dayIncomeGrowth check.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 1000,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 104,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-11-10 09:00:00'),
        ]);

        // Today (Nov 11) unsettled distributor order created at exact current second.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 1200,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 105,
            'paid_at' => null,
            'created_at' => $this->now->timestamp,
        ]);

        // Today (Nov 11) direct user paid order (STATUS_PROCESSING) with partial internal balance deduction:
        // only external total_amount (800) counts; balance_amount (2200) must not double-count.
        $this->insertOrder([
            'user_id' => $directUser->id,
            'plan_id' => $plan->id,
            'total_amount' => 800,
            'balance_amount' => 2200,
            'status' => Order::STATUS_PROCESSING,
            'distributor_order_id' => null,
            'paid_at' => $this->now->timestamp,
            'created_at' => $this->timestamp('2026-11-11 11:00:00'),
        ]);

        // Excluded orders on Nov 11: unpaid pending (0) and cancelled (2).
        $this->insertOrder([
            'user_id' => $directUser->id,
            'plan_id' => $plan->id,
            'total_amount' => 9900,
            'status' => Order::STATUS_PENDING,
            'distributor_order_id' => null,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-11-11 12:00:00'),
        ]);
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 8800,
            'status' => Order::STATUS_CANCELLED,
            'distributor_order_id' => 106,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-11-11 13:00:00'),
        ]);

        $this->insertCommission($inviter->id, $directUser->id, 800, 160, $this->timestamp('2026-11-11 11:05:00'));

        Sanctum::actingAs($admin);

        $orderOverview = $this->getJson($this->endpoint('stat/getOrder?start_date=2026-10-12&end_date=2026-11-11'))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->json('data');

        $this->assertCount(31, $orderOverview['list']);
        $byDate = collect($orderOverview['list'])->keyBy('date');

        $this->assertSame('2026-10-12', $orderOverview['list'][0]['date']);
        $this->assertSame('2026-11-11', $orderOverview['list'][30]['date']);

        $this->assertSame(1800, $byDate['2026-10-20']['paid_total']);
        $this->assertSame(1800, $byDate['2026-10-20']['settled_total']);
        $this->assertSame(0, $byDate['2026-10-20']['unsettled_total']);

        $this->assertSame(1200, $byDate['2026-10-31']['paid_total']);
        $this->assertSame(0, $byDate['2026-10-31']['settled_total']);
        $this->assertSame(1200, $byDate['2026-10-31']['unsettled_total']);

        $this->assertSame(2400, $byDate['2026-11-01']['paid_total']);
        $this->assertSame(0, $byDate['2026-11-03']['paid_total']);
        $this->assertSame(0, $byDate['2026-11-03']['paid_count']);

        $this->assertSame(2000, $byDate['2026-11-11']['paid_total']);
        $this->assertSame(2, $byDate['2026-11-11']['paid_count']);
        $this->assertSame(800, $byDate['2026-11-11']['settled_total']);
        $this->assertSame(1200, $byDate['2026-11-11']['unsettled_total']);
        $this->assertSame(160, $byDate['2026-11-11']['commission_total']);
        $this->assertSame(1, $byDate['2026-11-11']['commission_count']);

        // Total across Oct 12..Nov 11: 1800 + 1200 + 2400 + 1000 + 1200 + 800 = 8400 across 6 orders.
        $this->assertSame(8400, $orderOverview['summary']['paid_total']);
        $this->assertSame(6, $orderOverview['summary']['paid_count']);
        $this->assertSame(2600, $orderOverview['summary']['settled_total']);
        $this->assertSame(5800, $orderOverview['summary']['unsettled_total']);
        $this->assertEquals(1400, $orderOverview['summary']['avg_paid_amount']);
        $this->assertSame(160, $orderOverview['summary']['commission_total']);
        $this->assertEquals(1.9, $orderOverview['summary']['commission_rate']);

        $novemberSumInChart = collect($orderOverview['list'])
            ->filter(static fn (array $item): bool => str_starts_with((string) $item['date'], '2026-11-'))
            ->sum('paid_total');
        $this->assertSame(5400, $novemberSumInChart);

        // Top cards (/stat/getStats and /stat/getOverride) must match the chart identically.
        $stats = $this->getJson($this->endpoint('stat/getStats'))
            ->assertOk()
            ->json('data');

        $this->assertSame(2000, $stats['todayIncome']);
        $this->assertSame(800, $stats['todaySettledIncome']);
        $this->assertSame(1200, $stats['todayUnsettledIncome']);
        $this->assertEquals(100, $stats['dayIncomeGrowth']);
        $this->assertSame($novemberSumInChart, $stats['currentMonthIncome']);
        $this->assertSame(800, $stats['currentMonthSettledIncome']);
        $this->assertSame(4600, $stats['currentMonthUnsettledIncome']);
        $this->assertSame(3000, $stats['lastMonthIncome']);
        $this->assertSame(1800, $stats['lastMonthSettledIncome']);
        $this->assertSame(1200, $stats['lastMonthUnsettledIncome']);
        $this->assertEquals(80, $stats['monthIncomeGrowth']);

        $override = $this->getJson($this->endpoint('stat/getOverride'))
            ->assertOk()
            ->json('data');

        $this->assertSame(2000, $override['day_income']);
        $this->assertSame(5400, $override['month_income']);
        $this->assertSame(3000, $override['last_month_income']);
    }

    public function test_get_order_supports_empty_state_default_window_and_single_metric_type_mode(): void
    {
        $admin = $this->user('admin-empty@example.com', ['is_admin' => true]);
        Sanctum::actingAs($admin);

        // Empty database without query params must return a clean 30-day zero-filled window, not 1970-01-01.
        $emptyResponse = $this->getJson($this->endpoint('stat/getOrder'))
            ->assertOk()
            ->json('data');

        $this->assertCount(30, $emptyResponse['list']);
        $this->assertSame('2026-10-13', $emptyResponse['summary']['start_date']);
        $this->assertSame('2026-11-11', $emptyResponse['summary']['end_date']);
        $this->assertSame(0, $emptyResponse['summary']['paid_total']);
        $this->assertSame(0, $emptyResponse['summary']['commission_rate']);

        // Reversed range returns empty list without error.
        $reversed = $this->getJson($this->endpoint('stat/getOrder?start_date=2026-11-12&end_date=2026-11-11'))
            ->assertOk()
            ->json('data');
        $this->assertSame([], $reversed['list']);

        // Pathological multi-decade or out-of-bounds ranges reject cleanly (422) without unbounded memory loops.
        $this->getJson($this->endpoint('stat/getOrder?start_date=0001-01-01&end_date=9999-12-31'))
            ->assertStatus(422);
        $this->getJson($this->endpoint('stat/getOrder?start_date=2010-01-01&end_date=2026-11-11'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);

        // Single metric type filter preserves { date, value, type } item structure.
        $user = $this->user('buyer@example.com');
        $plan = $this->plan();
        $this->insertOrder([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'total_amount' => 3600,
            'status' => Order::STATUS_COMPLETED,
            'paid_at' => $this->timestamp('2026-11-10 08:00:00'),
            'created_at' => $this->timestamp('2026-11-10 08:00:00'),
        ]);

        $typed = $this->getJson($this->endpoint('stat/getOrder?start_date=2026-11-10&end_date=2026-11-11&type=paid_total'))
            ->assertOk()
            ->json('data');

        $this->assertSame([
            ['date' => '2026-11-10', 'value' => 3600, 'type' => '收款金额'],
            ['date' => '2026-11-11', 'value' => 0, 'type' => '收款金额'],
        ], $typed['list']);
        $this->assertSame(3600, $typed['summary']['paid_total']);
    }

    public function test_statistical_service_generate_stat_data_uses_the_same_order_creation_window(): void
    {
        $distributor = $this->user('stat-dealer@example.com', ['is_distributor' => true]);
        $plan = $this->plan();

        $dayStart = $this->timestamp('2026-11-03 00:00:00');
        $dayEnd = $this->timestamp('2026-11-04 00:00:00');

        // Created in October, settled on Nov 3: must NOT be counted in Nov 3 daily stat.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 5000,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 201,
            'paid_at' => $this->timestamp('2026-11-03 15:00:00'),
            'created_at' => $this->timestamp('2026-10-15 10:00:00'),
        ]);

        // Created on Nov 3, unsettled: MUST be counted in Nov 3 daily stat.
        $this->insertOrder([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'total_amount' => 1500,
            'status' => Order::STATUS_COMPLETED,
            'distributor_order_id' => 202,
            'paid_at' => null,
            'created_at' => $this->timestamp('2026-11-03 16:00:00'),
        ]);

        $statService = new StatisticalService();
        $statService->setStartAt($dayStart);
        $statService->setEndAt($dayEnd);

        $generated = $statService->generateStatData();
        $expected = app(OrderRevenueStatService::class)->periodSummary($dayStart, $dayEnd);

        $this->assertSame(1, $generated['paid_count']);
        $this->assertSame(1500, $generated['paid_total']);
        $this->assertSame($expected['paid_total'], $generated['paid_total']);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 11, 4, 0, 10, 0, 'Asia/Shanghai'));
        $this->artisan('xboard:statistics')->assertExitCode(0);
        $this->assertDatabaseHas('v2_stat', [
            'record_at' => $dayStart,
            'record_type' => 'd',
            'paid_count' => 1,
            'paid_total' => 1500,
        ]);
    }

    private function endpoint(string $path): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return '/api/v2/' . $securePath . '/' . ltrim($path, '/');
    }

    private function timestamp(string $dateTime): int
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $dateTime, 'Asia/Shanghai')->timestamp;
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $email, array $attributes = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => false,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
            't' => 0,
        ], $attributes));
    }

    private function plan(): Plan
    {
        return Plan::query()->create([
            'group_id' => 1,
            'transfer_enable' => 100,
            'name' => 'Revenue Test Plan',
            'prices' => [Plan::PERIOD_MONTHLY => 12],
            'show' => true,
            'sell' => true,
            'renew' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function insertOrder(array $attributes): void
    {
        $createdAt = (int) ($attributes['created_at'] ?? $this->now->timestamp);

        DB::table('v2_order')->insert(array_merge([
            'invite_user_id' => null,
            'user_id' => 1,
            'plan_id' => 1,
            'coupon_id' => null,
            'payment_id' => null,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => substr(str_replace('-', '', Helper::guid()), 0, 32),
            'callback_no' => null,
            'total_amount' => 1200,
            'handling_amount' => 0,
            'discount_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'balance_amount' => 0,
            'surplus_order_ids' => null,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 0,
            'commission_balance' => 0,
            'actual_commission_balance' => null,
            'paid_at' => null,
            'distributor_order_id' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $attributes));
    }

    private function insertCommission(
        int $inviteUserId,
        int $userId,
        int $orderAmount,
        int $getAmount,
        int $createdAt
    ): void {
        CommissionLog::query()->insert([
            'invite_user_id' => $inviteUserId,
            'user_id' => $userId,
            'trade_no' => substr(str_replace('-', '', Helper::guid()), 0, 32),
            'order_amount' => $orderAmount,
            'get_amount' => $getAmount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
