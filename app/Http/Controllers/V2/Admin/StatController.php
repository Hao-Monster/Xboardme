<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrderRevenueStatService;
use App\Services\RealtimeStatsService;
use App\Services\StatisticalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class StatController extends Controller
{
    private $service;
    private RealtimeStatsService $realtimeStatsService;
    private OrderRevenueStatService $orderRevenueStatService;

    public function __construct(
        StatisticalService $service,
        RealtimeStatsService $realtimeStatsService,
        ?OrderRevenueStatService $orderRevenueStatService = null
    ) {
        $this->service = $service;
        $this->realtimeStatsService = $realtimeStatsService;
        $this->orderRevenueStatService = $orderRevenueStatService ?? app(OrderRevenueStatService::class);
    }

    public function getOverride(Request $request)
    {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $now = CarbonImmutable::now($timezone);
        $nowEndExclusive = $now->timestamp + 1;
        $todayStart = $now->startOfDay()->timestamp;
        $monthStart = $now->startOfMonth()->timestamp;
        $lastMonthStart = $now->startOfMonth()->subMonthNoOverflow()->timestamp;

        $realtimeStats = $this->realtimeStatsService->snapshot();

        // 获取今日流量统计
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', $nowEndExclusive)
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthTraffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', $nowEndExclusive)
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        $monthSummary = $this->orderRevenueStatService->periodSummary($monthStart, $nowEndExclusive);
        $daySummary = $this->orderRevenueStatService->periodSummary($todayStart, $nowEndExclusive);
        $lastMonthSummary = $this->orderRevenueStatService->periodSummary($lastMonthStart, $monthStart);

        return [
            'data' => [
                'month_income' => $monthSummary['paid_total'],
                'month_settled_income' => $monthSummary['settled_total'],
                'month_unsettled_income' => $monthSummary['unsettled_total'],
                'month_register_total' => User::where('created_at', '>=', $monthStart)
                    ->where('created_at', '<', $nowEndExclusive)
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => $daySummary['paid_total'],
                'day_settled_income' => $daySummary['settled_total'],
                'day_unsettled_income' => $daySummary['unsettled_total'],
                'last_month_income' => $lastMonthSummary['paid_total'],
                'last_month_settled_income' => $lastMonthSummary['settled_total'],
                'last_month_unsettled_income' => $lastMonthSummary['unsettled_total'],
                'commission_month_payout' => (int) CommissionLog::where('created_at', '>=', $monthStart)
                    ->where('created_at', '<', $nowEndExclusive)
                    ->sum('get_amount'),
                'commission_last_month_payout' => (int) CommissionLog::where('created_at', '>=', $lastMonthStart)
                    ->where('created_at', '<', $monthStart)
                    ->sum('get_amount'),
                // 新增统计数据
                'online_nodes' => $realtimeStats['onlineNodes'],
                'online_devices' => $realtimeStats['onlineDevices'],
                'online_users' => $realtimeStats['onlineUsers'],
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        return [
            'code' => 0,
            'message' => 'success',
            'data' => $this->orderRevenueStatService->dailyOverview(
                $validated['start_date'] ?? null,
                $validated['end_date'] ?? null,
                $validated['type'] ?? null
            ),
        ];
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $pageSize = $request->input('pageSize', 10);
        $records = StatUser::orderBy('record_at', 'desc')
            ->where('user_id', $request->input('user_id'))
            ->paginate($pageSize);

        $data = $records->items();
        return [
            'data' => $data,
            'total' => $records->total(),
        ];
    }

    public function getStatRecord(Request $request)
    {
        return [
            'data' => $this->service->getStatRecord($request->input('type'))
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');
        $now = CarbonImmutable::now($timezone);
        $nowTimestamp = $now->timestamp;
        $nowEndExclusive = $nowTimestamp + 1;

        $currentMonth = $now->startOfMonth();
        $currentMonthStart = $currentMonth->timestamp;
        $lastMonthStart = $currentMonth->subMonthNoOverflow()->timestamp;
        $twoMonthsAgoStart = $currentMonth->subMonthsNoOverflow(2)->timestamp;

        // Today's start timestamp
        $today = $now->startOfDay();
        $todayStart = $today->timestamp;
        $yesterdayStart = $today->subDay()->timestamp;

        $realtimeStats = $this->realtimeStatsService->snapshot();

        // 获取今日流量统计
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', $nowEndExclusive)
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthTraffic = StatServer::where('record_at', '>=', $currentMonthStart)
            ->where('record_at', '<', $nowEndExclusive)
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        $todaySummary = $this->orderRevenueStatService->periodSummary($todayStart, $nowEndExclusive);
        $yesterdaySummary = $this->orderRevenueStatService->periodSummary($yesterdayStart, $todayStart);
        $currentMonthSummary = $this->orderRevenueStatService->periodSummary($currentMonthStart, $nowEndExclusive);
        $lastMonthSummary = $this->orderRevenueStatService->periodSummary($lastMonthStart, $currentMonthStart);
        $twoMonthsAgoSummary = $this->orderRevenueStatService->periodSummary($twoMonthsAgoStart, $lastMonthStart);

        $todayIncome = $todaySummary['paid_total'];
        $yesterdayIncome = $yesterdaySummary['paid_total'];
        $currentMonthIncome = $currentMonthSummary['paid_total'];
        $lastMonthIncome = $lastMonthSummary['paid_total'];
        $twoMonthsAgoIncome = $twoMonthsAgoSummary['paid_total'];

        // Last month commission payout
        $lastMonthCommissionPayout = (int) CommissionLog::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('get_amount');

        // Current month commission payout
        $currentMonthCommissionPayout = (int) CommissionLog::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', $nowEndExclusive)
            ->sum('get_amount');

        // Current month new users
        $currentMonthNewUsers = User::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', $nowEndExclusive)
            ->count();

        // Total users
        $totalUsers = User::count();

        // Active users (users with valid subscription)
        $activeUsers = User::where(function ($query) use ($nowTimestamp) {
            $query->where('expired_at', '>=', $nowTimestamp)
                ->orWhere('expired_at', NULL);
        })->count();

        // Previous month commission for growth calculation
        $twoMonthsAgoCommission = (int) CommissionLog::where('created_at', '>=', $twoMonthsAgoStart)
            ->where('created_at', '<', $lastMonthStart)
            ->sum('get_amount');

        // Previous month users for growth calculation
        $lastMonthNewUsers = User::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->count();

        // Calculate growth rates
        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        // 获取待处理工单和佣金数据
        $ticketPendingTotal = Ticket::where('status', 0)->count();
        $commissionPendingTotal = Order::where('commission_status', 0)
            ->where('invite_user_id', '!=', NULL)
            ->whereIn('status', [Order::STATUS_COMPLETED])
            ->where('commission_balance', '>', 0)
            ->count();

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'todaySettledIncome' => $todaySummary['settled_total'],
                'todayUnsettledIncome' => $todaySummary['unsettled_total'],
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'currentMonthSettledIncome' => $currentMonthSummary['settled_total'],
                'currentMonthUnsettledIncome' => $currentMonthSummary['unsettled_total'],
                'lastMonthIncome' => $lastMonthIncome,
                'lastMonthSettledIncome' => $lastMonthSummary['settled_total'],
                'lastMonthUnsettledIncome' => $lastMonthSummary['unsettled_total'],
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $commissionPendingTotal,

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $realtimeStats['onlineUsers'],
                'onlineDevices' => $realtimeStats['onlineDevices'],

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $realtimeStats['onlineNodes'],

                // 流量统计
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    public function getRealtimeStats()
    {
        return $this->success($this->realtimeStatsService->snapshot());
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // Get node traffic data
            $currentData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'desc')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'desc')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}
