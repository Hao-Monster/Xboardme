<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use App\Http\Middleware\InitializePlugins;
use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Services\Diagnostics\DiagnosticContract;
use App\Services\Diagnostics\OrderDiagnosticIdentityService;
use App\Services\DistributorOrderService;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDiagnosticRedis;
use Tests\TestCase;

final class OrderDiagnosticsIdentityAndSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeDiagnosticRedis $fakeRedis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Cache::flush();

        $this->fakeRedis = (new FakeDiagnosticRedis())->install();
    }

    public function test_t01_panel_scope_initialization_is_one_way_and_cannot_be_overwritten_via_config_save(): void
    {
        $admin = $this->makeUser('admin-scope@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);

        $initUri = $this->adminUri('diagnostics/initialize');

        $first = $this->postJson($initUri)->assertOk();
        $first->assertJsonPath('status', 'success');
        $this->assertTrue((bool) $first->json('data.created'));
        $panelScope = (string) $first->json('data.panel_scope');
        $this->assertTrue(DiagnosticContract::isValidPanelScope($panelScope));

        $second = $this->postJson($initUri)->assertOk();
        $this->assertFalse((bool) $second->json('data.created'));
        $this->assertSame($panelScope, $second->json('data.panel_scope'));

        // Attempting to overwrite diagnostics_panel_scope via admin config save must be rejected
        $configSaveUri = $this->adminUri('config/save');
        $this->postJson($configSaveUri, [
            OrderDiagnosticIdentityService::SETTING_PANEL_SCOPE => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        ])->assertStatus(422);

        $persisted = SettingModel::query()
            ->where('name', OrderDiagnosticIdentityService::SETTING_PANEL_SCOPE)
            ->value('value');
        $this->assertSame($panelScope, $persisted);
    }

    public function test_t01_t03_initial_scope_creation_notifies_online_nodes_and_exposes_diagnostic_id_in_available_users(): void
    {
        $admin = $this->makeUser('admin-sync@example.com', false, ['is_admin' => true]);
        $server = $this->makeServer(['group_ids' => ['1']]);
        $subscriber = $this->makeUser('subscriber-sync@example.com', false, [
            'group_id' => 1,
            'transfer_enable' => 10737418240,
            'u' => 0,
            'd' => 0,
            'expired_at' => time() + 86400,
        ]);

        // Before panel_scope initialization, diagnostic_id is not present on available users
        $usersBefore = ServerService::getAvailableUsers($server);
        $this->assertCount(1, $usersBefore);
        $this->assertNull($usersBefore[0]->diagnostic_id ?? null);

        // Mark node online via compatibility WS heartbeat key so initialize pushes sync.users
        Cache::put("node_ws_alive:{$server->id}", true, 300);

        Sanctum::actingAs($admin);
        $res = $this->postJson($this->adminUri('diagnostics/initialize'))->assertOk();
        $panelScope = (string) $res->json('data.panel_scope');

        // Verify Redis publish on node:push was triggered once for sync.users
        $syncUsersPublishes = array_values(array_filter(
            $this->fakeRedis->published,
            static fn (array $item): bool => $item['channel'] === 'node:push'
        ));
        $this->assertCount(1, $syncUsersPublishes);

        // Subsequent initialize does not re-trigger hot sync
        $this->postJson($this->adminUri('diagnostics/initialize'))->assertOk();
        $syncUsersPublishesAfter = array_values(array_filter(
            $this->fakeRedis->published,
            static fn (array $item): bool => $item['channel'] === 'node:push'
        ));
        $this->assertCount(1, $syncUsersPublishesAfter);

        $usersAfter = ServerService::getAvailableUsers($server);
        $this->assertCount(1, $usersAfter);
        $expectedDiagId = DiagnosticContract::computeDiagnosticId($panelScope, (int) $subscriber->id);
        $this->assertSame($expectedDiagId, $usersAfter[0]->diagnostic_id ?? null);
    }

    public function test_t02_order_lookup_resolves_standard_distributor_initial_and_renewal_orders_and_fails_closed_on_missing_subscriber(): void
    {
        $admin = $this->makeUser('admin-lookup@example.com', false, ['is_admin' => true]);
        $this->makeServer(['group_ids' => ['1']]);
        $plan = $this->makePlan();

        Sanctum::actingAs($admin);
        $panelScope = (string) $this->postJson($this->adminUri('diagnostics/initialize'))
            ->assertOk()
            ->json('data.panel_scope');

        // 1. Standard user order
        $normalUser = $this->makeUser('normal-buyer@example.com', false, [
            'group_id' => 1,
            'plan_id' => $plan->id,
            'transfer_enable' => 10737418240,
            'expired_at' => time() + 86400,
        ]);
        $normalOrder = Order::create([
            'user_id' => $normalUser->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => '20261018STD0001abc',
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $lookupUri = $this->adminUri('order/diagnostics/lookup');
        $stdRes = $this->postJson($lookupUri, ['trade_no' => '20261018STD0001abc'])->assertOk();
        $stdRes->assertJsonPath('data.order_id', $normalOrder->id);
        $stdRes->assertJsonPath('data.order_type', 'direct');
        $stdRes->assertJsonPath('data.diagnostic_id', DiagnosticContract::computeDiagnosticId($panelScope, (int) $normalUser->id));

        // Case-sensitive exact trade_no check: uppercase 'ABC' must fail with 404 order_not_found
        $this->postJson($lookupUri, ['trade_no' => '20261018STD0001ABC'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'order_not_found');

        // 2. Distributor initial order -> must resolve to subscriber_user_id, NEVER distributor user_id
        $distributor = $this->makeUser('dealer-lookup@example.com', true, ['balance' => 50000]);
        $distInitialOrder = app(DistributorOrderService::class)->create(
            $distributor,
            $plan,
            Plan::PERIOD_MONTHLY,
            '终端客户A'
        );
        $delivery = $distInitialOrder->distributorOrder()->firstOrFail();
        User::query()->whereKey($delivery->subscriber_user_id)->update(['group_id' => 1]);

        $distInitRes = $this->postJson($lookupUri, ['order_id' => $distInitialOrder->id])->assertOk();
        $distInitRes->assertJsonPath('data.order_type', 'distributor_initial');
        $expectedDistDiagId = DiagnosticContract::computeDiagnosticId($panelScope, (int) $delivery->subscriber_user_id);
        $distInitRes->assertJsonPath('data.diagnostic_id', $expectedDistDiagId);
        $this->assertNotSame(
            DiagnosticContract::computeDiagnosticId($panelScope, (int) $distributor->id),
            $distInitRes->json('data.diagnostic_id')
        );

        // 3. Distributor renewal order -> must resolve via distributor_order_id to same subscriber_user_id
        $renewalOrder = Order::create([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'distributor_order_id' => $delivery->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => '20261018RNW0002xyz',
            'total_amount' => 3000,
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_COMPLETED,
        ]);

        $distRenRes = $this->postJson($lookupUri, ['trade_no' => '20261018RNW0002xyz'])->assertOk();
        $distRenRes->assertJsonPath('data.order_type', 'distributor_renewal');
        $distRenRes->assertJsonPath('data.diagnostic_id', $expectedDistDiagId);

        // 4. Distributor order with missing subscriber mapping -> 409 subscription_user_missing fail-closed
        $brokenDistOrder = Order::create([
            'user_id' => $distributor->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => '20261018BRK0003',
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);
        $this->postJson($lookupUri, ['order_id' => $brokenDistOrder->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription_user_missing');
    }

    public function test_t12_t24_admin_endpoints_enforce_admin_auth_reject_forbidden_identity_params_and_never_expose_sensitive_fields(): void
    {
        $lookupUri = $this->adminUri('order/diagnostics/lookup');

        // Unauthenticated -> 403
        $this->postJson($lookupUri, ['order_id' => 1])->assertForbidden();

        // Non-admin user -> 403
        $member = $this->makeUser('member@example.com');
        Sanctum::actingAs($member);
        $this->postJson($lookupUri, ['order_id' => 1])->assertForbidden();

        // Admin user
        $admin = $this->makeUser('admin-sec@example.com', false, ['is_admin' => true]);
        Sanctum::actingAs($admin);
        $this->postJson($this->adminUri('diagnostics/initialize'))->assertOk();

        $plan = $this->makePlan();
        $subUser = $this->makeUser('secret-subscriber@example.com', false, [
            'group_id' => 1,
            'plan_id' => $plan->id,
        ]);
        $order = Order::create([
            'user_id' => $subUser->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => '20261018SEC0001',
            'total_amount' => 3000,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
        ]);

        foreach (['uuid', 'token', 'subscribe_url', 'email', 'user_id', 'subscriber_user_id'] as $forbiddenField) {
            $secretValue = 'forbidden-secret-value-' . $forbiddenField;
            $this->postJson($lookupUri . '?' . http_build_query([
                $forbiddenField => $secretValue,
                'email' => 'query-leak@example.com',
            ]), [
                'order_id' => $order->id,
                $forbiddenField => $secretValue,
            ])->assertStatus(422)
                ->assertJsonPath('error.code', 'forbidden_identity_parameter');

            $audit = AdminAuditLog::query()->where('action', 'order_diagnostics.lookup')->latest('id')->firstOrFail();
            $auditRaw = (string) $audit->getRawOriginal('request_data');
            $auditUri = (string) $audit->uri;
            $this->assertStringNotContainsString($secretValue, $auditRaw);
            $this->assertStringNotContainsString($forbiddenField, $auditRaw);
            $this->assertStringNotContainsString($secretValue, $auditUri);
            $this->assertStringNotContainsString($forbiddenField, $auditUri);
            $this->assertStringNotContainsString('query-leak@example.com', $auditUri);
        }

        $res = $this->postJson($lookupUri, ['order_id' => $order->id])->assertOk();
        $rawBody = (string) $res->getContent();
        $this->assertStringNotContainsString($subUser->uuid, $rawBody);
        $this->assertStringNotContainsString($subUser->token, $rawBody);
        $this->assertStringNotContainsString($subUser->email, $rawBody);
        $this->assertArrayNotHasKey('subscriber_user_id', (array) $res->json('data'));
        $this->assertArrayNotHasKey('user_id', (array) $res->json('data'));
    }

    private function adminUri(string $path): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . ltrim((string) $prefix, '/') . '/' . ltrim($path, '/');
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'group_id' => 1,
            'transfer_enable' => 30,
            'name' => 'Diagnostics Test Plan',
            'speed_limit' => 100,
            'device_limit' => 2,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 30,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'Diag Test Node',
            'type' => Server::TYPE_VLESS,
            'host' => '198.51.100.10',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeUser(string $email, bool $isDistributor = false, array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $isDistributor,
            'distributor_name' => $isDistributor ? '测试渠道商' : null,
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ], $attributes));
    }
}
