<?php

namespace Tests\Support;

use App\Models\DistributorHwidDevice;
use App\Models\DistributorOrder;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\DistributorOrderService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

trait BuildsSubscriptionFixtures
{
    protected function makeSubscriptionPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => 1,
            'transfer_enable' => 50,
            'name' => 'Subscription Perf Plan',
            'speed_limit' => 100,
            'device_limit' => 2,
            'distributor_hwid_limit' => 3,
            'show' => true,
            'sell' => true,
            'renew' => true,
            'sort' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 30,
                Plan::PERIOD_QUARTERLY => 80,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ], $overrides));
    }

    protected function makeSubscriptionUser(
        string $email,
        bool $isDistributor = false,
        array $overrides = []
    ): User {
        return User::create(array_merge([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_distributor' => $isDistributor,
            'distributor_name' => $isDistributor ? '测试分销商' : null,
            'is_admin' => false,
            'is_staff' => false,
            'banned' => false,
        ], $overrides));
    }

    protected function makeOrdinarySubscriber(Plan $plan, array $overrides = []): User
    {
        return $this->makeSubscriptionUser('subscriber-' . bin2hex(random_bytes(4)) . '@example.com', false, array_merge([
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => 50 * 1073741824,
            'expired_at' => time() + 86400 * 30,
        ], $overrides));
    }

    /**
     * @return array{distributor: User, plan: Plan, order: Order, delivery: DistributorOrder, subscriber: User}
     */
    protected function makeDistributorSubscriptionFixture(
        int $hwidLimit = 3,
        bool $hwidEnabled = true,
        string $customerName = '客户A'
    ): array {
        $distributor = $this->makeSubscriptionUser(
            'dealer-' . bin2hex(random_bytes(4)) . '@example.com',
            true,
            ['balance' => 100000]
        );
        $plan = $this->makeSubscriptionPlan(['distributor_hwid_limit' => $hwidLimit]);
        $order = app(DistributorOrderService::class)->create(
            $distributor,
            $plan,
            Plan::PERIOD_MONTHLY,
            $customerName
        );
        /** @var DistributorOrder $delivery */
        $delivery = $order->distributorOrder()->with('subscriber')->firstOrFail();
        if (!$hwidEnabled || (int) $delivery->hwid_limit !== $hwidLimit) {
            $delivery->update([
                'hwid_enabled' => $hwidEnabled,
                'hwid_limit' => $hwidLimit,
            ]);
            $delivery->refresh();
        }
        /** @var User $subscriber */
        $subscriber = $delivery->subscriber()->firstOrFail();

        return [
            'distributor' => $distributor,
            'plan' => $plan,
            'order' => $order,
            'delivery' => $delivery,
            'subscriber' => $subscriber,
        ];
    }

    protected function makeOnlineServer(array $overrides = [], ?int $timestamp = null): Server
    {
        $server = Server::create(array_merge([
            'name' => 'Node-' . bin2hex(random_bytes(3)),
            'type' => Server::TYPE_SOCKS,
            'host' => '198.51.100.10',
            'port' => '1080',
            'server_port' => 1080,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ], $overrides));

        $now = $timestamp ?? time();
        $idForStatus = $server->parent_id ?: $server->id;
        $typePrefix = strtoupper((string) $server->type);
        Cache::put(CacheKey::get("SERVER_{$typePrefix}_LAST_CHECK_AT", $idForStatus), $now, 3600);
        Cache::put(CacheKey::get("SERVER_{$typePrefix}_LAST_PUSH_AT", $idForStatus), $now, 3600);
        Cache::put(CacheKey::get("SERVER_{$typePrefix}_ONLINE_USER", $idForStatus), 12, 3600);

        return $server;
    }

    protected function seedRegisteredHwidDevice(
        DistributorOrder $delivery,
        string $hwid,
        array $overrides = []
    ): DistributorHwidDevice {
        $now = time();

        return DistributorHwidDevice::create(array_merge([
            'distributor_order_id' => $delivery->id,
            'hwid' => $hwid,
            'first_seen_at' => $now - 600,
            'last_seen_at' => $now - 300,
            'device_os' => 'Android',
            'os_version' => '14',
            'device_model' => 'Pixel 8',
            'user_agent' => 'ClashMetaForAndroid/2.10.1',
            'ip' => '198.51.100.50',
        ], $overrides));
    }

    /**
     * @param callable(): mixed $callback
     * @return array{result: mixed, queries: list<array{sql: string, bindings: array<int, mixed>, time: float|null}>}
     */
    protected function captureQueries(callable $callback): array
    {
        $queries = [];
        $dispatcher = DB::getEventDispatcher();
        $listener = static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = [
                'sql' => $event->sql,
                'bindings' => $event->bindings,
                'time' => $event->time,
            ];
        };

        DB::listen($listener);
        try {
            $result = $callback();
        } finally {
            if ($dispatcher !== null) {
                $dispatcher->forget(QueryExecuted::class);
            }
        }

        return ['result' => $result, 'queries' => $queries];
    }

    /**
     * @param list<array{sql: string, bindings?: array<int, mixed>}|string> $queries
     * @return list<string>
     */
    protected function filterWriteQueriesForTable(array $queries, string $table): array
    {
        $matched = [];
        $needle = strtolower($table);
        foreach ($queries as $item) {
            $sql = is_array($item) ? (string) $item['sql'] : (string) $item;
            $lower = ltrim(strtolower($sql));
            if ((str_starts_with($lower, 'update ')
                || str_starts_with($lower, 'insert ')
                || str_starts_with($lower, 'delete '))
                && str_contains($lower, $needle)
            ) {
                $matched[] = $sql;
            }
        }

        return $matched;
    }
}
