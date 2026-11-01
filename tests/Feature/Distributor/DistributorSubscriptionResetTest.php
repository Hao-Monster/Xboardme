<?php

namespace Tests\Feature\Distributor;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Http\Middleware\InitializePlugins;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorSubscriptionResetService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\TestCase;

class DistributorSubscriptionResetTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    private const RESET_URL = '/api/v1/user/distributor/subscription-reset';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        Bus::fake();
    }

    public function test_reset_replaces_credentials_and_all_devices_without_recreating_or_changing_entitlements(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        ['distributor' => $distributor, 'subscriber' => $subscriber, 'delivery' => $delivery, 'order' => $order] = $fixture;
        DB::table('v2_user')->where('id', $subscriber->id)->update([
            'u' => 123456, 'd' => 789012, 'next_reset_at' => time() + 43210,
            'speed_limit' => 57, 'device_limit' => 4,
        ]);
        $subscriber->refresh();
        $delivery->update([
            'delivery_status' => DistributorOrder::DELIVERY_CLAIMED,
            'claimed_at' => time() - 600, 'config_issued_at' => time() - 590,
            'settlement_status' => DistributorOrder::SETTLEMENT_SETTLED,
            'settled_at' => time() - 300, 'settled_by' => $distributor->id,
            'remark' => 'Preserve customer history',
        ]);
        $this->seedRegisteredHwidDevice($delivery, 'old-device-0001');
        $this->seedRegisteredHwidDevice($delivery, 'old-device-0002');
        $beforeUser = $subscriber->getRawOriginal();
        $beforeDelivery = $delivery->getRawOriginal();
        $beforeDealer = $distributor->fresh()->getRawOriginal();
        $beforeOrder = $order->fresh()->getRawOriginal();
        $usersCount = User::count();
        $ordersCount = Order::count();
        $payload = $this->payload($fixture);

        Sanctum::actingAs($distributor);
        $response = $this->postJson(self::RESET_URL, $payload)->assertOk()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.cleared_devices', 2)
            ->assertJsonPath('data.can_reset_subscription', true)
            ->assertJsonCount(0, 'data.hwid_devices');

        $subscriber->refresh();
        $delivery->refresh();
        $this->assertNotSame($beforeUser['token'], $subscriber->token);
        $this->assertNotSame($beforeUser['uuid'], $subscriber->uuid);
        $this->assertSame(hash('sha256', $subscriber->token), $response->json('data.credential_version'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('data.qr_code'));
        foreach (['id', 'plan_id', 'group_id', 'transfer_enable', 'u', 'd', 'expired_at', 'next_reset_at', 'speed_limit', 'device_limit', 'banned', 'balance'] as $field) {
            $this->assertSame($beforeUser[$field], $subscriber->getRawOriginal($field), $field);
        }
        foreach (['subscriber_user_id', 'order_id', 'customer_name', 'remark', 'subscription_code', 'subscription_name', 'hwid_enabled', 'hwid_limit', 'delivery_status', 'claimed_at', 'config_issued_at', 'settlement_status', 'settled_at', 'settled_by'] as $field) {
            $this->assertSame($beforeDelivery[$field], $delivery->getRawOriginal($field), $field);
        }
        $this->assertSame($beforeDealer, $distributor->fresh()->getRawOriginal());
        $this->assertSame($beforeOrder, $order->fresh()->getRawOriginal());
        $this->assertSame($usersCount, User::count());
        $this->assertSame($ordersCount, Order::count());
        $this->assertSame(0, $delivery->hwidDevices()->count());
        $this->assertNull($delivery->claim_token);
        $this->assertNotSame($beforeDelivery['claim_token_hash'], $delivery->claim_token_hash);
        $this->assertDatabaseHas('v2_distributor_subscription_reset', [
            'distributor_order_id' => $delivery->id, 'distributor_user_id' => $distributor->id,
            'idempotency_key' => $payload['idempotency_key'], 'previous_version' => $payload['credential_version'],
            'credential_version' => hash('sha256', $subscriber->token), 'cleared_devices' => 2,
        ]);
        $audit = json_encode(DB::table('v2_distributor_subscription_reset')->first(), JSON_THROW_ON_ERROR);
        foreach ([$beforeUser['token'], $beforeUser['uuid'], $subscriber->token, $subscriber->uuid] as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    public function test_old_links_and_pending_claim_fail_and_new_link_binds_devices_under_original_limit(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture(2);
        ['distributor' => $distributor, 'subscriber' => $subscriber, 'delivery' => $delivery] = $fixture;
        $this->makeOnlineServer();
        $oldToken = $subscriber->token;
        $oldClaim = $delivery->claim_token;
        $this->seedRegisteredHwidDevice($delivery, 'old-device-0001');
        Sanctum::actingAs($distributor);
        $this->postJson(self::RESET_URL, $this->payload($fixture))->assertOk();

        $this->withHeaders(['X-HWID' => 'old-device-0001', 'User-Agent' => 'v2rayN/7.0'])
            ->get('/api/v1/client/subscribe?token=' . urlencode($oldToken))->assertForbidden();
        $this->get('/api/v1/client/distributor/claim/' . $oldClaim)->assertStatus(410);
        $this->assertSame(0, $delivery->hwidDevices()->count());
        $newToken = $subscriber->fresh()->token;
        foreach (['new-device-0001', 'new-device-0002'] as $hwid) {
            $this->withHeaders(['X-HWID' => $hwid, 'User-Agent' => 'v2rayN/7.0'])
                ->get('/api/v1/client/subscribe?token=' . urlencode($newToken))->assertOk();
        }
        $this->withHeaders(['X-HWID' => 'new-device-0003', 'User-Agent' => 'v2rayN/7.0'])
            ->get('/api/v1/client/subscribe?token=' . urlencode($newToken))->assertNotFound();
        $this->assertSame(2, $delivery->hwidDevices()->count());
    }

    public function test_same_operation_replays_but_stale_or_superseded_operations_cannot_rotate_again(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        Sanctum::actingAs($fixture['distributor']);
        $payload = $this->payload($fixture);
        $first = $this->postJson(self::RESET_URL, $payload)->assertOk()->json('data');
        $credentials = $fixture['subscriber']->fresh()->only(['token', 'uuid']);
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'device-after-reset');
        $this->postJson(self::RESET_URL, $payload)->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.credential_version', $first['credential_version'])
            ->assertJsonPath('data.reset_at', $first['reset_at']);
        $this->assertSame($credentials, $fixture['subscriber']->fresh()->only(['token', 'uuid']));
        $this->assertSame(1, $fixture['delivery']->hwidDevices()->count());
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 1);

        $this->postJson(self::RESET_URL, array_replace($payload, ['idempotency_key' => (string) Str::uuid()]))->assertStatus(409);
        $this->postJson(self::RESET_URL, array_replace($payload, ['credential_version' => $first['credential_version']]))->assertStatus(409);
        $secondPayload = array_replace($payload, ['credential_version' => $first['credential_version'], 'idempotency_key' => (string) Str::uuid()]);
        $second = $this->postJson(self::RESET_URL, $secondPayload)->assertOk()->json('data');
        $this->postJson(self::RESET_URL, $payload)->assertStatus(409);
        $this->assertSame($second['credential_version'], hash('sha256', $fixture['subscriber']->fresh()->token));
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 2);
    }

    public function test_owner_role_and_explicit_confirmation_are_enforced_while_every_qr_order_supports_reset(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $payload = $this->payload($fixture);
        $before = $fixture['subscriber']->getRawOriginal();
        $otherDealer = $this->makeSubscriptionUser('other-reset-dealer@example.com', true);
        Sanctum::actingAs($otherDealer);
        $this->postJson(self::RESET_URL, $payload)->assertNotFound();
        Sanctum::actingAs($fixture['distributor']);
        $this->postJson(self::RESET_URL, array_replace($payload, ['confirm' => false]))->assertStatus(422);
        $this->postJson(self::RESET_URL, array_replace($payload, ['credential_version' => 'invalid']))->assertStatus(422);
        $fixture['distributor']->update(['is_distributor' => false]);
        Sanctum::actingAs($fixture['distributor']->fresh());
        $this->postJson(self::RESET_URL, $payload)->assertForbidden();
        $fixture['distributor']->update(['is_distributor' => true, 'banned' => true]);
        Sanctum::actingAs($fixture['distributor']->fresh());
        $this->postJson(self::RESET_URL, $payload)->assertForbidden();
        $this->assertSame($before, $fixture['subscriber']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 0);

        $fixture['distributor']->update(['banned' => false]);
        Sanctum::actingAs($fixture['distributor']->fresh());

        // An order with associated renewal orders keeps its QR action and supports reset; renewal child orders omit QR/renew actions.
        $renewalResponse = $this->postJson('/api/v1/user/order/renew', [
            'trade_no' => $fixture['order']->trade_no,
            'period' => 'month_price',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json('data');
        $fetchedOrders = collect($this->getJson('/api/v1/user/order/fetch')->assertOk()->json('data'))
            ->keyBy('trade_no');
        $this->assertTrue((bool) $fetchedOrders[$fixture['order']->trade_no]['can_view_subscription_qr']);
        $this->assertFalse((bool) $fetchedOrders[$renewalResponse['trade_no']]['can_view_subscription_qr']);
        $this->assertFalse((bool) $fetchedOrders[$renewalResponse['trade_no']]['is_subscription_origin']);
        $this->assertFalse((bool) $fetchedOrders[$renewalResponse['trade_no']]['can_renew']);

        $afterRenewReset = $this->postJson(self::RESET_URL, $payload)->assertOk()->json('data');
        $this->assertTrue((bool) $afterRenewReset['can_reset_subscription']);

        // Historical orders whose initial delivery popup was closed still expose QR and must also support reset.
        $fixture['delivery']->update(['delivery_status' => DistributorOrder::DELIVERY_CLOSED]);
        $this->getJson('/api/v1/user/distributor/subscription-qr?trade_no=' . $fixture['order']->trade_no)
            ->assertOk()->assertJsonPath('data.can_reset_subscription', true);
        $this->postJson(self::RESET_URL, [
            'trade_no' => $fixture['order']->trade_no,
            'credential_version' => $afterRenewReset['credential_version'],
            'idempotency_key' => (string) Str::uuid(),
            'confirm' => true,
        ])->assertOk();
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 2);
    }

    public function test_expired_exhausted_and_banned_subscribers_remain_unavailable_after_reset(): void
    {
        $server = $this->makeOnlineServer();
        foreach ([['expired_at' => time() - 60], ['u' => 50 * 1073741824], ['banned' => true]] as $overrides) {
            $fixture = $this->makeDistributorSubscriptionFixture();
            DB::table('v2_user')->where('id', $fixture['subscriber']->id)->update($overrides);
            $fixture['subscriber']->refresh();
            $before = $fixture['subscriber']->only(['transfer_enable', 'u', 'd', 'expired_at', 'next_reset_at', 'banned']);
            Sanctum::actingAs($fixture['distributor']);
            $this->postJson(self::RESET_URL, $this->payload($fixture))->assertOk();
            $subscriber = $fixture['subscriber']->fresh();
            $this->assertSame($before, $subscriber->only(array_keys($before)));
            $this->assertFalse(ServerService::getAvailableUsers($server)->contains('id', $subscriber->id));
            if (!array_key_exists('u', $overrides)) {
                $this->withHeaders(['X-HWID' => 'new-device-0001', 'User-Agent' => 'v2rayN/7.0'])
                    ->get('/api/v1/client/subscribe?token=' . urlencode($subscriber->token))->assertForbidden();
            }
        }
    }

    public function test_node_exports_etag_and_protocol_passwords_change_with_uuid_without_changing_user_id(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $server = $this->makeOnlineServer();
        $ssServer = $this->makeOnlineServer([
            'type' => Server::TYPE_SHADOWSOCKS,
            'protocol_settings' => ['cipher' => '2022-blake3-aes-128-gcm'],
        ]);
        $subscriber = $fixture['subscriber'];
        $oldUuid = $subscriber->uuid;
        $oldSsPassword = $ssServer->generateServerPassword($subscriber);
        $request = Request::create('/node/users');
        $request->attributes->set('node_info', $server);
        $controller = app(UniProxyController::class);
        $before = $controller->user($request);
        $request->headers->set('If-None-Match', $before->headers->get('ETag'));
        $this->assertSame(304, $controller->user($request)->getStatusCode());
        Sanctum::actingAs($fixture['distributor']);
        $this->postJson(self::RESET_URL, $this->payload($fixture))->assertOk();
        $subscriber->refresh();

        $after = $controller->user($request);
        $this->assertSame(200, $after->getStatusCode());
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));
        $users = json_decode($after->getContent(), true, 512, JSON_THROW_ON_ERROR)['users'];
        $this->assertSame($subscriber->id, $users[0]['id']);
        $this->assertSame($subscriber->uuid, $users[0]['uuid']);
        $this->assertStringNotContainsString($oldUuid, $after->getContent());
        $this->assertSame($subscriber->uuid, $server->generateServerPassword($subscriber));
        $this->assertNotSame($oldSsPassword, $ssServer->generateServerPassword($subscriber));
        $request->headers->set('If-None-Match', $after->headers->get('ETag'));
        $this->assertSame(304, $controller->user($request)->getStatusCode());
    }

    public function test_audit_insert_failure_rolls_back_credentials_bindings_and_claim_revocation_together(): void
    {
        $fixture = $this->makeDistributorSubscriptionFixture();
        $this->seedRegisteredHwidDevice($fixture['delivery'], 'existing-device-01');
        $beforeUser = $fixture['subscriber']->getRawOriginal();
        $beforeDelivery = $fixture['delivery']->getRawOriginal();
        $failureActive = true;
        DB::connection()->beforeExecuting(static function (string $query) use (&$failureActive): void {
            if ($failureActive && preg_match('/^insert\s+into\s+[`"]?v2_distributor_subscription_reset\b/i', $query)) {
                $failureActive = false;
                throw new \RuntimeException('reset audit fixture failure');
            }
        });
        $payload = $this->payload($fixture);
        try {
            app(DistributorSubscriptionResetService::class)->reset(
                $fixture['distributor'], $payload['trade_no'], $payload['credential_version'], $payload['idempotency_key']
            );
            $this->fail('Audit persistence failure must abort the entire reset.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reset audit fixture failure', $exception->getMessage());
        } finally {
            $failureActive = false;
        }
        $this->assertSame($beforeUser, $fixture['subscriber']->fresh()->getRawOriginal());
        $this->assertSame($beforeDelivery, $fixture['delivery']->fresh()->getRawOriginal());
        $this->assertSame(1, $fixture['delivery']->hwidDevices()->count());
        $this->assertDatabaseCount('v2_distributor_subscription_reset', 0);
    }

    public function test_non_distributor_and_banned_distributor_receive_403_before_payload_validation(): void
    {
        $regularUser = $this->makeSubscriptionUser('reset-regular-user@example.invalid', false);
        Sanctum::actingAs($regularUser);
        $this->postJson(self::RESET_URL, [])->assertForbidden();

        $bannedDistributor = $this->makeSubscriptionUser('reset-banned-dealer@example.invalid', true, ['banned' => true]);
        Sanctum::actingAs($bannedDistributor);
        $this->postJson(self::RESET_URL, [])->assertForbidden();
    }

    private function payload(array $fixture): array
    {
        return [
            'trade_no' => $fixture['order']->trade_no,
            'credential_version' => hash('sha256', $fixture['subscriber']->token),
            'idempotency_key' => (string) Str::uuid(),
            'confirm' => true,
        ];
    }
}
