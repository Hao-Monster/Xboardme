<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\DistributorOrder;
use App\Models\User;
use App\Support\SqliteImmediateTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DistributorSubscriptionResetService
{
    public static function credentialVersion(User $subscriber): string
    {
        // A comparison value only: it is never accepted as authentication.
        return hash('sha256', (string) $subscriber->token);
    }

    public function reset(User $distributor, string $tradeNo, string $credentialVersion, string $idempotencyKey): array
    {
        [$delivery, $receipt, $replayed] = SqliteImmediateTransaction::write(function () use (
            $distributor, $tradeNo, $credentialVersion, $idempotencyKey
        ): array {
            $actor = User::query()->find($distributor->id);
            if (!$actor || !$actor->is_distributor || $actor->banned) {
                throw new ApiException('当前分销商账号不可用', 403);
            }

            // Match renewal/HWID lock order; unrelated subscriptions need no shared lock.
            $delivery = DistributorOrder::query()
                ->where('distributor_user_id', $actor->id)
                ->whereHas('order', fn ($query) => $query->where('trade_no', $tradeNo))
                ->lockForUpdate()->first();
            if (!$delivery) {
                throw new ApiException('分销订单不存在', 404);
            }

            $subscriber = User::query()->lockForUpdate()->find($delivery->subscriber_user_id);
            if (!$subscriber || !$subscriber->token) {
                throw new ApiException('订阅尚未生成', 409);
            }
            $currentVersion = self::credentialVersion($subscriber);
            $receipt = DB::table('v2_distributor_subscription_reset')
                ->where('distributor_order_id', $delivery->id)
                // Current read also sees a winner committed while MySQL waited for the delivery lock.
                ->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($receipt) {
                if ((int) $receipt->distributor_user_id !== (int) $actor->id
                    || !hash_equals($receipt->previous_version, $credentialVersion)
                    || !hash_equals($receipt->credential_version, $currentVersion)) {
                    throw new ApiException('该重置请求已失效，请刷新二维码后重新确认', 409);
                }

                return [$delivery->setRelation('subscriber', $subscriber), $receipt, true];
            }
            if (!hash_equals($currentVersion, $credentialVersion)) {
                throw new ApiException('订阅已发生变化，请刷新二维码后重新确认', 409);
            }

            // Dirty-field save retains concurrent traffic accounting and every entitlement.
            do {
                $token = bin2hex(random_bytes(16));
            } while (hash_equals((string) $subscriber->token, $token));
            $subscriber->token = $token;
            $subscriber->uuid = (string) Str::uuid();
            $subscriber->saveOrFail();
            $clearedDevices = $delivery->hwidDevices()->delete();
            $delivery->claim_token = null;
            $delivery->claim_token_hash = hash('sha256', 'revoked:' . $delivery->id . ':' . Str::random(64));
            $delivery->saveOrFail();

            $receipt = (object) [
                'distributor_order_id' => $delivery->id,
                'distributor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'previous_version' => $currentVersion,
                'credential_version' => self::credentialVersion($subscriber),
                'cleared_devices' => $clearedDevices,
                'created_at' => time(),
            ];
            DB::table('v2_distributor_subscription_reset')->insert((array) $receipt);

            return [$delivery->setRelation('subscriber', $subscriber), $receipt, false];
        }, 3);

        // Rendering can fail after commit; retrying the same operation recovers its QR.
        return app(DistributorOrderService::class)->subscriptionQrData($delivery) + [
            'reset_at' => (int) $receipt->created_at,
            'cleared_devices' => (int) $receipt->cleared_devices,
            'replayed' => $replayed,
        ];
    }
}
