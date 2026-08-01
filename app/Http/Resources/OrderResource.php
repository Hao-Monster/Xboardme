<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\PlanService;
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

        return [
            ...$data,
            'period' => PlanService::getLegacyPeriod((string)$this->period),
            'is_distributor_order' => $distributorOrder !== null,
            'payment_label' => $distributorOrder ? '分销免支付' : null,
            'delivery_status' => $distributorOrder?->delivery_status,
            'settlement_status' => $distributorOrder?->settlement_status,
            'config_issued_at' => $distributorOrder?->config_issued_at,
            'claimed_at' => $distributorOrder?->claimed_at,
            'closed_at' => $distributorOrder?->closed_at,
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
