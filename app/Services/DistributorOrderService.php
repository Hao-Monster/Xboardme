<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Jobs\NodeUserSyncJob;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Support\SqliteImmediateTransaction;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class DistributorOrderService
{
    public const MAX_BATCH_QUANTITY = 500;

    public static function batchIdempotencyPrefix(?string $idempotencyKey): ?string
    {
        $normalized = trim((string) $idempotencyKey);
        if ($normalized === '') {
            return null;
        }

        return 'b-' . substr(hash('sha256', "dist-batch:{$normalized}"), 0, 26) . '-';
    }

    public function create(
        User $distributor,
        Plan $plan,
        string $period,
        ?string $customerName = null,
        ?string $idempotencyKey = null
    ): Order {
        $orders = $this->createBatch($distributor, $plan, $period, 1, $customerName, $idempotencyKey);

        return $orders[0];
    }

    /**
     * @return array<int, Order>
     */
    public function createBatch(
        User $distributor,
        Plan $plan,
        string $period,
        int $quantity = 1,
        ?string $customerName = null,
        ?string $idempotencyKey = null
    ): array {
        if ($quantity < 1 || $quantity > self::MAX_BATCH_QUANTITY) {
            throw new ApiException('单次下单数量需在1至' . self::MAX_BATCH_QUANTITY . '个之间', 422);
        }

        $customerName = trim((string) $customerName);
        if (mb_strlen($customerName) > 64) {
            throw new ApiException('用户名称不能超过64个字符', 422);
        }

        $idempotencyKey = trim((string) $idempotencyKey);
        if (
            $idempotencyKey !== ''
            && (strlen($idempotencyKey) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $idempotencyKey))
        ) {
            throw new ApiException('幂等标识格式不正确', 422);
        }

        if (!$distributor->is_distributor) {
            throw new ApiException('当前账号不是可用的分销商账号', 403);
        }

        $periodKey = PlanService::getPeriodKey($period);
        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            throw new ApiException('分销商只能为终端客户购买新订阅');
        }

        $batchPrefix = self::batchIdempotencyPrefix($idempotencyKey);
        $created = false;
        /** @var array<int, int> $createdSubscriberIds */
        $createdSubscriberIds = [];

        /** @var array<int, Order> $orders */
        $orders = SqliteImmediateTransaction::write(function () use (
            $distributor,
            $plan,
            $period,
            $periodKey,
            $quantity,
            $customerName,
            $batchPrefix,
            &$created,
            &$createdSubscriberIds
        ): array {
            $lockedDistributor = User::lockForUpdate()->find($distributor->id);
            if (!$lockedDistributor?->is_distributor || $lockedDistributor->banned) {
                throw new ApiException('当前分销商账号不可用', 403);
            }

            if ($batchPrefix !== null) {
                $existing = Order::query()
                    ->where('user_id', $lockedDistributor->id)
                    ->whereBetween('distributor_idempotency_key', [$batchPrefix . '000000', $batchPrefix . '999999'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($existing->isNotEmpty()) {
                    if (
                        $existing->count() !== $quantity
                        || (int) $existing[0]->plan_id !== (int) $plan->id
                        || (string) $existing[0]->period !== $periodKey
                    ) {
                        throw new ApiException('下单请求标识已用于其他下单操作', 409);
                    }

                    return $existing->load(['plan', 'distributorOrder', 'distributorSubscription'])->all();
                }
            }

            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            (new PlanService($lockedPlan))->validateDistributorPurchase($lockedDistributor, $period, $quantity, true);
            $subscriptionNameService = app(DistributorSubscriptionNameService::class);
            $merchantName = $subscriptionNameService->validateMerchantName($lockedDistributor);
            HookManager::call('order.create.before', [$lockedDistributor, $lockedPlan, $period, null]);

            $batchPasswordHash = Hash::make(Str::random(64));
            $unitAmount = (int) ($lockedPlan->prices[$periodKey] * 100);
            $expiredAt = $this->calculateExpiredAt($periodKey);
            $userService = app(UserService::class);
            $trafficResetService = app(TrafficResetService::class);
            $remindExpire = admin_setting('default_remind_expire', 1);
            $remindTraffic = admin_setting('default_remind_traffic', 1);
            $resetProbe = new User();
            $resetProbe->plan_id = $lockedPlan->id;
            $resetProbe->expired_at = $expiredAt;
            $resetProbe->setRelation('plan', $lockedPlan);
            $initialNextResetAt = $trafficResetService->calculateNextResetTime($resetProbe)?->timestamp;
            $reservedTradeNos = [];
            $createdOrders = [];
            $subscriberIds = [];

            for ($index = 0; $index < $quantity; $index++) {
                $order = Order::create([
                    'user_id' => $lockedDistributor->id,
                    'plan_id' => $lockedPlan->id,
                    'period' => $periodKey,
                    'trade_no' => $this->generateUniqueTradeNo($reservedTradeNos),
                    'total_amount' => $unitAmount,
                    'type' => Order::TYPE_NEW_PURCHASE,
                    'status' => Order::STATUS_COMPLETED,
                    'callback_no' => 'distributor_auto',
                    'commission_status' => 0,
                    'commission_balance' => 0,
                    'balance_amount' => 0,
                    'discount_amount' => 0,
                    // 未结算前不视为实际收款；结算时再写 paid_at。
                    'paid_at' => null,
                    'distributor_idempotency_key' => $batchPrefix !== null
                        ? $batchPrefix . sprintf('%06d', $index)
                        : null,
                ]);

                $subscriber = $userService->createUser([
                    'email' => sprintf('dist-%s@internal.invalid', $order->trade_no),
                    'password_hash' => $batchPasswordHash,
                    'remind_expire' => $remindExpire,
                    'remind_traffic' => $remindTraffic,
                    'plan' => $lockedPlan,
                    'plan_id' => $lockedPlan->id,
                    'expired_at' => $expiredAt,
                ]);
                $subscriber->setRelation('plan', $lockedPlan);
                $subscriber->device_limit = $lockedPlan->device_limit;
                $subscriber->next_reset_at = $initialNextResetAt;
                $subscriber->is_admin = 0;
                $subscriber->is_staff = false;
                $subscriber->is_distributor = false;
                $subscriber->invite_user_id = null;
                $subscriber->remarks = 'Internal subscription for distributor order ' . $order->trade_no;
                User::withoutEvents(static function () use ($subscriber): void {
                    $subscriber->saveOrFail();
                });
                $subscriberIds[] = (int) $subscriber->id;

                $claimToken = Str::random(64);
                $delivery = $subscriptionNameService->createWithGeneratedName([
                    'order_id' => $order->id,
                    'distributor_user_id' => $lockedDistributor->id,
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'subscriber_user_id' => $subscriber->id,
                    'claim_token' => $claimToken,
                    'claim_token_hash' => hash('sha256', $claimToken),
                    'delivery_status' => DistributorOrder::DELIVERY_PENDING,
                    'settlement_status' => DistributorOrder::SETTLEMENT_UNSETTLED,
                    'hwid_enabled' => true,
                    'hwid_limit' => $lockedPlan->distributor_hwid_limit,
                ], $merchantName, (int) $order->created_at);

                $order->fill([
                    'distributor_order_id' => $delivery->id,
                    'entitlement_expired_at_before' => null,
                    'entitlement_expired_at_after' => $subscriber->expired_at,
                ])->saveOrFail();

                $createdOrders[] = $order;
            }

            $created = true;
            $createdSubscriberIds = $subscriberIds;

            return (new EloquentCollection($createdOrders))
                ->load(['plan', 'distributorOrder', 'distributorSubscription'])
                ->all();
        });

        if ($created) {
            foreach ($createdSubscriberIds as $subscriberId) {
                NodeUserSyncJob::dispatch($subscriberId, 'created');
            }
            foreach ($orders as $order) {
                HookManager::call('order.create.after', $order);
                HookManager::call('order.after_create', $order);
            }
        }

        return $orders;
    }

    public function renew(
        User $distributor,
        string $tradeNo,
        string $period,
        string $idempotencyKey
    ): Order {
        if (!$distributor->is_distributor) {
            throw new ApiException('当前账号不是可用的分销商账号', 403);
        }

        $created = false;
        $order = SqliteImmediateTransaction::write(function () use (
            $distributor,
            $tradeNo,
            $period,
            $idempotencyKey,
            &$created
        ) {
            $lockedDistributor = User::query()->lockForUpdate()->find($distributor->id);
            if (!$lockedDistributor?->is_distributor || $lockedDistributor->banned) {
                throw new ApiException('当前分销商账号不可用', 403);
            }

            $sourceOrder = Order::query()
                ->where('user_id', $lockedDistributor->id)
                ->where('trade_no', $tradeNo)
                ->whereNotNull('distributor_order_id')
                ->lockForUpdate()
                ->first();
            if (!$sourceOrder) {
                throw new ApiException('需要续费的分销订阅不存在', 404);
            }

            $periodKey = PlanService::getPeriodKey($period);
            $existing = Order::query()
                ->where('user_id', $lockedDistributor->id)
                ->where('distributor_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    (int) $existing->distributor_order_id !== (int) $sourceOrder->distributor_order_id
                    || (string) $existing->period !== $periodKey
                ) {
                    throw new ApiException('续费请求标识已用于其他续费操作', 409);
                }

                return $existing->load(['plan', 'distributorSubscription.order']);
            }

            $delivery = DistributorOrder::query()
                ->whereKey($sourceOrder->distributor_order_id)
                ->where('distributor_user_id', $lockedDistributor->id)
                ->lockForUpdate()
                ->first();
            if (!$delivery) {
                throw new ApiException('需要续费的分销订阅不存在', 404);
            }
            if ($delivery->delivery_status === DistributorOrder::DELIVERY_CLOSED) {
                throw new ApiException('已关闭的分销订阅不能续费', 409);
            }

            $subscriber = User::query()
                ->whereKey($delivery->subscriber_user_id)
                ->lockForUpdate()
                ->first();
            if (!$subscriber || $subscriber->banned) {
                throw new ApiException('分销订单订阅权益不存在或不可用', 422);
            }

            $now = Carbon::now(config('app.timezone'))->timestamp;
            $holdsActiveSlot = $subscriber->expired_at === null || (int) $subscriber->expired_at >= $now;
            $planQuery = Plan::query()->whereKey($subscriber->plan_id);
            if (!$holdsActiveSlot) {
                $planQuery->lockForUpdate();
            }
            $plan = $planQuery->first();
            if (!$plan) {
                throw new ApiException(__('Subscription plan does not exist'));
            }
            $planService = new PlanService($plan);
            $planService->validateDistributorRenewal($subscriber, $periodKey, $now);
            if (!$holdsActiveSlot && !$planService->hasCapacity($plan)) {
                throw new ApiException(__('Current product is sold out'));
            }

            $expiredAtBefore = (int) $subscriber->expired_at;
            $wasExpired = $expiredAtBefore <= $now;
            $expiredAtAfter = $this->calculateRenewedExpiredAt($expiredAtBefore, $periodKey, $now);

            HookManager::call('order.create.before', [$lockedDistributor, $plan, $periodKey, null]);
            $order = Order::create([
                'user_id' => $lockedDistributor->id,
                'plan_id' => $plan->id,
                'period' => $periodKey,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) ($plan->prices[$periodKey] * 100),
                'type' => Order::TYPE_RENEWAL,
                'status' => Order::STATUS_COMPLETED,
                'callback_no' => 'distributor_auto',
                'commission_status' => 0,
                'commission_balance' => 0,
                'balance_amount' => 0,
                'discount_amount' => 0,
                'paid_at' => null,
                'distributor_order_id' => $delivery->id,
                'entitlement_expired_at_before' => $expiredAtBefore,
                'entitlement_expired_at_after' => $expiredAtAfter,
                'distributor_idempotency_key' => $idempotencyKey,
            ]);

            $subscriber->expired_at = $expiredAtAfter;
            $subscriber->saveOrFail();

            if (
                $wasExpired
                && !app(TrafficResetService::class)->performReset(
                    $subscriber,
                    TrafficResetLog::SOURCE_ORDER
                )
            ) {
                throw new \RuntimeException('续费订阅流量重置失败');
            }

            $created = true;
            return $order->load(['plan', 'distributorSubscription.order']);
        }, 3);

        if ($created) {
            HookManager::call('order.create.after', $order);
            HookManager::call('order.after_create', $order);
        }

        return $order;
    }

    public function deliveryData(DistributorOrder $delivery, bool $includeClaimUrl = true): array
    {
        $delivery->loadMissing([
            'order:id,trade_no,plan_id,period',
            'order.plan:id,name',
            'subscriber:id,token',
            'hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
        ]);

        $data = [
            'trade_no' => $delivery->order->trade_no,
            'subscription_code' => $delivery->subscription_code,
            'subscription_name' => $delivery->subscription_name,
            'customer_name' => trim((string) $delivery->customer_name),
            'plan_id' => (int) $delivery->order->plan_id,
            'plan_name' => (string) ($delivery->order->plan->name ?: ''),
            'period' => PlanService::getLegacyPeriod((string) $delivery->order->period),
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
            'hwid_devices' => $delivery->hwidDevices
                ->sortByDesc('last_seen_at')
                ->map(fn($device) => $device->displayLabel())
                ->filter()
                ->values()
                ->all(),
            'can_open' => $delivery->delivery_status === DistributorOrder::DELIVERY_PENDING,
            'can_reset_subscription' => !empty($delivery->subscriber->token),
            'credential_version' => !empty($delivery->subscriber->token)
                ? DistributorSubscriptionResetService::credentialVersion($delivery->subscriber) : null,
        ];

        if (
            $includeClaimUrl
            && $delivery->delivery_status === DistributorOrder::DELIVERY_PENDING
        ) {
            $subscribeUrl = $this->subscriptionUrl($delivery);
            $data['qr_code'] = $this->makeQrDataUri($subscribeUrl);
        }

        return $data;
    }

    public function subscriptionQrData(DistributorOrder $delivery): array
    {
        $delivery->loadMissing([
            'order:id,trade_no',
            'subscriber:id,token',
            'hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
        ]);

        /** @var User|null $subscriber */
        $subscriber = $delivery->subscriber;
        if (!$subscriber?->token) {
            throw new ApiException('订阅尚未生成', 409);
        }

        return [
            'trade_no' => $delivery->order->trade_no,
            'subscription_code' => $delivery->subscription_code,
            'subscription_name' => $delivery->subscription_name,
            'customer_name' => trim((string) $delivery->customer_name),
            'qr_code' => $this->makeQrDataUri($this->subscriptionUrl($delivery)),
            'delivery_status' => $delivery->delivery_status,
            'can_reset_subscription' => true,
            'credential_version' => DistributorSubscriptionResetService::credentialVersion($delivery->subscriber),
            'hwid_enabled' => (bool) $delivery->hwid_enabled,
            'hwid_devices' => $delivery->hwidDevices
                ->sortByDesc('last_seen_at')
                ->map(fn($device) => $device->displayLabel())
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, bool> $reservedTradeNos
     */
    private function generateUniqueTradeNo(array &$reservedTradeNos = []): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $micro = explode('.', sprintf('%.6F', microtime(true)))[1] ?? '000000';
            $candidate = date('YmdHis') . $micro . random_int(10000, 99999);
            if (isset($reservedTradeNos[$candidate])) {
                continue;
            }
            if (!Order::query()->where('trade_no', $candidate)->exists()) {
                $reservedTradeNos[$candidate] = true;

                return $candidate;
            }
        }

        throw new \RuntimeException('无法生成唯一订单号');
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

    private function calculateRenewedExpiredAt(int $expiredAt, string $period, int $now): int
    {
        $months = OrderService::STR_TO_TIME[$period] ?? null;
        if (!$months) {
            throw new ApiException('无效的套餐周期');
        }

        $base = max($now, $expiredAt);

        return Carbon::createFromTimestamp($base, config('app.timezone'))
            ->addMonthsNoOverflow($months)
            ->timestamp;
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

    public function subscriptionUrl(DistributorOrder $delivery): string
    {
        $delivery->loadMissing(['order:id,trade_no', 'subscriber:id,token']);

        return Helper::withSubscriptionRemark(
            Helper::getSubscribeUrl($delivery->subscriber->token),
            (string) ($delivery->subscription_name ?: $delivery->order->trade_no)
        );
    }
}
