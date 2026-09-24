<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Http\Controllers\V2\Admin\PlanController as AdminPlanController;
use App\Services\PlanService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanAudienceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_plans_keep_both_audiences_and_selected_customers_only_see_authorized_plans(): void
    {
        $legacy = $this->plan('Legacy plan')->fresh();
        $restricted = $this->plan('Restricted plan', [
            'customer_visibility' => 'selected',
            'distributor_visibility' => 'none',
        ]);
        $allowed = $this->user('allowed@example.com');
        $denied = $this->user('denied@example.com');
        DB::table('v2_plan_visibility_user')->insert([
            'plan_id' => $restricted->id,
            'user_id' => $allowed->id,
            'audience' => 'customer',
        ]);

        $this->assertSame('all', $legacy->customer_visibility);
        $this->assertSame('all', $legacy->distributor_visibility);
        $this->assertEqualsCanonicalizing(
            [$legacy->id, $restricted->id],
            app(PlanService::class)->getAvailablePlans($allowed)->pluck('id')->all()
        );
        $this->assertSame([$legacy->id], app(PlanService::class)->getAvailablePlans($denied)->pluck('id')->all());
    }

    public function test_customer_cannot_fetch_or_order_a_plan_outside_their_allowlist(): void
    {
        $plan = $this->plan('Private plan', ['customer_visibility' => 'selected']);
        Sanctum::actingAs($this->user('excluded-customer@example.com'));

        $this->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/user/plan/fetch?id=' . $plan->id)
            ->assertStatus(400)
            ->assertJsonPath('message', '订阅计划不存在');
        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
        ])->assertStatus(400);
    }

    public function test_distributor_must_be_in_the_plan_specific_allowlist_to_see_or_order_it(): void
    {
        $plan = $this->plan('Dealer private plan', [
            'customer_visibility' => 'all',
            'distributor_visibility' => 'selected',
        ]);
        Sanctum::actingAs($this->user('excluded-dealer@example.com', true));

        $this->getJson('/api/v1/user/plan/fetch')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/user/order/save', [
            'plan_id' => $plan->id,
            'period' => 'month_price',
        ])->assertStatus(400);
        $this->assertDatabaseCount('v2_order', 0);
    }

    public function test_selected_customer_can_fetch_plan_by_id_and_service_group_is_unchanged(): void
    {
        $customer = $this->user('included-customer@example.com');
        $plan = $this->plan('Selected plan', [
            'group_id' => 7,
            'customer_visibility' => 'selected',
        ]);
        DB::table('v2_plan_visibility_user')->insert([
            'plan_id' => $plan->id,
            'user_id' => $customer->id,
            'audience' => 'customer',
        ]);
        Sanctum::actingAs($customer);

        $this->getJson('/api/v1/user/plan/fetch?id=' . $plan->id)
            ->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.group_id', 7);
    }

    public function test_admin_can_save_audience_modes_and_separate_recipient_lists(): void
    {
        $admin = $this->user('visibility-admin@example.com');
        $admin->is_admin = true;
        $admin->save();
        $customer = $this->user('visible-customer@example.com');
        $distributor = $this->user('visible-dealer@example.com', true);
        $plan = $this->plan('Configurable plan');
        Sanctum::actingAs($admin);

        $this->postJson($this->visibilityUri('saveVisibility'), [
            'plan_id' => $plan->id,
            'customer_visibility' => 'selected',
            'distributor_visibility' => 'selected',
            'customer_user_ids' => [$customer->id],
            'distributor_user_ids' => [$distributor->id],
        ])->assertOk()->assertJsonPath('data', true);

        $this->getJson($this->visibilityUri('visibility') . '?id=' . $plan->id)
            ->assertOk()
            ->assertJsonPath('data.plan.customer_visibility', 'selected')
            ->assertJsonPath('data.plan.distributor_visibility', 'selected')
            ->assertJsonPath('data.plan.customer_users.0.id', $customer->id)
            ->assertJsonPath('data.plan.distributor_users.0.id', $distributor->id);

        $this->assertDatabaseHas('v2_plan_visibility_user', [
            'plan_id' => $plan->id,
            'user_id' => $customer->id,
            'audience' => 'customer',
        ]);
        $this->assertDatabaseHas('v2_plan_visibility_user', [
            'plan_id' => $plan->id,
            'user_id' => $distributor->id,
            'audience' => 'distributor',
        ]);
    }

    public function test_new_plans_default_to_all_customers_and_no_distributors(): void
    {
        $admin = $this->user('new-plan-admin@example.com');
        $admin->is_admin = true;
        $admin->save();
        Sanctum::actingAs($admin);

        $this->postJson($this->visibilityUri('save'), [
            'name' => 'New default plan',
            'transfer_enable' => 30,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'group_id' => 4,
        ])->assertOk();

        $plan = Plan::where('name', 'New default plan')->firstOrFail();
        $this->assertSame('all', $plan->customer_visibility);
        $this->assertSame('none', $plan->distributor_visibility);
        $this->assertSame(4, $plan->group_id);
    }

    public function test_admin_can_save_a_plan_distributor_hwid_default_within_supported_bounds(): void
    {
        $admin = $this->user('plan-hwid-admin@example.com');
        $admin->is_admin = true;
        $admin->save();
        Sanctum::actingAs($admin);

        $plan = $this->plan('Plan HWID default');
        $this->postJson($this->visibilityUri('save'), [
            'id' => $plan->id,
            'name' => $plan->name,
            'transfer_enable' => $plan->transfer_enable,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'distributor_hwid_limit' => 5,
        ])->assertOk();

        $this->assertSame(5, $plan->fresh()->distributor_hwid_limit);

        $this->postJson($this->visibilityUri('save'), [
            'id' => $plan->id,
            'name' => $plan->name,
            'transfer_enable' => $plan->transfer_enable,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
            'distributor_hwid_limit' => 101,
        ])->assertUnprocessable();
    }

    private function visibilityUri(string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->getActionName() === AdminPlanController::class . '@' . $method
        );
        $this->assertNotNull($route, 'Plan visibility route not found: ' . $method);

        return '/' . ltrim($route->uri(), '/');
    }

    private function plan(string $name, array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $name,
            'group_id' => 1,
            'transfer_enable' => 30,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 30],
        ], $attributes));
    }

    private function user(string $email, bool $distributor = false): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $distributor,
            'distributor_name' => $distributor ? 'Test distributor' : null,
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ]);
    }
}
