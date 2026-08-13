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
        '订单号', '用户名称', '分销商', '套餐', '原价', '结算状态', '备注',
    ];

    private const DISTRIBUTOR_HEADERS = [
        '订单号', '用户名称', '订阅计划', '周期', '订单金额', '结算状态', '备注',
    ];

    public function __construct(private readonly DistributorOrderSearchService $searchService)
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
                $query->where('v2_distributor_order.settlement_status', $settlementStatus);
            });
        $this->searchService->applyToExportQuery($query, $search, true);

        return $this->download(
            $query,
            self::ADMIN_HEADERS,
            fn (object $order): array => [
                (string) $order->trade_no,
                (string) ($order->customer_name ?: '-'),
                (string) ($order->distributor_name ?: $order->distributor_email),
                (string) ($order->plan_name ?: '-'),
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
        ?string $search = null
    ): BinaryFileResponse
    {
        $query = $this->baseQuery()
            ->where('v2_order.user_id', $distributorUserId)
            ->where('v2_distributor_order.distributor_user_id', $distributorUserId)
            ->when($settlementStatus !== null, function (Builder $query) use ($settlementStatus) {
                $query->where('v2_distributor_order.settlement_status', $settlementStatus);
            });
        $this->searchService->applyToExportQuery($query, $search);

        return $this->download(
            $query,
            self::DISTRIBUTOR_HEADERS,
            fn (object $order): array => [
                (string) $order->trade_no,
                (string) ($order->customer_name ?: '-'),
                (string) ($order->plan_name ?: '-'),
                $this->periodLabel((string) $order->period),
                $this->yuan($order->total_amount),
                $this->settlementLabel((int) $order->settlement_status),
                (string) ($order->remark ?? ''),
            ],
            '我的分销订单'
        );
    }

    private function baseQuery(): Builder
    {
        return DB::table('v2_distributor_order')
            ->join('v2_order', 'v2_order.id', '=', 'v2_distributor_order.order_id')
            ->join('v2_user as distributor', 'distributor.id', '=', 'v2_distributor_order.distributor_user_id')
            ->leftJoin('v2_user as subscriber', 'subscriber.id', '=', 'v2_distributor_order.subscriber_user_id')
            ->leftJoin('v2_plan', 'v2_plan.id', '=', 'v2_order.plan_id')
            ->select([
                'v2_order.id',
                'v2_order.user_id',
                'v2_order.trade_no',
                'v2_order.period',
                'v2_order.total_amount',
                'v2_order.created_at',
                'v2_distributor_order.customer_name',
                'v2_distributor_order.remark',
                'distributor.email as distributor_email',
                'distributor.distributor_name as distributor_name',
                'v2_plan.name as plan_name',
                'v2_distributor_order.settlement_status',
            ])
            ->orderByDesc('v2_order.created_at')
            ->orderByDesc('v2_order.id');
    }

    /**
     * @param array<int, string> $headers
     * @param callable(object): array<int, float|string> $rowMapper
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
            $sheet->setColumnWidth(28, 1);
            $sheet->setColumnWidth(22, 2);
            $sheet->setColumnWidth(28, 3);
            $sheet->setColumnWidth(24, 4);
            $sheet->setColumnWidth(14, 5, 6);
            $sheet->setColumnWidth(42, 7);

            $headerStyle = (new Style())
                ->setFontBold()
                ->setFontColor(Color::WHITE)
                ->setBackgroundColor(Color::DARK_BLUE);
            $amountStyle = (new Style())->setFormat('0.00');

            $writer->addRow(Row::fromValues($headers, $headerStyle));
            $dataRows = 0;
            foreach ($query->cursor() as $order) {
                $values = $rowMapper($order);
                $remarkIndex = count($values) - 1;
                $row = Row::fromValuesWithStyles(
                    $values,
                    null,
                    [4 => $amountStyle]
                );
                // Force the administrator-authored remark to remain literal text even
                // when it starts with "=", which OpenSpout otherwise treats as a formula.
                $row->setCellAtIndex(new StringCell((string) $values[$remarkIndex], null), $remarkIndex);
                $writer->addRow($row);
                ++$dataRows;
            }

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

    private function periodLabel(string $period): string
    {
        $periodKey = PlanService::getPeriodKey($period);
        $periods = Plan::getAvailablePeriods();

        return $periods[$periodKey]['name'] ?? $period;
    }
}
