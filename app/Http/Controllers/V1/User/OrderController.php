<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\OrderSave;
use App\Http\Requests\User\DistributorOrderRenew;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\DistributorOrder;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Services\DistributorOrderService;
use App\Services\DistributorOrderExportService;
use App\Services\DistributorOrderSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function fetch(Request $request, DistributorOrderSearchService $searchService)
    {
        $request->validate([
            'status' => 'nullable|integer|in:0,1,2,3',
            'settlement_status' => 'nullable|integer|in:0,1',
            'search' => 'nullable|string|max:512',
        ]);
        $orders = Order::with([
            'plan',
            'distributorSubscription:id,order_id,subscriber_user_id,customer_name,remark,delivery_status,settlement_status,config_issued_at,connected_at,connected_node_id,connected_node_name,claimed_at,closed_at,hwid_enabled,hwid_limit',
            'distributorSubscription.order:id,trade_no,plan_id,period',
            'distributorSubscription.subscriber:id,plan_id,token,transfer_enable,u,d,expired_at,speed_limit,device_limit,banned',
            'distributorSubscription.hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
        ])
            ->where('user_id', $request->user()->id)
            ->when($request->user()->is_distributor, function ($query) use ($request, $searchService) {
                $searchService->applyToOrderQuery($query, $request->input('search'));
            })
            ->when($request->user()->is_distributor, function ($query) use ($request) {
                $query->whereNotNull('distributor_order_id');
                if ($request->input('settlement_status') !== null) {
                    $request->integer('settlement_status') === DistributorOrder::SETTLEMENT_SETTLED
                        ? $query->whereNotNull('paid_at')
                        : $query->whereNull('paid_at');
                }
            })
            ->when($request->input('status') !== null, function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return $this->success(OrderResource::collection($orders));
    }

    public function export(Request $request, DistributorOrderExportService $exportService)
    {
        abort_unless((bool) $request->user()->is_distributor, 403);

        $validated = $request->validate([
            'settlement_status' => 'nullable|integer|in:0,1',
            'search' => 'nullable|string|max:512',
        ]);

        return $exportService->downloadForDistributor(
            (int) $request->user()->id,
            isset($validated['settlement_status']) ? (int) $validated['settlement_status'] : null,
            $validated['search'] ?? null
        );
    }

    public function detail(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
        ]);
        $order = Order::with([
            'payment',
            'plan',
            'distributorSubscription:id,order_id,subscriber_user_id,customer_name,remark,delivery_status,settlement_status,config_issued_at,connected_at,connected_node_id,connected_node_name,claimed_at,closed_at,hwid_enabled,hwid_limit',
            'distributorSubscription.order:id,trade_no,plan_id,period',
            'distributorSubscription.subscriber:id,plan_id,token,transfer_enable,u,d,expired_at,speed_limit,device_limit,banned',
            'distributorSubscription.hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
        ])
            ->where('user_id', $request->user()->id)
            ->where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        $order['try_out_plan_id'] = (int) admin_setting('try_out_plan_id');
        if (!$order->plan) {
            return $this->fail([400, __('Subscription plan does not exist')]);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        return $this->success(OrderResource::make($order));
    }

    public function save(OrderSave $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:App\Models\Plan,id',
            'period' => 'required|string'
        ]);

        $user = User::findOrFail($request->user()->id);
        $userService = app(UserService::class);

        $plan = Plan::findOrFail($request->input('plan_id'));
        if ($user->is_distributor) {
            if ($request->filled('coupon_code')) {
                throw new ApiException('分销订单不支持优惠券或折扣');
            }

            $order = app(DistributorOrderService::class)->create(
                $user,
                $plan,
                $request->input('period'),
                $request->input('customer_name')
            );

            return $this->success($order->trade_no);
        }

        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        $planService = new PlanService($plan);

        $planService->validatePurchase($user, $request->input('period'));

        $order = OrderService::createFromRequest(
            $user,
            $plan,
            $request->input('period'),
            $request->input('coupon_code')
        );

        return $this->success($order->trade_no);
    }

    public function renew(DistributorOrderRenew $request)
    {
        abort_unless((bool) $request->user()->is_distributor, 403);

        $order = app(DistributorOrderService::class)->renew(
            User::findOrFail($request->user()->id),
            (string) $request->input('trade_no'),
            (string) $request->input('period'),
            (string) $request->input('idempotency_key')
        );

        return $this->success([
            'trade_no' => $order->trade_no,
            'subscription_trade_no' => $order->distributorSubscription?->order?->trade_no,
            'period' => PlanService::getLegacyPeriod((string) $order->period),
            'total_amount' => (int) $order->total_amount,
            'expired_at_before' => $order->entitlement_expired_at_before,
            'expired_at_after' => $order->entitlement_expired_at_after,
            'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
        ]);
    }

    protected function applyCoupon(Order $order, string $couponCode): void
    {
        $couponService = new CouponService($couponCode);
        if (!$couponService->use($order)) {
            throw new ApiException(__('Coupon failed'));
        }
        $order->coupon_id = $couponService->getId();
    }

    protected function handleUserBalance(Order $order, User $user, UserService $userService): void
    {
        $remainingBalance = $user->balance - $order->total_amount;

        if ($remainingBalance > 0) {
            if (!$userService->addBalance($order->user_id, -$order->total_amount)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $order->total_amount;
            $order->total_amount = 0;
        } else {
            if (!$userService->addBalance($order->user_id, -$user->balance)) {
                throw new ApiException(__('Insufficient balance'));
            }
            $order->balance_amount = $user->balance;
            $order->total_amount = $order->total_amount - $user->balance;
        }
    }

    public function checkout(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $completedDistributorOrder = Order::query()
            ->where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNotNull('distributor_order_id')
            ->first();
        if ($completedDistributorOrder) {
            return response([
                'type' => -1,
                'data' => true,
            ]);
        }

        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->where('status', 0)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist or has been paid')]);
        }
        // free process
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no))
                return $this->fail([400, '支付失败']);
            return response([
                'type' => -1,
                'data' => true
            ]);
        }
        $payment = Payment::find($method);
        if (!$payment || !$payment->enable) {
            return $this->fail([400, __('Payment method is not available')]);
        }
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = (int) round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save())
            return $this->fail([400, __('Request failed, please try again later')]);
        $result = $paymentService->pay([
            'trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);
        return response([
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }

    public function check(Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $order = Order::where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        return $this->success($order->status);
    }

    public function getPaymentMethod(Request $request)
    {
        if ($request->user()->is_distributor) {
            return $this->success([]);
        }

        $methods = Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent'
        ])
            ->where('enable', 1)
            ->orderBy('sort', 'asc')
            ->get();

        return $this->success($methods);
    }

    public function cancel(Request $request)
    {
        if (empty($request->input('trade_no'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$order) {
            return $this->fail([400, __('Order does not exist')]);
        }
        if ($order->status !== 0) {
            return $this->fail([400, __('You can only cancel pending orders')]);
        }
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, __('Cancel failed')]);
        }
        return $this->success(true);
    }
}
