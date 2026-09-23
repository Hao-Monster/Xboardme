<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PlanVisibilityService
{
    public const CUSTOMER = 'customer';
    public const DISTRIBUTOR = 'distributor';

    public function applyTo(Builder $query, ?User $user): Builder
    {
        $audience = $user?->is_distributor ? self::DISTRIBUTOR : self::CUSTOMER;
        $modeColumn = $audience === self::DISTRIBUTOR ? 'distributor_visibility' : 'customer_visibility';

        if ($user === null) {
            return $query->where('customer_visibility', 'all');
        }

        return $query->where(function (Builder $visible) use ($user, $audience, $modeColumn) {
            $visible->where($modeColumn, 'all')
                ->orWhere(function (Builder $selected) use ($user, $audience, $modeColumn) {
                    $selected->where($modeColumn, 'selected')
                        ->whereExists(function ($recipients) use ($user, $audience) {
                            $recipients->selectRaw('1')
                                ->from('v2_plan_visibility_user')
                                ->whereColumn('v2_plan_visibility_user.plan_id', 'v2_plan.id')
                                ->where('v2_plan_visibility_user.user_id', $user->id)
                                ->where('v2_plan_visibility_user.audience', $audience);
                        });
                });
        });
    }

    public function allows(Plan $plan, User $user): bool
    {
        $audience = $user->is_distributor ? self::DISTRIBUTOR : self::CUSTOMER;
        $mode = $audience === self::DISTRIBUTOR ? $plan->distributor_visibility : $plan->customer_visibility;

        if ($mode === 'all') return true;
        if ($mode !== 'selected') return false;

        return DB::table('v2_plan_visibility_user')
            ->where('plan_id', $plan->id)
            ->where('user_id', $user->id)
            ->where('audience', $audience)
            ->exists();
    }
}
