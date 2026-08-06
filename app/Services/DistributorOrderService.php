<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class DistributorOrderService
{
    public function create(User $distributor, Plan $plan, string $period, string $customerName): Order
    {
        $customerName = trim($customerName);
        if ($customerName === '') {
            throw new ApiException('为了售后方便，请输入备注清楚用户', 422);
        }
        if (mb_strlen($customerName) > 64) {
            throw new ApiException('用户名称不能超过64个字符', 422);
        }

        if (!$distributor->is_distributor) {
            throw new ApiException('当前账号不是可用的分销商账号', 403);
        }

        (new PlanService($plan))->validateDistributorPurchase($period);
        HookManager::call('order.create.before', [$distributor, $plan, $period, null]);

        $order = DB::transaction(function () use ($distributor, $plan, $period, $customerName) {
            $lockedDistributor = User::lockForUpdate()->find($distributor->id);
            if (!$lockedDistributor?->is_distributor || $lockedDistributor->banned) {
                throw new ApiException('当前分销商账号不可用', 403);
            }

            $lockedPlan = Plan::findOrFail($plan->id);
            (new PlanService($lockedPlan))->validateDistributorPurchase($period);

            $periodKey = PlanService::getPeriodKey($period);
            $order = Order::create([
                'user_id' => $lockedDistributor->id,
                'plan_id' => $lockedPlan->id,
                'period' => $periodKey,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) ($lockedPlan->prices[$periodKey] * 100),
                'type' => Order::TYPE_NEW_PURCHASE,
                'status' => Order::STATUS_COMPLETED,
                'callback_no' => 'distributor_auto',
                'commission_status' => 0,
                'commission_balance' => 0,
                'balance_amount' => 0,
                'discount_amount' => 0,
                // 未结算前不视为实际收款；结算时再写 paid_at。
                'paid_at' => null,
            ]);

            $subscriber = app(UserService::class)->createUser([
                'email' => sprintf('dist-%s@internal.invalid', $order->trade_no),
                'password' => Str::random(64),
                'plan_id' => $lockedPlan->id,
                'expired_at' => $this->calculateExpiredAt($periodKey),
            ]);
            $subscriber->device_limit = $lockedPlan->device_limit;
            $subscriber->is_admin = 0;
            $subscriber->is_staff = false;
            $subscriber->is_distributor = false;
            $subscriber->invite_user_id = null;
            $subscriber->remarks = 'Internal subscription for distributor order ' . $order->trade_no;
            $subscriber->saveOrFail();

            app(TrafficResetService::class)->setInitialResetTime($subscriber);

            $claimToken = Str::random(64);
            DistributorOrder::create([
                'order_id' => $order->id,
                'distributor_user_id' => $lockedDistributor->id,
                'customer_name' => $customerName,
                'subscriber_user_id' => $subscriber->id,
                'claim_token' => $claimToken,
                'claim_token_hash' => hash('sha256', $claimToken),
                'delivery_status' => DistributorOrder::DELIVERY_PENDING,
                'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
                'hwid_enabled' => true,
                'hwid_limit' => 1,
            ]);

            return $order->load(['plan', 'distributorOrder']);
        });

        HookManager::call('order.create.after', $order);
        HookManager::call('order.after_create', $order);

        return $order;
    }

    public function deliveryData(DistributorOrder $delivery, bool $includeClaimUrl = true): array
    {
        $data = [
            'trade_no' => $delivery->order->trade_no,
            'delivery_status' => $delivery->delivery_status,
            'settlement_status' => $delivery->settlement_status,
            'config_issued_at' => $delivery->config_issued_at,
            'connected_at' => $delivery->connected_at,
            'connected_node_id' => $delivery->connected_node_id,
            'connected_node_name' => $delivery->connected_node_name,
            'claimed_at' => $delivery->claimed_at,
            'closed_at' => $delivery->closed_at,
            'hwid_enabled' => (bool) $delivery->hwid_enabled,
            'hwid_limit' => (int) $delivery->hwid_limit,
            'can_open' => $delivery->delivery_status === DistributorOrder::DELIVERY_PENDING,
        ];

        if (
            $includeClaimUrl
            && $delivery->delivery_status === DistributorOrder::DELIVERY_PENDING
        ) {
            $subscribeUrl = Helper::getSubscribeUrl($delivery->subscriber->token);
            $data['qr_code'] = $this->makeQrDataUri($subscribeUrl);
        }

        return $data;
    }

    public function subscriptionQrData(DistributorOrder $delivery): array
    {
        $delivery->loadMissing([
            'order:id,trade_no',
            'subscriber:id,token',
            'hwidDevices:id,distributor_order_id,hwid,last_seen_at',
        ]);

        if (!$delivery->subscriber?->token) {
            throw new ApiException('订阅尚未生成', 409);
        }

        return [
            'trade_no' => $delivery->order->trade_no,
            'customer_name' => trim((string) $delivery->customer_name),
            'qr_code' => $this->makeQrDataUri(Helper::getSubscribeUrl($delivery->subscriber->token)),
            'hwid_enabled' => (bool) $delivery->hwid_enabled,
            'hwid_devices' => $delivery->hwidDevices
                ->sortByDesc('last_seen_at')
                ->pluck('hwid')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function calculateExpiredAt(string $period): ?int
    {
        if ($period === Plan::PERIOD_ONETIME) {
            return null;
        }

        $months = OrderService::STR_TO_TIME[$period] ?? null;
        if (!$months) {
            throw new ApiException('无效的套餐周期');
        }

        return Carbon::now()->addMonths($months)->timestamp;
    }

    private function makeQrDataUri(string $content): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(320, 2),
            new SvgImageBackEnd()
        );
        $svg = (new Writer($renderer))->writeString($content);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
