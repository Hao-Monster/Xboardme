<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\DistributorOrder;
use App\Services\DistributorOrderService;
use App\Services\DistributorSubscriptionResetService;
use App\Support\SqliteImmediateTransaction;
use Illuminate\Http\Request;

class DistributorController extends Controller
{
    public function delivery(Request $request, DistributorOrderService $service)
    {
        abort_unless($request->user()->is_distributor && !$request->user()->banned, 403);

        $request->validate([
            'trade_no' => 'nullable|string',
        ]);

        $query = DistributorOrder::query()
            ->with([
                'order:id,user_id,trade_no,plan_id,period',
                'order.plan:id,name',
            ])
            ->where('distributor_user_id', $request->user()->id);

        if ($request->filled('trade_no')) {
            $query->whereHas('order', fn($q) => $q->where('trade_no', $request->input('trade_no')));
        } else {
            $query->where(function ($query) {
                $query->where('delivery_status', DistributorOrder::DELIVERY_PENDING)
                    ->orWhere(function ($query) {
                        $query->where('delivery_status', DistributorOrder::DELIVERY_CLAIMED)
                            ->whereNull('config_issued_at');
                    });
            })
                ->latest('id');
        }

        $delivery = $query->first();
        if (!$delivery) {
            return $this->fail([404, '没有可用的分销交付记录']);
        }

        return $this->success($service->deliveryData($delivery))->header('Cache-Control', 'no-store, private');
    }

    public function deliveryBatch(Request $request, DistributorOrderService $service)
    {
        abort_unless($request->user()->is_distributor && !$request->user()->banned, 403);

        $rawTradeNos = $request->input('trade_nos');
        if (is_string($rawTradeNos)) {
            $rawTradeNos = array_values(array_filter(
                array_map('trim', explode(',', $rawTradeNos)),
                static fn(string $value): bool => $value !== ''
            ));
            $request->merge(['trade_nos' => $rawTradeNos]);
        }

        $validated = $request->validate([
            'trade_nos' => 'required|array|min:1|max:' . DistributorOrderService::MAX_BATCH_QUANTITY,
            'trade_nos.*' => 'required|string|max:64',
            'include_qr' => 'nullable|boolean',
        ]);

        $tradeNos = array_values(array_unique(array_map('trim', $validated['trade_nos'])));
        $includeQr = $request->boolean('include_qr', true);

        $deliveriesByTradeNo = DistributorOrder::query()
            ->with([
                'order:id,user_id,trade_no,plan_id,period',
                'order.plan:id,name',
                'subscriber:id,token',
                'hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
            ])
            ->where('distributor_user_id', $request->user()->id)
            ->whereHas('order', fn($query) => $query->whereIn('trade_no', $tradeNos))
            ->get()
            ->keyBy(fn(DistributorOrder $delivery): string => (string) $delivery->order->trade_no);

        $items = [];
        foreach ($tradeNos as $tradeNo) {
            $delivery = $deliveriesByTradeNo->get($tradeNo);
            if ($delivery) {
                $items[] = $service->deliveryData($delivery, $includeQr);
            }
        }

        if ($items === []) {
            return $this->fail([404, '没有可用的分销交付记录']);
        }

        return $this->success($items)->header('Cache-Control', 'no-store, private');
    }

    public function subscriptionQr(Request $request, DistributorOrderService $service)
    {
        abort_unless($request->user()->is_distributor && !$request->user()->banned, 403);

        $validated = $request->validate([
            'trade_no' => 'required|string|max:64',
        ]);

        $delivery = DistributorOrder::query()
            ->with([
                'order:id,trade_no',
                'subscriber:id,token',
                'hwidDevices:id,distributor_order_id,hwid,device_model,last_seen_at',
            ])
            ->where('distributor_user_id', $request->user()->id)
            ->whereHas('order', fn($query) => $query->where('trade_no', $validated['trade_no']))
            ->first();

        if (!$delivery) {
            return $this->fail([404, '分销订单不存在']);
        }
        if (!$delivery->subscriber?->token) {
            return $this->fail([409, '订阅尚未生成']);
        }

        return $this->success($service->subscriptionQrData($delivery))->header('Cache-Control', 'no-store, private');
    }

    public function resetSubscription(Request $request, DistributorSubscriptionResetService $service)
    {
        abort_unless($request->user()->is_distributor && !$request->user()->banned, 403);

        $validated = $request->validate([
            'trade_no' => 'required|string|max:64',
            'credential_version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'idempotency_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'confirm' => 'required|accepted',
        ]);

        return $this->success($service->reset(
            $request->user(),
            $validated['trade_no'],
            $validated['credential_version'],
            $validated['idempotency_key'],
        ))->header('Cache-Control', 'no-store, private');
    }

    public function close(Request $request, DistributorOrderService $service)
    {
        abort_unless($request->user()->is_distributor && !$request->user()->banned, 403);

        $request->validate([
            'trade_no' => 'required|string',
            'confirm' => 'accepted',
        ]);

        $delivery = SqliteImmediateTransaction::write(function () use ($request) {
            $delivery = DistributorOrder::query()
                ->where('distributor_user_id', $request->user()->id)
                ->whereHas('order', fn($q) => $q->where('trade_no', $request->input('trade_no')))
                ->lockForUpdate()
                ->first();

            if (!$delivery) {
                return null;
            }

            if ($delivery->delivery_status === DistributorOrder::DELIVERY_PENDING) {
                $delivery->delivery_status = DistributorOrder::DELIVERY_CLOSED;
                $delivery->closed_at = time();
                $delivery->claim_token = null;
                $delivery->save();
            }

            return $delivery->load([
                'order:id,user_id,trade_no,plan_id,period',
                'order.plan:id,name',
            ]);
        });

        if (!$delivery) {
            return $this->fail([404, '分销交付记录不存在']);
        }

        return $this->success($service->deliveryData($delivery, false));
    }
}
