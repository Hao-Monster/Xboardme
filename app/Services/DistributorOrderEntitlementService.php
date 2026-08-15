<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DistributorOrderEntitlementService
{
    private const EDITABLE_FIELDS = [
        'transfer_enable',
        'expired_at',
        'speed_limit',
        'device_limit',
    ];

    public function data(DistributorOrder $distributorOrder): array
    {
        $distributorOrder->loadMissing([
            'order.plan:id,name',
            'subscriber:id,plan_id,transfer_enable,u,d,expired_at,speed_limit,device_limit',
        ]);

        $subscriber = $distributorOrder->subscriber;
        if (!$subscriber) {
            throw new ApiException('分销订单订阅权益不存在', 422);
        }

        $total = max(0, (int) ($subscriber->transfer_enable ?? 0));
        $used = max(0, (int) ($subscriber->u ?? 0) + (int) ($subscriber->d ?? 0));

        return [
            'plan_id' => $distributorOrder->order->plan_id,
            'plan_name' => $distributorOrder->order->plan->name,
            'transfer_enable' => $total,
            'used_traffic' => $used,
            'remaining_traffic' => max(0, $total - $used),
            'expired_at' => $subscriber->expired_at,
            'speed_limit' => $subscriber->speed_limit,
            'device_limit' => $subscriber->device_limit,
        ];
    }

    public function updateForOrder(int $orderId, array $attributes): array
    {
        return DB::transaction(function () use ($orderId, $attributes) {
            $order = Order::query()
                ->with(['distributorSubscription', 'distributorOrder'])
                ->lockForUpdate()
                ->find($orderId);
            $distributorOrder = $order?->distributorSubscription ?: $order?->distributorOrder;

            if (!$distributorOrder) {
                throw new ApiException('该订单不是分销订单', 422);
            }

            $subscriber = User::query()
                ->whereKey($distributorOrder->subscriber_user_id)
                ->lockForUpdate()
                ->first();

            if (!$subscriber) {
                throw new ApiException('分销订单订阅权益不存在', 422);
            }

            $subscriber->fill(Arr::only($attributes, self::EDITABLE_FIELDS));
            $subscriber->saveOrFail();

            $distributorOrder->setRelation('subscriber', $subscriber->fresh([
                'plan:id,name',
            ]));
            $distributorOrder->loadMissing('order.plan:id,name');

            return $this->data($distributorOrder);
        });
    }
}
