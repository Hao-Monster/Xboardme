<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DistributorOrderStatisticsService
{
    /**
     * @return array{
     *   range:array{start_date:string,end_date:string,days:int},
     *   summary:array{order_count:int,total_amount:int},
     *   daily:array<int,array{date:string,order_count:int,total_amount:int}>
     * }
     */
    public function forDistributor(int $distributorUserId, string $startDate, string $endDate): array
    {
        $timezone = (string) config('app.timezone');
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, $timezone);
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, $timezone);
        $startAt = $start->startOfDay()->timestamp;
        $endAt = $end->addDay()->startOfDay()->timestamp;

        // Unix timestamps are grouped into Asia/Shanghai calendar days without
        // relying on database/session timezone tables.
        $dayBucketSql = match (DB::connection()->getDriverName()) {
            'sqlite' => 'CAST((v2_order.created_at + 28800) / 86400 AS INTEGER)',
            default => 'FLOOR((v2_order.created_at + 28800) / 86400)',
        };

        $rows = DB::table('v2_order')
            ->join('v2_distributor_order', 'v2_distributor_order.id', '=', 'v2_order.distributor_order_id')
            ->where('v2_order.user_id', $distributorUserId)
            ->where('v2_distributor_order.distributor_user_id', $distributorUserId)
            ->where('v2_order.created_at', '>=', $startAt)
            ->where('v2_order.created_at', '<', $endAt)
            ->selectRaw("{$dayBucketSql} as day_bucket, COUNT(*) as order_count, COALESCE(SUM(v2_order.total_amount), 0) as total_amount")
            ->groupByRaw($dayBucketSql)
            ->orderByRaw($dayBucketSql)
            ->get()
            ->keyBy(static fn(object $row): string => CarbonImmutable::createFromTimestampUTC(
                (int) $row->day_bucket * 86400
            )->format('Y-m-d'));

        $daily = [];
        $orderCount = 0;
        $totalAmount = 0;
        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);
            $count = (int) ($row->order_count ?? 0);
            $amount = (int) ($row->total_amount ?? 0);
            $daily[] = [
                'date' => $key,
                'order_count' => $count,
                'total_amount' => $amount,
            ];
            $orderCount += $count;
            $totalAmount += $amount;
        }

        return [
            'range' => [
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'days' => (int) $start->diffInDays($end) + 1,
            ],
            'summary' => [
                'order_count' => $orderCount,
                'total_amount' => $totalAmount,
            ],
            'daily' => $daily,
        ];
    }
}
