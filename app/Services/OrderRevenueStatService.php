<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderRevenueStatService
{
    public const MAX_RANGE_DAYS = 3660;
    private const DEFAULT_MAX_LOOKBACK_DAYS = 365;
    /**
     * Summarize effective order revenue and settlement split for [startAt, endAt).
     *
     * Effective orders exclude pending (0) and cancelled (2) statuses and are
     * attributed to their order creation timestamp so monthly-settled
     * distributor orders appear on the day they were sold rather than spiking
     * on the manual settlement day.
     *
     * @return array{
     *     paid_total: int,
     *     paid_count: int,
     *     settled_total: int,
     *     settled_count: int,
     *     unsettled_total: int,
     *     unsettled_count: int
     * }
     */
    public function periodSummary(int $startAt, int $endAt): array
    {
        if ($startAt >= $endAt) {
            return $this->emptyPeriodSummary();
        }

        $row = DB::table('v2_order')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->selectRaw('
                COUNT(*) as paid_count,
                COALESCE(SUM(total_amount), 0) as paid_total,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NULL OR paid_at IS NOT NULL THEN 1 ELSE 0 END), 0) as settled_count,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NULL OR paid_at IS NOT NULL THEN total_amount ELSE 0 END), 0) as settled_total,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NOT NULL AND paid_at IS NULL THEN 1 ELSE 0 END), 0) as unsettled_count,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NOT NULL AND paid_at IS NULL THEN total_amount ELSE 0 END), 0) as unsettled_total
            ')
            ->first();

        if (!$row) {
            return $this->emptyPeriodSummary();
        }

        return [
            'paid_total' => (int) ($row->paid_total ?? 0),
            'paid_count' => (int) ($row->paid_count ?? 0),
            'settled_total' => (int) ($row->settled_total ?? 0),
            'settled_count' => (int) ($row->settled_count ?? 0),
            'unsettled_total' => (int) ($row->unsettled_total ?? 0),
            'unsettled_count' => (int) ($row->unsettled_count ?? 0),
        ];
    }

    /**
     * Build zero-filled daily revenue and commission series for the dashboard overview.
     *
     * @return array{
     *     list: array<int, array<string, int|float|string>>,
     *     summary: array{
     *         paid_total: int,
     *         paid_count: int,
     *         settled_total: int,
     *         settled_count: int,
     *         unsettled_total: int,
     *         unsettled_count: int,
     *         commission_total: int,
     *         commission_count: int,
     *         start_date: string,
     *         end_date: string,
     *         avg_paid_amount: int|float,
     *         avg_commission_amount: int|float,
     *         commission_rate: int|float
     *     }
     * }
     */
    public function dailyOverview(?string $startDate, ?string $endDate, ?string $type = null): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $end = $endDate !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', $endDate, $timezone)
            : $today;

        $endExclusiveAt = $end->addDay()->startOfDay()->timestamp;

        $start = $startDate !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $timezone)
            : $this->resolveDefaultStartDate($end, $endExclusiveAt, $timezone);

        if ($start->greaterThan($end)) {
            return [
                'list' => [],
                'summary' => [
                    'paid_total' => 0,
                    'paid_count' => 0,
                    'settled_total' => 0,
                    'settled_count' => 0,
                    'unsettled_total' => 0,
                    'unsettled_count' => 0,
                    'commission_total' => 0,
                    'commission_count' => 0,
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                    'avg_paid_amount' => 0,
                    'avg_commission_amount' => 0,
                    'commission_rate' => 0,
                ],
            ];
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'end_date' => sprintf('查询时间范围不能超过%d天', self::MAX_RANGE_DAYS),
            ]);
        }

        $startAt = $start->startOfDay()->timestamp;
        $dayBucketSql = $this->dayBucketExpression('created_at', $start->offset);

        $orderRows = DB::table('v2_order')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endExclusiveAt)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->selectRaw("
                {$dayBucketSql} as day_bucket,
                COUNT(*) as paid_count,
                COALESCE(SUM(total_amount), 0) as paid_total,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NULL OR paid_at IS NOT NULL THEN 1 ELSE 0 END), 0) as settled_count,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NULL OR paid_at IS NOT NULL THEN total_amount ELSE 0 END), 0) as settled_total,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NOT NULL AND paid_at IS NULL THEN 1 ELSE 0 END), 0) as unsettled_count,
                COALESCE(SUM(CASE WHEN distributor_order_id IS NOT NULL AND paid_at IS NULL THEN total_amount ELSE 0 END), 0) as unsettled_total
            ")
            ->groupByRaw($dayBucketSql)
            ->orderByRaw($dayBucketSql)
            ->get()
            ->keyBy(static fn (object $row): string => CarbonImmutable::createFromTimestampUTC(
                (int) $row->day_bucket * 86400
            )->format('Y-m-d'));

        $commissionRows = DB::table('v2_commission_log')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endExclusiveAt)
            ->selectRaw("
                {$dayBucketSql} as day_bucket,
                COUNT(*) as commission_count,
                COALESCE(SUM(get_amount), 0) as commission_total
            ")
            ->groupByRaw($dayBucketSql)
            ->orderByRaw($dayBucketSql)
            ->get()
            ->keyBy(static fn (object $row): string => CarbonImmutable::createFromTimestampUTC(
                (int) $row->day_bucket * 86400
            )->format('Y-m-d'));

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'settled_total' => 0,
            'settled_count' => 0,
            'unsettled_total' => 0,
            'unsettled_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0,
            'commission_rate' => 0,
        ];

        $dailyStats = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $orderRow = $orderRows->get($key);
            $commissionRow = $commissionRows->get($key);

            $paidTotal = (int) ($orderRow->paid_total ?? 0);
            $paidCount = (int) ($orderRow->paid_count ?? 0);
            $settledTotal = (int) ($orderRow->settled_total ?? 0);
            $settledCount = (int) ($orderRow->settled_count ?? 0);
            $unsettledTotal = (int) ($orderRow->unsettled_total ?? 0);
            $unsettledCount = (int) ($orderRow->unsettled_count ?? 0);
            $commissionTotal = (int) ($commissionRow->commission_total ?? 0);
            $commissionCount = (int) ($commissionRow->commission_count ?? 0);

            $summary['paid_total'] += $paidTotal;
            $summary['paid_count'] += $paidCount;
            $summary['settled_total'] += $settledTotal;
            $summary['settled_count'] += $settledCount;
            $summary['unsettled_total'] += $unsettledTotal;
            $summary['unsettled_count'] += $unsettledCount;
            $summary['commission_total'] += $commissionTotal;
            $summary['commission_count'] += $commissionCount;

            $dailyData = [
                'date' => $key,
                'paid_total' => $paidTotal,
                'paid_count' => $paidCount,
                'settled_total' => $settledTotal,
                'settled_count' => $settledCount,
                'unsettled_total' => $unsettledTotal,
                'unsettled_count' => $unsettledCount,
                'commission_total' => $commissionTotal,
                'commission_count' => $commissionCount,
                'avg_order_amount' => $paidCount > 0 ? round($paidTotal / $paidCount, 2) : 0,
                'avg_commission_amount' => $commissionCount > 0 ? round($commissionTotal / $commissionCount, 2) : 0,
            ];

            if ($type !== null && $type !== '') {
                $dailyStats[] = [
                    'date' => $key,
                    'value' => $dailyData[$type],
                    'type' => $this->typeLabel($type),
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }
        if ($summary['paid_total'] > 0) {
            $summary['commission_rate'] = round(($summary['commission_total'] / $summary['paid_total']) * 100, 2);
        }

        return [
            'list' => $dailyStats,
            'summary' => $summary,
        ];
    }

    private function resolveDefaultStartDate(
        CarbonImmutable $end,
        int $endExclusiveAt,
        string $timezone
    ): CarbonImmutable {
        $earliestOrderAt = DB::table('v2_order')
            ->where('created_at', '<', $endExclusiveAt)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->min('created_at');

        $earliestCommissionAt = DB::table('v2_commission_log')
            ->where('created_at', '<', $endExclusiveAt)
            ->min('created_at');

        $candidates = array_values(array_filter(
            [$earliestOrderAt, $earliestCommissionAt],
            static fn (mixed $value): bool => $value !== null && (int) $value > 0
        ));

        if ($candidates === []) {
            return $end->subDays(29);
        }

        $earliestTimestamp = (int) min(array_map('intval', $candidates));
        $earliestDate = CarbonImmutable::createFromTimestamp($earliestTimestamp, $timezone)->startOfDay();
        $minAllowedStart = $end->subDays(self::DEFAULT_MAX_LOOKBACK_DAYS);

        return $earliestDate->lessThan($minAllowedStart) ? $minAllowedStart : $earliestDate;
    }

    private function dayBucketExpression(string $column, int $offsetSeconds): string
    {
        $shifted = $offsetSeconds >= 0
            ? "{$column} + {$offsetSeconds}"
            : "{$column} - " . abs($offsetSeconds);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "CASE WHEN ({$shifted}) >= 0 THEN CAST(({$shifted}) / 86400 AS INTEGER) ELSE CAST((({$shifted}) - 86399) / 86400 AS INTEGER) END",
            default => "FLOOR(({$shifted}) / 86400)",
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type,
        };
    }

    /**
     * @return array{
     *     paid_total: int,
     *     paid_count: int,
     *     settled_total: int,
     *     settled_count: int,
     *     unsettled_total: int,
     *     unsettled_count: int
     * }
     */
    private function emptyPeriodSummary(): array
    {
        return [
            'paid_total' => 0,
            'paid_count' => 0,
            'settled_total' => 0,
            'settled_count' => 0,
            'unsettled_total' => 0,
            'unsettled_count' => 0,
        ];
    }
}
