<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Exceptions\ApiException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PlanService
{
    public Plan $plan;

    public function __construct(Plan $plan)
    {
        $this->plan = $plan;
    }

    public static function userHoldsActiveSlot(?User $user, Plan $plan, ?int $now = null): bool
    {
        if (!$user || (int) $user->plan_id !== (int) $plan->id) {
            return false;
        }

        $timestamp = $now ?? Carbon::now()->timestamp;

        return $user->expired_at === null || (int) $user->expired_at >= $timestamp;
    }

    /**
     * 获取所有可销售的订阅计划列表
     * 条件：show 和 sell 为 true，且容量充足
     *
     * @return Collection
     */
    public function getAvailablePlans(?User $user = null): Collection
    {
        $query = Plan::where('show', true)->where('sell', true);
        app(PlanVisibilityService::class)->applyTo($query, $user);

        return $this->filterAvailablePlansByCapacity($query->orderBy('sort')->get());
    }

    /**
     * @param Collection<int, Plan> $plans
     * @return Collection<int, Plan>
     */
    private function filterAvailablePlansByCapacity(Collection $plans): Collection
    {
        $limitedPlanIds = $plans
            ->filter(fn(Plan $plan): bool => $plan->capacity_limit !== null && $plan->capacity_limit > 0)
            ->pluck('id')
            ->all();

        if ($limitedPlanIds === []) {
            return $plans;
        }

        $now = Carbon::now()->timestamp;
        $activeCounts = User::query()
            ->whereIn('plan_id', $limitedPlanIds)
            ->where(function ($query) use ($now) {
                $query->where('expired_at', '>=', $now)
                    ->orWhereNull('expired_at');
            })
            ->selectRaw('plan_id, COUNT(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        $reservedOrderCounts = Order::query()
            ->whereIn('plan_id', $limitedPlanIds)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_UPGRADE])
            ->selectRaw('plan_id, COUNT(*) as aggregate')
            ->groupBy('plan_id')
            ->pluck('aggregate', 'plan_id');

        return $plans->filter(function (Plan $plan) use ($activeCounts, $reservedOrderCounts): bool {
            if ($plan->capacity_limit === null || $plan->capacity_limit <= 0) {
                return true;
            }

            $activeUserCount = (int) ($activeCounts[$plan->id] ?? 0);
            $reservedOrderCount = (int) ($reservedOrderCounts[$plan->id] ?? 0);

            return ((int) $plan->capacity_limit - $activeUserCount - $reservedOrderCount) > 0;
        });
    }

    /**
     * 获取指定订阅计划的可用状态
     * 条件：renew 和 sell 为 true
     *
     * @param int $planId
     * @return Plan|null
     */
    public function getAvailablePlan(int $planId): ?Plan
    {
        return Plan::where('id', $planId)
            ->where('sell', true)
            ->where('renew', true)
            ->first();
    }

    /**
     * 检查指定计划是否可用于指定用户
     * 
     * @param Plan $plan
     * @param User $user
     * @return bool
     */
    public function isPlanAvailableForUser(Plan $plan, User $user): bool
    {
        $visibility = app(PlanVisibilityService::class);
        if ($user->is_distributor) {
            return $visibility->allows($plan, $user)
                && $plan->show && $plan->sell && $this->hasCapacity($plan);
        }

        // 如果是同套餐续费或过期重购
        if ((int) $user->plan_id === (int) $plan->id) {
            if (self::userHoldsActiveSlot($user, $plan)) {
                return (bool) $plan->renew;
            }

            return (bool) $plan->renew && (bool) $plan->show && $this->hasCapacity($plan);
        }

        // 如果是新购或换套餐
        return $visibility->allows($plan, $user)
            && $plan->show && $plan->sell && $this->hasCapacity($plan);
    }

    public function validatePurchase(User $user, string $period, bool $forUpdate = false): void
    {
        if (!$this->plan) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        // 转换周期格式为新版格式
        $periodKey = self::getPeriodKey($period);
        $price = $this->plan->prices[$periodKey] ?? null;

        if ($price === null) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            $this->validateResetTrafficPurchase($user);
            return;
        }

        if ((int) $user->plan_id !== (int) $this->plan->id
            && !app(PlanVisibilityService::class)->allows($this->plan, $user)) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        $this->validatePlanAvailability($user);

        if (!self::userHoldsActiveSlot($user, $this->plan) && !$this->hasCapacity($this->plan, $forUpdate)) {
            throw new ApiException(__('Current product is sold out'));
        }
    }

    public function validateDistributorPurchase(User $distributor, string $period, int $quantity = 1, bool $forUpdate = false): void
    {
        if ($quantity < 1) {
            throw new ApiException('下单数量至少为1个', 422);
        }

        $periodKey = self::getPeriodKey($period);
        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            throw new ApiException('分销商只能为终端客户购买新订阅');
        }

        $price = $this->plan->prices[$periodKey] ?? null;
        if ($price === null || $price <= 0) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if (!app(PlanVisibilityService::class)->allows($this->plan, $distributor)
            || !$this->plan->show || !$this->plan->sell) {
            throw new ApiException(__('Subscription plan does not exist'));
        }

        $remainingCapacity = $this->remainingCapacity($this->plan, $forUpdate);
        if ($remainingCapacity !== null) {
            if ($remainingCapacity <= 0) {
                throw new ApiException(__('Current product is sold out'));
            }
            if ($remainingCapacity < $quantity) {
                throw new ApiException("当前套餐剩余可售容量仅剩 {$remainingCapacity} 个，请调小下单数量", 422);
            }
        }
    }

    public function validateDistributorRenewal(User $subscriber, string $period, ?int $now = null): void
    {
        $periodKey = self::getPeriodKey($period);
        if (in_array($periodKey, [Plan::PERIOD_ONETIME, Plan::PERIOD_RESET_TRAFFIC], true)) {
            throw new ApiException('该套餐周期不支持续费');
        }

        if ((int) $subscriber->plan_id !== (int) $this->plan->id) {
            throw new ApiException('只能续费当前订阅使用的套餐');
        }

        if ($subscriber->expired_at === null) {
            throw new ApiException('长期有效订阅无需续费');
        }

        $price = $this->plan->prices[$periodKey] ?? null;
        if ($price === null || $price <= 0) {
            throw new ApiException(__('This payment period cannot be purchased, please choose another period'));
        }

        if (!$this->plan->renew) {
            throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
        }

        $timestamp = $now ?? Carbon::now()->timestamp;
        if (!$this->plan->show && (int) $subscriber->expired_at <= $timestamp) {
            throw new ApiException(__('This subscription has expired, please change to another subscription'));
        }
    }

    /**
     * 智能转换周期格式为新版格式
     * 如果是新版格式直接返回，如果是旧版格式则转换为新版格式
     *
     * @param string $period
     * @return string
     */
    public static function getPeriodKey(string $period): string
    {
        // 如果是新版格式直接返回
        if (in_array($period, self::getNewPeriods())) {
            return $period;
        }

        // 如果是旧版格式则转换为新版格式
        return Plan::LEGACY_PERIOD_MAPPING[$period] ?? $period;
    }
    /**
     * 只能转换周期格式为旧版本
     */
    public static function convertToLegacyPeriod(string $period): string
    {
        $flippedMapping = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flippedMapping[$period] ?? $period;
    }

    /**
     * 获取所有支持的新版周期格式
     *
     * @return array
     */
    public static function getNewPeriods(): array
    {
        return array_values(Plan::LEGACY_PERIOD_MAPPING);
    }

    /**
     * 获取旧版周期格式
     *
     * @param string $period
     * @return string
     */
    public static function getLegacyPeriod(string $period): string
    {
        $flipped = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        return $flipped[$period] ?? $period;
    }

    protected function validateResetTrafficPurchase(User $user): void
    {
        if (!app(UserService::class)->isAvailable($user) || $this->plan->id !== $user->plan_id) {
            throw new ApiException(__('Subscription has expired or no active subscription, unable to purchase Data Reset Package'));
        }
    }

    protected function validatePlanAvailability(User $user): void
    {
        if ((!$this->plan->show && !$this->plan->renew) || (!$this->plan->show && $user->plan_id !== $this->plan->id)) {
            throw new ApiException(__('This subscription has been sold out, please choose another subscription'));
        }

        if (!$this->plan->renew && $user->plan_id == $this->plan->id) {
            throw new ApiException(__('This subscription cannot be renewed, please change to another subscription'));
        }

        if (!$this->plan->show && $this->plan->renew && !app(UserService::class)->isAvailable($user)) {
            throw new ApiException(__('This subscription has expired, please change to another subscription'));
        }
    }

    public function remainingCapacity(Plan $plan, bool $forUpdate = false): ?int
    {
        // The plan schema defines both NULL and 0 as unlimited capacity.
        if ($plan->capacity_limit === null || $plan->capacity_limit <= 0) {
            return null;
        }

        // Callers serializing writes hold an X-lock on v2_plan with no prior non-locking
        // SELECT in the transaction; never range-lock v2_user to avoid table locks/deadlocks.
        unset($forUpdate);

        $now = Carbon::now()->timestamp;
        $activeUserCount = User::query()
            ->where('plan_id', $plan->id)
            ->where(function ($query) use ($now) {
                $query->where('expired_at', '>=', $now)
                    ->orWhereNull('expired_at');
            })
            ->count();

        $reservedOrderCount = Order::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereIn('type', [Order::TYPE_NEW_PURCHASE, Order::TYPE_UPGRADE])
            ->count();

        return max(0, (int) $plan->capacity_limit - $activeUserCount - $reservedOrderCount);
    }

    public function hasCapacity(Plan $plan, bool $forUpdate = false): bool
    {
        $remaining = $this->remainingCapacity($plan, $forUpdate);

        return $remaining === null || $remaining > 0;
    }

    public function getAvailablePeriods(Plan $plan): array
    {
        return array_filter(
            $plan->getActivePeriods(),
            fn($period) => isset($plan->prices[$period]) && $plan->prices[$period] > 0
        );
    }

    public function canResetTraffic(Plan $plan): bool
    {
        return $plan->reset_traffic_method !== Plan::RESET_TRAFFIC_NEVER
            && $plan->getResetTrafficPrice() > 0;
    }
}
