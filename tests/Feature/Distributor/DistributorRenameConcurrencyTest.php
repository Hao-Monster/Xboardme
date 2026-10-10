<?php

namespace Tests\Feature\Distributor;

use App\Http\Controllers\V2\Admin\UserController;
use App\Http\Middleware\InitializePlugins;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DistributorRenameConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_legacy_null_revision_can_be_renamed_and_read_back_without_other_mutations(): void
    {
        $admin = $this->admin();
        $dealer = $this->user('qa-cas-target@example.com', '原商户');
        $dealer->update(['balance' => 12345, 'commission_balance' => 6789, 'discount' => 90]);
        $order = $this->order($dealer);
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        $session = $dealer->createToken('qa-cas-session')->accessToken;
        $visibility = ['user_id' => $dealer->id, 'plan_id' => $order->plan_id, 'audience' => 'distributor'];
        DB::table('v2_plan_visibility_user')->insert($visibility);
        $before = $dealer->fresh()->getRawOriginal();
        $orderBefore = $order->fresh()->getRawOriginal();
        $deliveryBefore = $delivery->getRawOriginal();
        $subscriberBefore = $delivery->subscriber->getRawOriginal();
        $sessionBefore = $session->fresh()->getRawOriginal();
        $urlBefore = app(DistributorOrderService::class)->subscriptionUrl($delivery);
        $this->assertNull($dealer->fresh()->distributor_revision);
        $payload = ['id' => $dealer->id, 'distributor_name' => '新商户', 'expected_distributor_revision' => null];

        $this->postJson($this->endpoint('renameDistributor'), $payload)
            ->assertOk()->assertJsonPath('data', true);

        $saved = $dealer->fresh();
        $this->assertTrue(Str::isUuid($saved->distributor_revision));
        $this->getJson($this->endpoint('getUserInfoById') . '?id=' . $dealer->id)
            ->assertOk()->assertJsonPath('data.distributor_name', '新商户')
            ->assertJsonPath('data.distributor_revision', $saved->distributor_revision)
            ->assertJsonPath('data.is_distributor', true);
        $after = $saved->getRawOriginal();
        foreach (['distributor_name', 'distributor_revision', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
        $this->assertSame($deliveryBefore, $delivery->fresh()->getRawOriginal());
        $this->assertSame($subscriberBefore, $delivery->subscriber->fresh()->getRawOriginal());
        $this->assertSame($sessionBefore, $session->fresh()->getRawOriginal());
        $this->assertDatabaseHas('v2_plan_visibility_user', $visibility);
        $this->assertSame($urlBefore, app(DistributorOrderService::class)->subscriptionUrl($delivery->fresh()));
        $audit = AdminAuditLog::where('admin_id', $admin->id)->where('action', 'user_distributor.rename')->firstOrFail();
        $this->assertSame($payload, json_decode($audit->request_data, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_late_first_request_cannot_overwrite_the_second_request_that_committed_first(): void
    {
        $this->admin();
        $dealer = $this->user('qa-late-first@example.com', '原商户');
        $first = ['id' => $dealer->id, 'distributor_name' => '第一名称', 'expected_distributor_revision' => null];
        $second = [...$first, 'distributor_name' => '第二名称'];

        // Deliver in the order that occurs when the first HTTP request is delayed beyond its timeout.
        $this->postJson($this->endpoint('renameDistributor'), $second)->assertOk();
        $confirmed = $dealer->fresh()->getRawOriginal();
        $this->postJson($this->endpoint('renameDistributor'), $first)->assertStatus(409);

        $this->assertSame('第二名称', $dealer->fresh()->distributor_name);
        $this->assertSame($confirmed, $dealer->fresh()->getRawOriginal());
    }

    public function test_first_commit_rejects_a_stale_second_request_but_a_fresh_revision_allows_it(): void
    {
        $this->admin();
        $dealer = $this->user('qa-first-wins@example.com', '原商户');
        $payload = ['id' => $dealer->id, 'distributor_name' => '第一名称', 'expected_distributor_revision' => null];
        $this->postJson($this->endpoint('renameDistributor'), $payload)->assertOk();
        $firstRevision = $dealer->fresh()->distributor_revision;
        $second = [...$payload, 'distributor_name' => '第二名称'];
        $this->postJson($this->endpoint('renameDistributor'), $second)->assertStatus(409);
        $this->assertSame('第一名称', $dealer->fresh()->distributor_name);

        $this->postJson($this->endpoint('renameDistributor'), [...$second, 'expected_distributor_revision' => $firstRevision])
            ->assertOk();
        $this->assertSame('第二名称', $dealer->fresh()->distributor_name);
        $this->assertNotSame($firstRevision, $dealer->fresh()->distributor_revision);
    }

    public function test_revision_is_checked_in_the_database_write_not_only_a_preceding_read(): void
    {
        $this->admin();
        $dealer = $this->user('qa-atomic-cas@example.com', '原商户');
        $newerRevision = (string) Str::uuid();
        $interleaved = false;
        DB::connection()->beforeExecuting(function (string $query) use ($dealer, $newerRevision, &$interleaved): void {
            if ($interleaved || !preg_match('/^\s*update\s+["`]?v2_user["`]?\s/i', $query)) {
                return;
            }
            $interleaved = true;
            // The competitor commits at the last possible point before the actual rename SQL.
            DB::table('v2_user')->where('id', $dealer->id)->update([
                'distributor_name' => '竞争者名称', 'distributor_revision' => $newerRevision,
            ]);
        });

        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '过期请求', 'expected_distributor_revision' => null,
        ])->assertStatus(409);

        $this->assertTrue($interleaved);
        $this->assertSame('竞争者名称', $dealer->fresh()->distributor_name);
        $this->assertSame($newerRevision, $dealer->fresh()->distributor_revision);
    }

    public function test_legacy_update_name_aba_invalidates_the_original_revision(): void
    {
        $this->admin();
        $dealer = $this->user('qa-legacy-aba@example.com', '原商户');
        $uri = $this->endpoint('update');
        $this->postJson($uri, ['id' => $dealer->id, 'distributor_name' => '中间名称'])->assertOk();
        $middleRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($middleRevision));
        $this->postJson($uri, ['id' => $dealer->id, 'distributor_name' => '原商户'])->assertOk();
        $lastRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($lastRevision));
        $this->assertNotSame($middleRevision, $lastRevision);

        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '过期请求', 'expected_distributor_revision' => null,
        ])->assertStatus(409);
        $this->assertSame('原商户', $dealer->fresh()->distributor_name);
        $this->assertSame($lastRevision, $dealer->fresh()->distributor_revision);
    }

    public function test_model_identity_changes_rotate_revision_and_stale_model_saves_do_not_reuse_it(): void
    {
        $dealer = $this->user('qa-model-revisions@example.com', '原商户');
        $staleCopy = $dealer->fresh();
        $dealer->update(['distributor_name' => '中间名称']);
        $firstRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($firstRevision));
        $staleCopy->update(['distributor_name' => '另一个名称']);
        $secondRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($secondRevision));
        $this->assertNotSame($firstRevision, $secondRevision);
        $dealer->refresh()->update(['distributor_name' => '原商户']);
        $thirdRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($thirdRevision));
        $this->assertNotSame($secondRevision, $thirdRevision);

        $this->admin();
        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '过期请求', 'expected_distributor_revision' => null,
        ])->assertStatus(409);
        $this->assertSame('原商户', $dealer->fresh()->distributor_name);
    }

    public function test_role_revocation_and_restoration_fence_old_rename_requests(): void
    {
        $this->admin();
        $dealer = $this->user('qa-role-aba@example.com', '原商户');
        $uri = $this->endpoint('update');
        $this->postJson($uri, ['id' => $dealer->id, 'is_distributor' => false])->assertOk();
        $revoked = $dealer->fresh();
        $this->assertTrue(Str::isUuid($revoked->distributor_revision));
        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '过期请求', 'expected_distributor_revision' => $revoked->distributor_revision,
        ])->assertStatus(409);
        $this->assertFalse($dealer->fresh()->is_distributor);
        $this->assertNull($dealer->fresh()->distributor_name);
        $this->postJson($uri, ['id' => $dealer->id, 'is_distributor' => true, 'distributor_name' => '原商户'])->assertOk();
        $restoredRevision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($restoredRevision));
        $this->assertNotSame($revoked->distributor_revision, $restoredRevision);
        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '过期请求', 'expected_distributor_revision' => null,
        ])->assertStatus(409);
        $this->assertSame('原商户', $dealer->fresh()->distributor_name);
    }

    public function test_ordinary_profile_save_does_not_derive_or_change_identity_fields(): void
    {
        $this->withoutMiddleware(InitializePlugins::class);
        $this->admin();
        $dealer = $this->user('qa-remarks-only@example.com', '  历史名称  ');
        $before = $dealer->fresh()->getRawOriginal();
        $captured = null;
        HookManager::register('admin.user.update.before', function (array $context) use (&$captured): void {
            $captured = $context['params'];
        });
        $this->postJson($this->endpoint('update'), ['id' => $dealer->id, 'remarks' => 'Only the remark changes'])
            ->assertOk();

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('distributor_name', $captured);
        $this->assertArrayNotHasKey('is_distributor', $captured);
        $after = $dealer->fresh()->getRawOriginal();
        $this->assertSame('Only the remark changes', $after['remarks']);
        foreach (['remarks', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
    }

    public function test_revision_is_not_mass_assignable_and_unrelated_or_noop_saves_do_not_rotate_it(): void
    {
        $dealer = $this->user('qa-revision-guard@example.com', '原商户');
        $this->assertTrue($dealer->isGuarded('distributor_revision'));
        $dealer->update(['distributor_name' => '新名称']);
        $revision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($revision));
        $dealer->refresh()->update(['remarks' => 'remark', 'distributor_revision' => (string) Str::uuid()]);
        $this->assertSame($revision, $dealer->fresh()->distributor_revision);
        $dealer->refresh()->update(['distributor_name' => '新名称', 'is_distributor' => true]);
        $this->assertSame($revision, $dealer->fresh()->distributor_revision);
    }

    public function test_name_and_revision_validation_rejects_missing_invalid_and_oversized_values(): void
    {
        $this->admin();
        $dealer = $this->user('qa-rename-validation@example.com', '原商户');
        $uri = $this->endpoint('renameDistributor');
        $base = ['id' => $dealer->id, 'distributor_name' => '合法名称', 'expected_distributor_revision' => null];
        $missingRevision = $base;
        unset($missingRevision['expected_distributor_revision']);
        $this->postJson($uri, $missingRevision)->assertUnprocessable()->assertJsonValidationErrors('expected_distributor_revision');
        foreach ([1, [], 'not-a-uuid'] as $revision) {
            $this->postJson($uri, [...$base, 'expected_distributor_revision' => $revision])
                ->assertUnprocessable()->assertJsonValidationErrors('expected_distributor_revision');
        }
        foreach (['', '   ', str_repeat('甲', 17), str_repeat('😀', 9), "含\u{200B}隐字符", 'a@b.co', []] as $name) {
            $this->postJson($uri, [...$base, 'distributor_name' => $name])
                ->assertUnprocessable()->assertJsonValidationErrors('distributor_name');
        }
        $this->assertSame('原商户', $dealer->fresh()->distributor_name);
        $this->assertNull($dealer->fresh()->distributor_revision);
        $this->postJson($uri, [...$base, 'distributor_name' => '  ' . str_repeat('😀', 8) . '  '])->assertOk();
        $this->assertSame(str_repeat('😀', 8), $dealer->fresh()->distributor_name);
    }

    public function test_rename_ignores_unrelated_fields_and_cannot_accept_a_caller_chosen_new_revision(): void
    {
        $this->admin();
        $dealer = $this->user('qa-field-isolation@example.com', '原商户');
        $session = $dealer->createToken('qa-rename-only')->accessToken;
        $before = $dealer->fresh()->getRawOriginal();
        $chosenRevision = (string) Str::uuid();
        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => $dealer->id, 'distributor_name' => '新名称', 'expected_distributor_revision' => null,
            'is_distributor' => false, 'is_admin' => true, 'banned' => true,
            'balance' => 99999, 'commission_balance' => 99999, 'password' => 'different-password',
            'distributor_revision' => $chosenRevision,
        ])->assertOk();
        $after = $dealer->fresh()->getRawOriginal();
        $this->assertSame('新名称', $after['distributor_name']);
        $this->assertTrue(Str::isUuid($after['distributor_revision']));
        $this->assertNotSame($chosenRevision, $after['distributor_revision']);
        foreach (['distributor_name', 'distributor_revision', 'updated_at'] as $key) {
            unset($before[$key], $after[$key]);
        }
        $this->assertSame($before, $after);
        $this->assertNotNull($session->fresh());
    }

    public function test_guest_customer_distributor_and_staff_cannot_rename(): void
    {
        $dealer = $this->user('qa-auth-target@example.com', '受保护商户');
        $before = $dealer->fresh()->getRawOriginal();
        $payload = ['id' => $dealer->id, 'distributor_name' => '越权名称', 'expected_distributor_revision' => null];
        $uri = $this->endpoint('renameDistributor');
        $this->postJson($uri, $payload)->assertForbidden();
        $customer = $this->user('qa-auth-customer@example.com');
        $staff = $this->user('qa-auth-staff@example.com');
        $staff->update(['is_staff' => true]);
        foreach ([$customer, $dealer, $staff] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson($uri, $payload)->assertForbidden();
            $this->assertSame($before, $dealer->fresh()->getRawOriginal());
        }
    }

    public function test_missing_customer_and_internal_subscription_targets_cannot_be_renamed(): void
    {
        $this->admin();
        $dealer = $this->user('qa-scope-dealer@example.com', '分销商');
        $customer = $this->user('qa-scope-customer@example.com');
        $subscriber = $this->order($dealer)->distributorOrder()->firstOrFail()->subscriber;
        // Even an inconsistent internal account with the distributor flag must stay excluded.
        $subscriber->update(['is_distributor' => true, 'distributor_name' => '内部账号']);
        foreach ([$customer, $subscriber] as $target) {
            $before = $target->fresh()->getRawOriginal();
            $this->postJson($this->endpoint('renameDistributor'), [
                'id' => $target->id, 'distributor_name' => '错误目标',
                'expected_distributor_revision' => $target->fresh()->distributor_revision,
            ])->assertStatus(409);
            $this->assertSame($before, $target->fresh()->getRawOriginal());
        }
        $this->postJson($this->endpoint('renameDistributor'), [
            'id' => 2147483647, 'distributor_name' => '不存在', 'expected_distributor_revision' => null,
        ])->assertStatus(409);
    }

    private function endpoint(string $method): string
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->getActionName() === UserController::class . '@update'
        );
        $this->assertNotNull($route);
        $suffix = $method === 'renameDistributor' ? 'distributor/rename' : $method;

        return preg_replace('#/update$#', '/' . $suffix, '/' . $route->uri());
    }

    private function admin(): User
    {
        $admin = $this->user('qa-cas-admin@example.com');
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function user(string $email, ?string $name = null): User
    {
        return User::create([
            'email' => $email, 'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
            'is_distributor' => $name !== null, 'distributor_name' => $name,
            'is_admin' => false, 'is_staff' => false, 'banned' => false,
        ]);
    }

    private function order(User $dealer): Order
    {
        $plan = Plan::create([
            'group_id' => 1, 'transfer_enable' => 30, 'name' => 'QA concurrency plan',
            'show' => true, 'sell' => true, 'renew' => true, 'sort' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 30], 'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);

        return app(DistributorOrderService::class)->create($dealer, $plan, Plan::PERIOD_MONTHLY, 'QA customer');
    }
}
