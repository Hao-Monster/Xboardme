<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'asc')
            ->with([
                'group:id,name'
            ])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->success($plans);
    }

    public function visibility(Request $request)
    {
        $planId = $request->query('id');
        if ($planId !== null) {
            $plan = Plan::query()->findOrFail((int) $planId);
            $recipients = DB::table('v2_plan_visibility_user as visibility')
                ->join('v2_user as users', 'users.id', '=', 'visibility.user_id')
                ->where('visibility.plan_id', $plan->id)
                ->get(['visibility.user_id', 'visibility.audience', 'users.email', 'users.distributor_name'])
                ->groupBy('audience');

            return $this->success([
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'customer_visibility' => $plan->customer_visibility,
                    'distributor_visibility' => $plan->distributor_visibility,
                    'customer_users' => ($recipients['customer'] ?? collect())->map(fn ($user) => [
                        'id' => (int) $user->user_id,
                        'email' => $user->email,
                    ])->values(),
                    'distributor_users' => ($recipients['distributor'] ?? collect())->map(fn ($user) => [
                        'id' => (int) $user->user_id,
                        'email' => $user->email,
                        'distributor_name' => $user->distributor_name,
                    ])->values(),
                ],
            ]);
        }

        $plans = Plan::query()->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'customer_visibility', 'distributor_visibility']);

        return $this->success(['plans' => $plans]);
    }

    public function visibilityUsers(Request $request)
    {
        $params = $request->validate([
            'audience' => 'required|in:customer,distributor',
            'q' => 'required|string|min:2|max:255',
        ]);

        $users = User::query()->notInternalSubscriber()
            ->where('is_distributor', $params['audience'] === 'distributor')
            ->where(function ($query) use ($params) {
                $query->where('email', 'like', '%' . addcslashes($params['q'], '%_\\') . '%')
                    ->orWhere('distributor_name', 'like', '%' . addcslashes($params['q'], '%_\\') . '%');
            })
            ->orderBy('email')->limit(20)
            ->get(['id', 'email', 'distributor_name', 'banned']);

        return $this->success($users);
    }

    public function saveVisibility(Request $request)
    {
        $params = $request->validate([
            'plan_id' => 'required|integer|exists:v2_plan,id',
            'customer_visibility' => 'required|in:all,selected',
            'distributor_visibility' => 'required|in:none,all,selected',
            'customer_user_ids' => 'present|array|max:5000',
            'customer_user_ids.*' => 'integer|distinct',
            'distributor_user_ids' => 'present|array|max:5000',
            'distributor_user_ids.*' => 'integer|distinct',
        ]);

        $customerIds = array_values(array_unique(array_map('intval', $params['customer_user_ids'])));
        $distributorIds = array_values(array_unique(array_map('intval', $params['distributor_user_ids'])));
        DB::transaction(function () use ($params, $customerIds, $distributorIds) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($params['plan_id']);
            $this->assertAudienceUsers($customerIds, false);
            $this->assertAudienceUsers($distributorIds, true);
            $plan->update([
                'customer_visibility' => $params['customer_visibility'],
                'distributor_visibility' => $params['distributor_visibility'],
            ]);

            DB::table('v2_plan_visibility_user')->where('plan_id', $plan->id)->delete();
            $rows = [];
            foreach ($customerIds as $userId) {
                $rows[] = ['plan_id' => $plan->id, 'user_id' => $userId, 'audience' => 'customer'];
            }
            foreach ($distributorIds as $userId) {
                $rows[] = ['plan_id' => $plan->id, 'user_id' => $userId, 'audience' => 'distributor'];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('v2_plan_visibility_user')->insert($chunk);
            }
        });

        return $this->success(true);
    }

    private function assertAudienceUsers(array $ids, bool $distributor): void
    {
        if ($ids === []) {
            return;
        }

        $count = User::query()->notInternalSubscriber()
            ->where('is_distributor', $distributor)
            ->whereIn('id', $ids)->count();

        if ($count !== count($ids)) {
            abort(422, '名单中包含不存在、已转为其他身份或内部订阅账号');
        }
    }

    public function save(PlanSave $request)
    {
        $params = $request->validated();
        
        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->fail([400202, '该订阅不存在']);
            }
            
            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id' => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit' => $params['speed_limit'],
                        'device_limit' => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->success(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }
        $params['customer_visibility'] = 'all';
        $params['distributor_visibility'] = 'none';
        if (!Plan::create($params)) {
            return $this->fail([500, '创建失败']);
        }
        return $this->success(true);
    }

    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->fail([400201, '该订阅下存在用户无法删除']);
        }
        
        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }
        
        $deleted = DB::transaction(function () use ($plan) {
            DB::table('v2_plan_visibility_user')->where('plan_id', $plan->id)->delete();
            return $plan->delete();
        });

        return $this->success($deleted);
    }

    public function update(Request $request)
    {
        $updateData = $request->only([
            'show',
            'renew',
            'sell'
        ]);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }
}
