<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorHwidDevice;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DistributorHwidService
{
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 100;

    /**
     * @return array{delivery: DistributorOrder|null, allowed: bool, headers: array<string,string>}
     */
    public function authorizeSubscription(User $subscriber, Request $request): array
    {
        $delivery = DistributorOrder::query()
            ->where('subscriber_user_id', $subscriber->id)
            ->first();

        if (!$delivery || !$delivery->hwid_enabled) {
            return ['delivery' => $delivery, 'allowed' => true, 'headers' => []];
        }

        $headers = ['x-hwid-active' => 'true'];
        $hwid = trim((string) $request->header('x-hwid', ''));
        if (!preg_match('/^[a-zA-Z0-9=-]{10,64}$/', $hwid)) {
            return [
                'delivery' => $delivery,
                'allowed' => false,
                'headers' => $headers + ['x-hwid-not-supported' => 'true'],
            ];
        }

        $allowed = DB::transaction(function () use ($delivery, $request, $hwid) {
            $locked = DistributorOrder::query()->lockForUpdate()->find($delivery->id);
            if (!$locked || !$locked->hwid_enabled) {
                return true;
            }

            $now = time();
            $attributes = array_filter([
                'device_os' => $this->header($request, 'x-device-os', 100),
                'os_version' => $this->header($request, 'x-ver-os', 100),
                'device_model' => $this->header($request, 'x-device-model', 150),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
                'ip' => mb_substr((string) $request->ip(), 0, 45) ?: null,
            ], fn($value) => $value !== null);
            $attributes['last_seen_at'] = $now;

            $device = DistributorHwidDevice::query()
                ->where('distributor_order_id', $locked->id)
                ->where('hwid', $hwid)
                ->first();

            if ($device) {
                $device->fill($attributes)->save();
                return true;
            }

            $registered = DistributorHwidDevice::query()
                ->where('distributor_order_id', $locked->id)
                ->count();
            if ($registered >= $locked->hwid_limit) {
                return false;
            }

            DistributorHwidDevice::create($attributes + [
                'distributor_order_id' => $locked->id,
                'hwid' => $hwid,
                'first_seen_at' => $now,
            ]);

            return true;
        }, 3);

        if (!$allowed) {
            return [
                'delivery' => $delivery,
                'allowed' => false,
                'headers' => $headers + [
                    'x-hwid-max-devices-reached' => 'true',
                    'x-hwid-limit' => 'true',
                ],
            ];
        }

        return ['delivery' => $delivery, 'allowed' => true, 'headers' => $headers];
    }

    public function settingsForOrder(int $orderId): array
    {
        $delivery = $this->deliveryForOrder($orderId);

        return [
            'enabled' => (bool) $delivery->hwid_enabled,
            'limit' => (int) $delivery->hwid_limit,
            'registered_count' => $delivery->hwidDevices()->count(),
        ];
    }

    public function updateSettings(int $orderId, bool $enabled, int $limit): array
    {
        if ($limit < self::MIN_LIMIT || $limit > self::MAX_LIMIT) {
            throw new ApiException('HWID 数量必须在 1 到 100 之间', 422);
        }

        $delivery = $this->deliveryForOrder($orderId);
        $delivery->update([
            'hwid_enabled' => $enabled,
            'hwid_limit' => $limit,
        ]);

        return $this->settingsForOrder($orderId);
    }

    public function devicesForOrder(int $orderId, ?string $search = null): array
    {
        $delivery = $this->deliveryForOrder($orderId);
        $search = trim((string) $search);

        return $delivery->hwidDevices()
            ->when($search !== '', fn($query) => $query->where('hwid', 'like', "%{$search}%"))
            ->latest('last_seen_at')
            ->get()
            ->map(fn(DistributorHwidDevice $device) => [
                'id' => $device->id,
                'hwid' => $device->hwid,
                'device_os' => $device->device_os,
                'os_version' => $device->os_version,
                'device_model' => $device->device_model,
                'user_agent' => $device->user_agent,
                'ip' => $device->ip,
                'first_seen_at' => $device->first_seen_at,
                'last_seen_at' => $device->last_seen_at,
            ])
            ->all();
    }

    public function deleteDevice(int $orderId, int $deviceId): bool
    {
        $delivery = $this->deliveryForOrder($orderId);

        return (bool) DistributorHwidDevice::query()
            ->where('id', $deviceId)
            ->where('distributor_order_id', $delivery->id)
            ->delete();
    }

    private function deliveryForOrder(int $orderId): DistributorOrder
    {
        $order = Order::query()
            ->with(['distributorSubscription', 'distributorOrder'])
            ->find($orderId);
        $delivery = $order?->distributorSubscription ?: $order?->distributorOrder;
        if (!$delivery) {
            throw new ApiException('分销订单不存在', 422);
        }

        return $delivery;
    }

    private function header(Request $request, string $name, int $length): ?string
    {
        $value = trim((string) $request->header($name, ''));
        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
