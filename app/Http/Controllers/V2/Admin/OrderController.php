<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Http\Requests\Admin\DistributorOrderEntitlementUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\DistributorOrder;
use App\Models\DistributorHwidDevice;
use App\Services\OrderService;
use App\Services\DistributorOrderEntitlementService;
use App\Services\DistributorOrderService;
use App\Services\DistributorOrderExportService;
use App\Services\DistributorOrderSearchService;
use App\Services\DistributorHwidService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{

    public function export(Request $request, DistributorOrderExportService $exportService)
    {
        $validated = $request->validate([
            'distributor_user_id' => 'nullable|integer',
            'settlement_status' => 'nullable|integer|in:0,1',
            'search' => 'nullable|string|max:512',
        ]);

        return $exportService->downloadForAdmin(
            isset($validated['distributor_user_id']) ? (int) $validated['distributor_user_id'] : null,
            isset($validated['settlement_status']) ? (int) $validated['settlement_status'] : null,
            $validated['search'] ?? null
        );
    }

    public function detail(
        Request $request,
        DistributorOrderEntitlementService $entitlementService,
        DistributorHwidService $hwidService
    )
    {
        $order = Order::with([
            'user',
            'plan',
            'commission_log',
            'invite_user',
            'distributorSubscription.order:id,trade_no,plan_id,period',
            'distributorSubscription.subscriber',
            'distributorSubscription.distributor:id,email,distributor_name',
            'distributorOrder.subscriber',
            'distributorOrder.distributor:id,email,distributor_name',
        ])->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);

        $distributorOrder = $order->distributorSubscription ?: $order->distributorOrder;
        $subscribeUrl = null;
        if ($order->status === Order::STATUS_COMPLETED) {
            $subscriber = $distributorOrder?->subscriber ?: $order->user;
            $subscribeUrl = $distributorOrder
                ? app(DistributorOrderService::class)->subscriptionUrl($distributorOrder)
                : Helper::getSubscribeUrl($subscriber->token);
        }

        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $data = $order->toArray();
        unset($data['distributor_order']);
        unset($data['distributor_subscription']);
        $data['period'] = PlanService::getLegacyPeriod((string) $order->period);
        $data['is_distributor_order'] = $distributorOrder !== null;
        $data['order_type_label'] = Order::$typeMap[(int) $order->type] ?? (string) $order->type;
        $data['subscription_trade_no'] = $distributorOrder?->order?->trade_no;
        $data['distributor_email'] = $distributorOrder?->distributor?->email;
        $data['distributor_name'] = $distributorOrder?->distributor?->distributor_name
            ?: $distributorOrder?->distributor?->email;
        $data['customer_name'] = $distributorOrder?->customer_name;
        $data['remark'] = $distributorOrder?->remark;
        $data['delivery_status'] = $distributorOrder?->delivery_status;
        $data['config_issued_at'] = $distributorOrder?->config_issued_at;
        $data['connected_at'] = $distributorOrder?->connected_at;
        $data['connected_node_id'] = $distributorOrder?->connected_node_id;
        $data['connected_node_name'] = $distributorOrder?->connected_node_name;
        $data['settlement_status'] = $distributorOrder
            ? ($order->paid_at === null
                ? DistributorOrder::SETTLEMENT_UNSETTLED
                : DistributorOrder::SETTLEMENT_SETTLED)
            : null;
        $data['settled_at'] = $distributorOrder ? $order->paid_at : null;
        $data['subscribe_url'] = $subscribeUrl;
        $data['subscription_entitlement'] = $distributorOrder
            ? $entitlementService->data($distributorOrder)
            : null;
        $data['hwid'] = $distributorOrder
            ? $hwidService->settingsForOrder($order->id)
            : null;

        return $this->success($data);
    }

    public function updateRemark(Request $request)
    {
        if ($request->has('remark') && is_string($request->input('remark'))) {
            $request->merge(['remark' => trim($request->input('remark'))]);
        }
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'remark' => 'present|nullable|string|max:500',
        ]);

        $order = Order::query()
            ->with(['distributorSubscription', 'distributorOrder'])
            ->find((int) $validated['order_id']);
        $distributorOrder = $order?->distributorSubscription ?: $order?->distributorOrder;
        if (!$distributorOrder) {
            return $this->fail([404, '分销订单不存在']);
        }

        $remark = (string) ($validated['remark'] ?? '');
        $distributorOrder->remark = $remark === '' ? null : $remark;
        $distributorOrder->save();

        return $this->success([
            'order_id' => (int) $validated['order_id'],
            'subscription_trade_no' => $distributorOrder->order->trade_no,
            'remark' => $distributorOrder->remark,
        ]);
    }

    public function updateEntitlement(
        DistributorOrderEntitlementUpdate $request,
        DistributorOrderEntitlementService $entitlementService
    ) {
        return $this->success($entitlementService->updateForOrder(
            (int) $request->input('order_id'),
            $request->safe()->only([
                'transfer_enable',
                'expired_at',
                'speed_limit',
                'device_limit',
            ])
        ));
    }

    public function fetch(Request $request, DistributorOrderSearchService $searchService)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);
        $orderModel = Order::with([
            'plan:id,name',
            'distributorSubscription' => function ($query) {
                $query->select([
                    'id', 'order_id', 'distributor_user_id', 'subscriber_user_id', 'customer_name', 'remark',
                    'delivery_status', 'settlement_status', 'config_issued_at', 'connected_at', 'connected_node_id',
                    'connected_node_name', 'settled_at',
                ])->with([
                    'hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
                ]);
            },
            'distributorSubscription.order:id,trade_no',
            'distributorSubscription.distributor:id,email,distributor_name',
            'distributorSubscription.subscriber:id,u,d',
        ]);

        $request->validate([
            'distributor_user_id' => 'nullable|integer',
            'settlement_status' => 'nullable|integer|in:0,1',
            'distributor_only' => 'nullable|boolean',
            'search' => 'nullable|string|max:512',
        ]);

        $searchService->applyToOrderQuery(
            $orderModel,
            $request->input('search'),
            true
        );

        if ($request->boolean('distributor_only')) {
            $orderModel->whereNotNull('distributor_order_id');
        }

        if ($request->filled('distributor_user_id')) {
            $orderModel->whereHas('distributorSubscription', function ($query) use ($request) {
                $query->where('distributor_user_id', (int) $request->input('distributor_user_id'));
            });
        }
        if ($request->input('settlement_status') !== null) {
            $orderModel->whereNotNull('distributor_order_id');
            (int) $request->input('settlement_status') === DistributorOrder::SETTLEMENT_SETTLED
                ? $orderModel->whereNotNull('paid_at')
                : $orderModel->whereNull('paid_at');
        }

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
        $paginatedResults = $orderModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        $paginatedResults->getCollection()->transform(function ($order) {
            $orderArray = $order->toArray();
            $distributorOrder = $order->distributorSubscription;
            unset($orderArray['distributor_order']);
            unset($orderArray['distributor_subscription']);
            $orderArray['period'] = PlanService::getLegacyPeriod((string) $order->period);
            $orderArray['is_distributor_order'] = $distributorOrder !== null;
            $orderArray['order_type_label'] = Order::$typeMap[(int) $order->type] ?? (string) $order->type;
            $orderArray['subscription_trade_no'] = $distributorOrder?->order?->trade_no;
            $orderArray['distributor_email'] = $distributorOrder?->distributor?->email;
            $orderArray['distributor_name'] = $distributorOrder?->distributor?->distributor_name
                ?: $distributorOrder?->distributor?->email;
            $orderArray['customer_name'] = $distributorOrder?->customer_name;
            $orderArray['bound_device_count'] = $distributorOrder
                ? $distributorOrder->hwidDevices->count()
                : null;
            $orderArray['bound_devices'] = $distributorOrder
                ? $distributorOrder->hwidDevices
                    ->sortByDesc('last_seen_at')
                    ->map(static fn(DistributorHwidDevice $device): string => $device->displayLabel())
                    ->filter()
                    ->values()
                    ->all()
                : [];
            $orderArray['used_traffic'] = $distributorOrder?->subscriber
                ? max(0, (int) $distributorOrder->subscriber->u + (int) $distributorOrder->subscriber->d)
                : null;
            $orderArray['remark'] = $distributorOrder?->remark;
            $orderArray['delivery_status'] = $distributorOrder?->delivery_status;
            $orderArray['config_issued_at'] = $distributorOrder?->config_issued_at;
            $orderArray['connected_at'] = $distributorOrder?->connected_at;
            $orderArray['connected_node_id'] = $distributorOrder?->connected_node_id;
            $orderArray['connected_node_name'] = $distributorOrder?->connected_node_name;
            $orderArray['settlement_status'] = $distributorOrder
                ? ($order->paid_at === null
                    ? DistributorOrder::SETTLEMENT_UNSETTLED
                    : DistributorOrder::SETTLEMENT_SETTLED)
                : null;
            $orderArray['settled_at'] = $distributorOrder ? $order->paid_at : null;
            return $orderArray;
        });

        return $this->paginate($paginatedResults);
    }

    public function updateHwid(Request $request, DistributorHwidService $hwidService)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'enabled' => 'required|boolean',
            'limit' => 'required|integer|min:1|max:100',
        ]);

        return $this->success($hwidService->updateSettings(
            (int) $validated['order_id'],
            (bool) $validated['enabled'],
            (int) $validated['limit']
        ));
    }

    public function hwidDevices(Request $request, DistributorHwidService $hwidService)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'search' => 'nullable|string|max:64',
        ]);

        return $this->success($hwidService->devicesForOrder(
            (int) $validated['order_id'],
            $validated['search'] ?? null
        ));
    }

    public function deleteHwidDevice(Request $request, DistributorHwidService $hwidService)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'device_id' => 'required|integer',
        ]);

        if (!$hwidService->deleteDevice((int) $validated['order_id'], (int) $validated['device_id'])) {
            return $this->fail([404, 'HWID 设备不存在']);
        }

        return $this->success(true);
    }

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // Convert numeric strings to appropriate type
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // Apply operator
        $query->where($field, match (strtolower($operator)) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            'null' => static fn($q) => $q->whereNull($field),
            'notnull' => static fn($q) => $q->whereNotNull($field),
            default => 'like'
        }, match (strtolower($operator)) {
            'like', 'notlike' => "%{$filterValue}%",
            'null', 'notnull' => null,
            default => $filterValue
        });
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'desc' : 'asc';
            $builder->orderBy($field, $direction);
        });
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        try {
            $order->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }

        return $this->success(true);
    }

    public function assign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::byEmail($request->input('email'))->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, '该用户还有待支付的订单，无法分配']);
        }

        try {
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $period = $request->input('period');
            $order->period = PlanService::getPeriodKey((string) $period);
            $order->trade_no = Helper::guid();
            $order->total_amount = $request->input('total_amount');

            if (PlanService::getPeriodKey((string) $order->period) === Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollBack();
                return $this->fail([500, '订单创建失败']);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success($order->trade_no);
    }

    public function settlementPreview(Request $request)
    {
        $distributor = $this->resolveDistributor($request);
        if (!$distributor) {
            return $this->fail([422, '请选择有效的分销商']);
        }

        return $this->success($this->getSettlementSummary($distributor->id));
    }

    public function settle(Request $request)
    {
        $distributor = $this->resolveDistributor($request);
        if (!$distributor) {
            return $this->fail([422, '请选择有效的分销商']);
        }

        $result = DB::transaction(function () use ($request, $distributor) {
            $orders = Order::query()
                ->where('user_id', $distributor->id)
                ->whereNotNull('distributor_order_id')
                ->where('status', Order::STATUS_COMPLETED)
                ->whereNull('paid_at')
                ->whereHas('distributorSubscription', fn($query) => $query
                    ->where('distributor_user_id', $distributor->id))
                ->lockForUpdate()
                ->get(['id', 'distributor_order_id', 'total_amount']);

            $orderIds = $orders->pluck('id');
            $amount = (int) $orders->sum('total_amount');
            $settledAt = time();

            if ($orders->isNotEmpty()) {
                Order::whereIn('id', $orderIds)->update([
                    'paid_at' => $settledAt,
                    'distributor_settled_by' => $request->user()->id,
                    'updated_at' => $settledAt,
                ]);

                // Keep the legacy fields synchronized for original purchases
                // during the compatibility window. Renewal settlement lives on
                // its own financial order and never mutates the subscription row.
                DistributorOrder::whereIn('order_id', $orderIds)->update([
                    'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
                    'settled_at' => $settledAt,
                    'settled_by' => $request->user()->id,
                    'updated_at' => $settledAt,
                ]);
            }

            return [
                'count' => $orders->count(),
                'total_amount' => $amount,
                'total_amount_yuan' => $amount / 100,
                'settled_at' => $orders->isEmpty() ? null : $settledAt,
            ];
        });

        return $this->success($result);
    }

    private function resolveDistributor(Request $request): ?User
    {
        $request->validate([
            'distributor_user_id' => 'required|integer',
        ]);

        return User::notInternalSubscriber()
            ->where('is_distributor', true)
            ->find((int) $request->input('distributor_user_id'));
    }

    private function getSettlementSummary(int $distributorUserId): array
    {
        $summary = Order::query()
            ->join('v2_distributor_order', 'v2_distributor_order.id', '=', 'v2_order.distributor_order_id')
            ->where('v2_order.user_id', $distributorUserId)
            ->where('v2_distributor_order.distributor_user_id', $distributorUserId)
            ->where('v2_order.status', Order::STATUS_COMPLETED)
            ->whereNull('v2_order.paid_at')
            ->selectRaw('COUNT(v2_order.id) as order_count, COALESCE(SUM(v2_order.total_amount), 0) as total_amount')
            ->first();

        $amount = (int) ($summary->total_amount ?? 0);
        return [
            'count' => (int) ($summary->order_count ?? 0),
            'total_amount' => $amount,
            'total_amount_yuan' => $amount / 100,
        ];
    }
}
