<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\DistributorOrder;
use App\Services\DistributorOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistributorController extends Controller
{
    public function delivery(Request $request, DistributorOrderService $service)
    {
        $request->validate([
            'trade_no' => 'nullable|string',
        ]);

        $query = DistributorOrder::query()
            ->with('order:id,user_id,trade_no')
            ->where('distributor_user_id', $request->user()->id);

        if ($request->filled('trade_no')) {
            $query->whereHas('order', fn($q) => $q->where('trade_no', $request->input('trade_no')));
        } else {
            $query->where(function ($query) {
                $query->where('delivery_status', DistributorOrder::DELIVERY_PENDING)
                    ->orWhere(function ($query) {
                        $query->where('delivery_status', DistributorOrder::DELIVERY_CLAIMED)
                            ->whereNull('connected_at');
                    });
            })
                ->latest('id');
        }

        $delivery = $query->first();
        if (!$delivery) {
            return $this->fail([404, '没有可用的分销交付记录']);
        }

        return $this->success($service->deliveryData($delivery));
    }

    public function close(Request $request, DistributorOrderService $service)
    {
        $request->validate([
            'trade_no' => 'required|string',
            'confirm' => 'accepted',
        ]);

        $delivery = DB::transaction(function () use ($request) {
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

            return $delivery->load('order:id,user_id,trade_no');
        });

        if (!$delivery) {
            return $this->fail([404, '分销交付记录不存在']);
        }

        return $this->success($service->deliveryData($delivery, false));
    }
}
