<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorOrder;
use App\Models\Plan;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class DistributorOrderExportService
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const ADMIN_HEADERS = [
        '订单号', '订单类型', '关联原订单', '用户名称', '已绑定设备', '已用流量', '分销商', '套餐', '周期', '原价', '结算状态', '备注',
    ];

    private const DISTRIBUTOR_HEADERS = [
        '订单号', '订单类型', '关联原订单', '用户名称', '订阅计划', '周期', '订单金额', '已绑定设备', '已用流量', '结算状态', '备注',
    ];

    public function __construct(
        private readonly DistributorOrderSearchService $searchService,
        private readonly DistributorOrderFilterService $filterService
    )
    {
    }

    public function downloadForAdmin(
        ?int $distributorUserId,
        ?int $settlementStatus,
        ?string $search = null
    ): BinaryFileResponse
    {
        $query = $this->baseQuery()
            ->when($distributorUserId !== null, function (Builder $query) use ($distributorUserId) {
                $query->where('v2_distributor_order.distributor_user_id', $distributorUserId);
            })
            ->when($settlementStatus !== null, function (Builder $query) use ($settlementStatus) {
                $settlementStatus === DistributorOrder::SETTLEMENT_SETTLED
                    ? $query->whereNotNull('v2_order.paid_at')
                    : $query->whereNull('v2_order.paid_at');
            });
        $this->searchService->applyToExportQuery($query, $search, true);

        return $this->download(
            $query,
            self::ADMIN_HEADERS,
            fn (object $order): array => [
                (string) $order->trade_no,
                $this->typeLabel((int) $order->type),
                (int) $order->type === \App\Models\Order::TYPE_RENEWAL
                    ? (string) $order->subscription_trade_no
                    : '-',
                (string) ($order->customer_name ?: '-'),
                (string) $order->bound_device_labels,
                $this->trafficLabel($order->used_traffic),
                (string) ($order->distributor_name ?: $order->distributor_email),
                (string) ($order->plan_name ?: '-'),
                $this->periodLabel((string) $order->period),
                $this->yuan($order->total_amount),
                $this->settlementLabel((int) $order->settlement_status),
                (string) ($order->remark ?? ''),
            ],
            '分销订单'
        );
    }

    public function downloadForDistributor(
        int $distributorUserId,
        ?int $settlementStatus,
        ?string $search = null,
        array $filters = []
    ): BinaryFileResponse
    {
        $query = $this->baseQuery()
            ->where('v2_order.user_id', $distributorUserId)
            ->where('v2_distributor_order.distributor_user_id', $distributorUserId)
            ->when($settlementStatus !== null, function (Builder $query) use ($settlementStatus) {
                $settlementStatus === DistributorOrder::SETTLEMENT_SETTLED
                    ? $query->whereNotNull('v2_order.paid_at')
                    : $query->whereNull('v2_order.paid_at');
            });
        $this->searchService->applyToExportQuery($query, $search);
        if ($filters !== []) {
            $this->filterService->apply($query, $filters, 'v2_order');
        }

        return $this->download(
            $query,
            self::DISTRIBUTOR_HEADERS,
            fn (object $order): array => [
                (string) $order->trade_no,
                $this->typeLabel((int) $order->type),
                (int) $order->type === \App\Models\Order::TYPE_RENEWAL
                    ? (string) $order->subscription_trade_no
                    : '-',
                (string) ($order->customer_name ?: '-'),
                (string) ($order->plan_name ?: '-'),
                $this->periodLabel((string) $order->period),
                $this->yuan($order->total_amount),
                (string) $order->bound_device_labels,
                $this->trafficLabel($order->used_traffic),
                $this->settlementLabel((int) $order->settlement_status),
                (string) ($order->remark ?? ''),
            ],
            '我的分销订单'
        );
    }

    private function baseQuery(): Builder
    {
        return DB::table('v2_order')
            ->join('v2_distributor_order', 'v2_distributor_order.id', '=', 'v2_order.distributor_order_id')
            ->join('v2_order as root_order', 'root_order.id', '=', 'v2_distributor_order.order_id')
            ->join('v2_user as distributor', 'distributor.id', '=', 'v2_distributor_order.distributor_user_id')
            ->leftJoin('v2_user as subscriber', 'subscriber.id', '=', 'v2_distributor_order.subscriber_user_id')
            ->leftJoin('v2_plan', 'v2_plan.id', '=', 'v2_order.plan_id')
            ->select([
                'v2_order.id',
                'v2_order.user_id',
                'v2_order.trade_no',
                'v2_order.type',
                'v2_order.period',
                'v2_order.total_amount',
                'v2_order.created_at',
                'v2_distributor_order.id as distributor_order_id',
                'v2_distributor_order.customer_name',
                'v2_distributor_order.remark',
                'distributor.email as distributor_email',
                'distributor.distributor_name as distributor_name',
                'v2_plan.name as plan_name',
                'root_order.trade_no as subscription_trade_no',
                DB::raw('CASE WHEN v2_order.paid_at IS NULL THEN 0 ELSE 1 END as settlement_status'),
                DB::raw('CASE WHEN COALESCE(subscriber.u, 0) + COALESCE(subscriber.d, 0) < 0 THEN 0 ELSE COALESCE(subscriber.u, 0) + COALESCE(subscriber.d, 0) END as used_traffic'),
            ])
            ->orderByDesc('v2_order.created_at')
            ->orderByDesc('v2_order.id');
    }

    /**
     * @param array<int, string> $headers
     * @param callable(object): array<int, float|int|string> $rowMapper
     */
    private function download(Builder $query, array $headers, callable $rowMapper, string $filePrefix): BinaryFileResponse
    {
        if (!(clone $query)->exists()) {
            throw new ApiException('当前筛选条件下没有可导出的订单', 422);
        }

        $path = tempnam(sys_get_temp_dir(), 'xboard-distributor-orders-');
        if ($path === false) {
            throw new ApiException('导出文件创建失败，请稍后重试', 500);
        }

        $writer = new Writer();
        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName('分销订单');
            $sheet->setSheetView((new SheetView())->setFreezeRow(2));
            foreach ($headers as $index => $header) {
                $width = match ($header) {
                    '订单号', '关联原订单' => 28,
                    '用户名称', '分销商', '套餐', '订阅计划' => 22,
                    '已用流量' => 16,
                    '备注' => 42,
                    default => 14,
                };
                $sheet->setColumnWidth($width, $index + 1);
            }

            $headerStyle = (new Style())
                ->setFontBold()
                ->setFontColor(Color::WHITE)
                ->setBackgroundColor(Color::DARK_BLUE);
            $amountStyle = (new Style())->setFormat('0.00');
            $wrappedTextStyle = (new Style())->setShouldWrapText();

            $writer->addRow(Row::fromValues($headers, $headerStyle));
            $amountIndex = array_search('原价', $headers, true);
            if ($amountIndex === false) {
                $amountIndex = array_search('订单金额', $headers, true);
            }
            $dataRows = 0;
            $deviceIndex = array_search('已绑定设备', $headers, true);
            $query->chunk(500, function ($orders) use (
                $rowMapper,
                $amountIndex,
                $amountStyle,
                $deviceIndex,
                $wrappedTextStyle,
                $writer,
                &$dataRows
            ) {
                $deviceLabels = DB::table('v2_distributor_hwid_device')
                    ->whereIn('distributor_order_id', $orders->pluck('distributor_order_id')->unique()->values())
                    ->orderByDesc('last_seen_at')
                    ->orderByDesc('id')
                    ->get(['distributor_order_id', 'hwid', 'device_model'])
                    ->groupBy('distributor_order_id')
                    ->map(static fn($devices): string => $devices
                        ->map(static function (object $device): string {
                            $hwid = trim((string) $device->hwid);
                            $model = trim((string) $device->device_model);

                            return $model !== '' ? "{$model} {$hwid}" : $hwid;
                        })
                        ->filter()
                        ->implode("\n"));

                foreach ($orders as $order) {
                    $order->bound_device_labels = (string) $deviceLabels->get($order->distributor_order_id, '');
                    $values = $rowMapper($order);
                    $row = Row::fromValuesWithStyles(
                        $values,
                        null,
                        [$amountIndex => $amountStyle]
                    );
                    // Keep every textual export field literal so customer names, HWIDs,
                    // remarks, and administrator-defined labels cannot become formulas.
                    foreach ($values as $index => $value) {
                        if (is_string($value)) {
                            $row->setCellAtIndex(
                                new StringCell($value, $index === $deviceIndex ? $wrappedTextStyle : null),
                                $index
                            );
                        }
                    }
                    $writer->addRow($row);
                    ++$dataRows;
                }
            });

            $sheet->setAutoFilter(new AutoFilter(0, 1, count($headers) - 1, $dataRows + 1));
            $writer->close();
        } catch (Throwable $exception) {
            $writer->close();
            @unlink($path);
            throw $exception;
        }

        $filename = sprintf('%s_%s.xlsx', $filePrefix, now()->format('Ymd_His'));

        return response()->download($path, $filename, [
            'Content-Type' => self::CONTENT_TYPE,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    private function yuan(mixed $amount): float
    {
        return round(((int) $amount) / 100, 2);
    }

    private function settlementLabel(int $status): string
    {
        return $status === DistributorOrder::SETTLEMENT_SETTLED ? '已结算' : '未结算';
    }

    private function trafficLabel(mixed $bytes): string
    {
        $value = max(0, (int) $bytes);
        $gibibyte = 1024 ** 3;
        if ($value >= $gibibyte) {
            $precision = $value % $gibibyte === 0 ? 0 : 2;

            return number_format($value / $gibibyte, $precision, '.', '') . ' GB';
        }
        if ($value >= 1024 ** 2) {
            return number_format($value / (1024 ** 2), 2, '.', '') . ' MB';
        }
        if ($value >= 1024) {
            return number_format($value / 1024, 2, '.', '') . ' KB';
        }

        return $value . ' B';
    }

    private function periodLabel(string $period): string
    {
        $periodKey = PlanService::getPeriodKey($period);
        $periods = Plan::getAvailablePeriods();

        return $periods[$periodKey]['name'] ?? $period;
    }

    private function typeLabel(int $type): string
    {
        return \App\Models\Order::$typeMap[$type] ?? (string) $type;
    }
}
