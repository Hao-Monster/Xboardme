<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorHwidDevice;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\User;
use App\Support\SqliteImmediateTransaction;
use App\Support\SubscriptionCredentials;
use App\Support\SubscriptionTiming;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DistributorHwidService
{
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 100;

    /**
     * @return array{
     *     delivery: DistributorOrder|null,
     *     allowed: bool,
     *     headers: array<string, string>,
     *     observation: array{
     *         device_id: int,
     *         distributor_order_id: int,
     *         hwid: string,
     *         observed_at: int,
     *         device_os: string|null,
     *         os_version: string|null,
     *         device_model: string|null,
     *         user_agent: string|null,
     *         ip: string|null
     *     }|null
     * }
     */
    public function authorizeSubscription(
        User $subscriber,
        Request $request,
        ?SubscriptionTiming $timing = null
    ): array {
        if ((bool) config('subscription.hwid_async_metadata', false)) {
            return $this->authorizeAsyncMetadataPath($subscriber, $request, $timing);
        }

        return $this->authorizeSynchronousPath($subscriber, $request, $timing);
    }

    /**
     * @return array{
     *     delivery: DistributorOrder|null,
     *     allowed: bool,
     *     headers: array<string, string>,
     *     observation: null
     * }
     */
    private function authorizeSynchronousPath(
        User $subscriber,
        Request $request,
        ?SubscriptionTiming $timing = null
    ): array {
        $delivery = DistributorOrder::query()
            ->where('subscriber_user_id', $subscriber->id)
            ->first();

        if (!$delivery) {
            $timing?->setHwidBranch('no_delivery');

            return ['delivery' => null, 'allowed' => true, 'headers' => [], 'observation' => null];
        }

        if (!$delivery->hwid_enabled) {
            $timing?->setHwidBranch('hwid_disabled');

            return ['delivery' => $delivery, 'allowed' => true, 'headers' => [], 'observation' => null];
        }

        $headers = ['x-hwid-active' => 'true'];
        $hwid = trim((string) $request->header('x-hwid', ''));
        if (!preg_match('/^[a-zA-Z0-9=-]{10,64}$/', $hwid)) {
            $timing?->setHwidBranch('invalid_hwid');

            return [
                'delivery' => $delivery,
                'allowed' => false,
                'headers' => $headers + ['x-hwid-not-supported' => 'true'],
                'observation' => null,
            ];
        }

        $deliveryId = (int) $delivery->id;
        $credentialsRevoked = false;
        $allowed = SqliteImmediateTransaction::write(function () use ($deliveryId, $request, $hwid, $subscriber, &$credentialsRevoked) {
            $locked = DistributorOrder::query()->lockForUpdate()->find($deliveryId);
            if (!$locked || !SubscriptionCredentials::isCurrent($subscriber, true)) {
                $credentialsRevoked = true;
                return false;
            }
            if (!$locked->hwid_enabled) {
                return true;
            }

            $now = time();
            $attributes = array_filter($this->extractMetadata($request), static fn ($value) => $value !== null);
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
            if ($credentialsRevoked) {
                $timing?->setHwidBranch('credentials_revoked');

                return ['delivery' => $delivery, 'allowed' => false, 'headers' => [], 'observation' => null];
            }
            $timing?->setHwidBranch('sync_limit_reached');

            return [
                'delivery' => $delivery,
                'allowed' => false,
                'headers' => $headers + [
                    'x-hwid-max-devices-reached' => 'true',
                    'x-hwid-limit' => 'true',
                ],
                'observation' => null,
            ];
        }

        $timing?->setHwidBranch('sync_allowed');

        return [
            'delivery' => $delivery,
            'allowed' => true,
            'headers' => $headers,
            'observation' => null,
        ];
    }

    /**
     * @return array{
     *     delivery: DistributorOrder|null,
     *     allowed: bool,
     *     headers: array<string, string>,
     *     observation: array{
     *         device_id: int,
     *         distributor_order_id: int,
     *         hwid: string,
     *         observed_at: int,
     *         device_os: string|null,
     *         os_version: string|null,
     *         device_model: string|null,
     *         user_agent: string|null,
     *         ip: string|null
     *     }|null
     * }
     */
    private function authorizeAsyncMetadataPath(
        User $subscriber,
        Request $request,
        ?SubscriptionTiming $timing = null
    ): array {
        $rawHwid = trim((string) $request->header('x-hwid', ''));
        $hwidValid = preg_match('/^[a-zA-Z0-9=-]{10,64}$/', $rawHwid) === 1;

        $query = DistributorOrder::query();
        if ($hwidValid) {
            $query->leftJoin('v2_distributor_hwid_device as matched_hwid_device', static function ($join) use ($rawHwid): void {
                $join->on('matched_hwid_device.distributor_order_id', '=', 'v2_distributor_order.id')
                    ->where('matched_hwid_device.hwid', '=', $rawHwid);
            });
        } else {
            $query->leftJoin('v2_distributor_hwid_device as matched_hwid_device', static function ($join): void {
                $join->whereRaw('1 = 0');
            });
        }

        $delivery = $query
            ->where('v2_distributor_order.subscriber_user_id', $subscriber->id)
            ->select([
                'v2_distributor_order.*',
                DB::raw('matched_hwid_device.id as _matched_hwid_device_id'),
            ])
            ->first();

        $matchedDeviceId = null;
        if ($delivery !== null) {
            $rawDeviceId = $delivery->getAttribute('_matched_hwid_device_id');
            if ($rawDeviceId !== null) {
                $matchedDeviceId = (int) $rawDeviceId;
            }
            $delivery->offsetUnset('_matched_hwid_device_id');
            $delivery->syncOriginal();
        }

        if (!$delivery) {
            $timing?->setHwidBranch('no_delivery');

            return ['delivery' => null, 'allowed' => true, 'headers' => [], 'observation' => null];
        }

        if (!$delivery->hwid_enabled) {
            $timing?->setHwidBranch('hwid_disabled');

            return ['delivery' => $delivery, 'allowed' => true, 'headers' => [], 'observation' => null];
        }

        $headers = ['x-hwid-active' => 'true'];
        if (!$hwidValid) {
            $timing?->setHwidBranch('invalid_hwid');

            return [
                'delivery' => $delivery,
                'allowed' => false,
                'headers' => $headers + ['x-hwid-not-supported' => 'true'],
                'observation' => null,
            ];
        }

        if ($matchedDeviceId !== null && $matchedDeviceId > 0) {
            $timing?->setHwidBranch('registered_readonly');

            return [
                'delivery' => $delivery,
                'allowed' => true,
                'headers' => $headers,
                'observation' => $this->buildObservation(
                    deviceId: $matchedDeviceId,
                    distributorOrderId: (int) $delivery->id,
                    hwid: $rawHwid,
                    observedAt: time(),
                    request: $request
                ),
            ];
        }

        $deliveryId = (int) $delivery->id;
        /** @var array{
         *     allowed: bool,
         *     reason: string,
         *     delivery: DistributorOrder|null,
         *     observation: array{
         *         device_id: int,
         *         distributor_order_id: int,
         *         hwid: string,
         *         observed_at: int,
         *         device_os: string|null,
         *         os_version: string|null,
         *         device_model: string|null,
         *         user_agent: string|null,
         *         ip: string|null
         *     }|null
         * } $txResult
         */
        $txResult = SqliteImmediateTransaction::write(function () use ($deliveryId, $request, $rawHwid, $subscriber): array {
            $locked = DistributorOrder::query()->lockForUpdate()->find($deliveryId);
            if (!$locked) {
                return [
                    'allowed' => false,
                    'reason' => 'delivery_missing',
                    'delivery' => null,
                    'observation' => null,
                ];
            }

            if (!SubscriptionCredentials::isCurrent($subscriber, true)) {
                return [
                    'allowed' => false,
                    'reason' => 'credentials_revoked',
                    'delivery' => $locked,
                    'observation' => null,
                ];
            }

            if (!$locked->hwid_enabled) {
                return [
                    'allowed' => true,
                    'reason' => 'hwid_disabled_in_tx',
                    'delivery' => $locked,
                    'observation' => null,
                ];
            }

            $existing = DistributorHwidDevice::query()
                ->where('distributor_order_id', $locked->id)
                ->where('hwid', $rawHwid)
                ->first();

            if ($existing) {
                return [
                    'allowed' => true,
                    'reason' => 'existing_in_tx',
                    'delivery' => $locked,
                    'observation' => $this->buildObservation(
                        deviceId: (int) $existing->id,
                        distributorOrderId: (int) $locked->id,
                        hwid: $rawHwid,
                        observedAt: time(),
                        request: $request
                    ),
                ];
            }

            $registered = DistributorHwidDevice::query()
                ->where('distributor_order_id', $locked->id)
                ->count();
            if ($registered >= $locked->hwid_limit) {
                return [
                    'allowed' => false,
                    'reason' => 'limit_reached',
                    'delivery' => $locked,
                    'observation' => null,
                ];
            }

            $now = time();
            $attributes = array_filter($this->extractMetadata($request), static fn ($value) => $value !== null);

            try {
                DistributorHwidDevice::create($attributes + [
                    'distributor_order_id' => $locked->id,
                    'hwid' => $rawHwid,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $raced = DistributorHwidDevice::query()
                    ->where('distributor_order_id', $locked->id)
                    ->where('hwid', $rawHwid)
                    ->first();
                if ($raced) {
                    return [
                        'allowed' => true,
                        'reason' => 'existing_in_tx',
                        'delivery' => $locked,
                        'observation' => $this->buildObservation(
                            deviceId: (int) $raced->id,
                            distributorOrderId: (int) $locked->id,
                            hwid: $rawHwid,
                            observedAt: $now,
                            request: $request
                        ),
                    ];
                }

                throw new RuntimeException('Failed to resolve unique HWID device after constraint violation.');
            }

            return [
                'allowed' => true,
                'reason' => 'registered_new',
                'delivery' => $locked,
                'observation' => null,
            ];
        }, 3);

        if (in_array($txResult['reason'], ['delivery_missing', 'credentials_revoked'], true)) {
            $timing?->setHwidBranch('new_device_' . $txResult['reason']);

            return [
                'delivery' => null,
                'allowed' => false,
                'headers' => [],
                'observation' => null,
            ];
        }

        if ($txResult['reason'] === 'hwid_disabled_in_tx') {
            $timing?->setHwidBranch('new_device_hwid_disabled');

            return [
                'delivery' => $txResult['delivery'],
                'allowed' => true,
                'headers' => [],
                'observation' => null,
            ];
        }

        if (!$txResult['allowed']) {
            $timing?->setHwidBranch('new_device_limit_reached');

            return [
                'delivery' => $txResult['delivery'] ?? $delivery,
                'allowed' => false,
                'headers' => $headers + [
                    'x-hwid-max-devices-reached' => 'true',
                    'x-hwid-limit' => 'true',
                ],
                'observation' => null,
            ];
        }

        $timing?->setHwidBranch(
            $txResult['reason'] === 'existing_in_tx' ? 'new_device_race_existing' : 'new_device_registered'
        );

        return [
            'delivery' => $txResult['delivery'] ?? $delivery,
            'allowed' => true,
            'headers' => $headers,
            'observation' => $txResult['observation'],
        ];
    }

    /**
     * @return array{
     *     device_os: string|null,
     *     os_version: string|null,
     *     device_model: string|null,
     *     user_agent: string|null,
     *     ip: string|null
     * }
     */
    private function extractMetadata(Request $request): array
    {
        $userAgent = mb_substr((string) $request->userAgent(), 0, 255);
        $ip = mb_substr((string) $request->ip(), 0, 45);

        return [
            'device_os' => $this->header($request, 'x-device-os', 100),
            'os_version' => $this->header($request, 'x-ver-os', 100),
            'device_model' => $this->header($request, 'x-device-model', 150),
            'user_agent' => $userAgent !== '' ? $userAgent : null,
            'ip' => $ip !== '' ? $ip : null,
        ];
    }

    /**
     * @return array{
     *     device_id: int,
     *     distributor_order_id: int,
     *     hwid: string,
     *     observed_at: int,
     *     device_os: string|null,
     *     os_version: string|null,
     *     device_model: string|null,
     *     user_agent: string|null,
     *     ip: string|null
     * }
     */
    private function buildObservation(
        int $deviceId,
        int $distributorOrderId,
        string $hwid,
        int $observedAt,
        Request $request
    ): array {
        $metadata = $this->extractMetadata($request);

        return [
            'device_id' => $deviceId,
            'distributor_order_id' => $distributorOrderId,
            'hwid' => $hwid,
            'observed_at' => $observedAt,
            'device_os' => $metadata['device_os'],
            'os_version' => $metadata['os_version'],
            'device_model' => $metadata['device_model'],
            'user_agent' => $metadata['user_agent'],
            'ip' => $metadata['ip'],
        ];
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
