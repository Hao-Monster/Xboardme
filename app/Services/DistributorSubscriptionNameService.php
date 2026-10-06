<?php

namespace App\Services;

use App\Models\DistributorOrder;
use App\Rules\DistributorName;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use LogicException;
use RuntimeException;

class DistributorSubscriptionNameService
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function assignToNewSubscription(DistributorOrder $delivery): void
    {
        if ($delivery->subscription_code && $delivery->subscription_name) {
            return;
        }
        // Legacy subscriptions deliberately keep null metadata and their original title.
        if (!$delivery->wasRecentlyCreated) {
            throw new LogicException('只能在新建分销订阅时分配名称');
        }

        $identity = DB::transaction(function () use ($delivery): array {
            $locked = DistributorOrder::query()->lockForUpdate()->findOrFail($delivery->id);
            if ($locked->subscription_code && $locked->subscription_name) {
                return [$locked->subscription_code, $locked->subscription_name];
            }

            $merchant = $locked->distributor()->first(['id', 'distributor_name']);
            $order = $locked->order()->first(['id', 'created_at']);
            $merchantName = trim((string) $merchant?->distributor_name);
            Validator::make(['distributor_name' => $merchantName], [
                'distributor_name' => ['required', 'string', new DistributorName()],
            ], ['distributor_name.required' => '请先为分销商设置名称'])->validate();
            if (!$order || !$order->created_at) {
                throw new RuntimeException('分销订阅缺少原始订单日期');
            }
            $day = Carbon::createFromTimestamp((int) $order->created_at, 'Asia/Shanghai')->format('ymd');

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $code = $this->generateCode();
                $name = $merchantName . '-' . $day . '-' . $code;
                try {
                    // A savepoint lets a unique-code collision retry without aborting the purchase.
                    DB::transaction(static function () use ($locked, $code, $name): void {
                        DB::table('v2_distributor_order')->where('id', $locked->id)->update([
                            'subscription_code' => $code,
                            'subscription_name' => $name,
                        ]);
                    });
                    return [$code, $name];
                } catch (UniqueConstraintViolationException $exception) {
                    if ($attempt === 9) {
                        throw $exception;
                    }
                }
            }

            throw new RuntimeException('无法分配唯一订阅编号');
        }, 3);

        $delivery->subscription_code = $identity[0];
        $delivery->subscription_name = $identity[1];
        $delivery->syncOriginalAttributes(['subscription_code', 'subscription_name']);
    }

    protected function generateCode(): string
    {
        $code = '';
        for ($index = 0; $index < 6; $index++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $code;
    }
}
