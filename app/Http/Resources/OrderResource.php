<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
use App\Services\DistributorOrderEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['distributor_order']);

        $distributorOrder = $this->relationLoaded('distributorOrder')
            ? $this->distributorOrder
            : null;
        $subscriptionEntitlement = null;
        if ($distributorOrder) {
            $distributorOrder->setRelation('order', $this->resource);
            $subscriptionEntitlement = app(DistributorOrderEntitlementService::class)
                ->data($distributorOrder);
        }

        $boundDevices = $distributorOrder && $distributorOrder->relationLoaded('hwidDevices')
            ? $distributorOrder->hwidDevices
                ->sortByDesc('last_seen_at')
                ->map(fn($device) => $device->displayLabel())
                ->filter()
                ->values()
                ->all()
            : [];

        return [
            ...$data,
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'is_distributor_order' => $distributorOrder !== null,
            'customer_name' => $distributorOrder?->customer_name,
            'payment_label' => $distributorOrder ? '分销免支付' : null,
            'delivery_status' => $distributorOrder?->delivery_status,
            'settlement_status' => $distributorOrder?->settlement_status,
            'config_issued_at' => $distributorOrder?->config_issued_at,
            'connected_at' => $distributorOrder?->connected_at,
            'connected_node_id' => $distributorOrder?->connected_node_id,
            'connected_node_name' => $distributorOrder?->connected_node_name,
            'claimed_at' => $distributorOrder?->claimed_at,
            'closed_at' => $distributorOrder?->closed_at,
            'hwid_enabled' => $distributorOrder ? (bool) $distributorOrder->hwid_enabled : null,
            'hwid_limit' => $distributorOrder ? (int) $distributorOrder->hwid_limit : null,
            'bound_devices' => $boundDevices,
            'can_view_subscription_qr' => (bool) ($distributorOrder?->subscriber?->token),
            ...($distributorOrder ? ['subscription_entitlement' => $subscriptionEntitlement] : []),
            'plan' => $this->whenLoaded('plan', fn() => PlanResource::make($this->plan)),
            'payment' => $this->whenLoaded('payment', fn() => $this->payment ? [
                'id' => $this->payment->id,
                'name' => $this->payment->name,
                'payment' => $this->payment->payment,
                'icon' => $this->payment->icon,
            ] : null),
        ];
    }
}
