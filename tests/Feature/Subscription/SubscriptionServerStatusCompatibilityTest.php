<?php

namespace Tests\Feature\Subscription;

use App\Models\Server;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Support\SubscriptionTiming;
use App\Utils\CacheKey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\Support\LoopbackRespRedisServer;
use Tests\TestCase;

class SubscriptionServerStatusCompatibilityTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    private static ?LoopbackRespRedisServer $redisServer = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$redisServer = new LoopbackRespRedisServer();
        self::$redisServer->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$redisServer?->stop();
        self::$redisServer = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$redisServer?->configureApplication($this->app);
        self::$redisServer?->flushAll();
        config(['cache.default' => 'redis']);
        $this->app['cache']->forgetDriver('redis');
        self::$redisServer?->resetCommandLog();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_c01_edge_values_boundary_timestamps_zero_miss_fallback_and_cache_exception_propagation(): void
    {
        $now = 1_760_100_000;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));

        $plan = $this->makeSubscriptionPlan();
        $user = $this->makeOrdinarySubscriber($plan);

        $cases = [
            // Missing keys (null)
            ['name' => 'null-miss', 'check' => null, 'push' => null, 'online' => null, 'seed' => false],
            // Integer 0
            ['name' => 'int-zero', 'check' => 0, 'push' => 0, 'online' => 0, 'seed' => true],
            // String '0'
            ['name' => 'str-zero', 'check' => '0', 'push' => '0', 'online' => '0', 'seed' => true],
            // Boolean false
            ['name' => 'bool-false', 'check' => false, 'push' => false, 'online' => false, 'seed' => true],
            // Boundary timestamps around 300s online window: now-300, now-299, now-301
            ['name' => 'boundary-300', 'check' => $now - 300, 'push' => $now - 300, 'online' => 5, 'seed' => true],
            ['name' => 'boundary-299', 'check' => $now - 299, 'push' => $now - 299, 'online' => 5, 'seed' => true],
            ['name' => 'boundary-301', 'check' => $now - 301, 'push' => $now - 301, 'online' => 5, 'seed' => true],
        ];

        $createdIds = [];
        foreach ($cases as $idx => $spec) {
            $server = Server::create([
                'name' => $spec['name'],
                'type' => Server::TYPE_VMESS,
                'host' => "198.51.100.{$idx}",
                'port' => (string) (10000 + $idx),
                'server_port' => 10000 + $idx,
                'rate' => 1,
                'group_ids' => ['1'],
                'show' => true,
                'enabled' => true,
                'sort' => $idx,
                'protocol_settings' => [],
            ]);
            $createdIds[] = $server->id;

            if ($spec['seed']) {
                Cache::put(CacheKey::get('SERVER_VMESS_LAST_CHECK_AT', $server->id), $spec['check'], 3600);
                Cache::put(CacheKey::get('SERVER_VMESS_LAST_PUSH_AT', $server->id), $spec['push'], 3600);
                Cache::put(CacheKey::get('SERVER_VMESS_ONLINE_USER', $server->id), $spec['online'], 3600);
            }
        }

        // Baseline: fresh Server models reading directly via legacy Cache::get() without snapshot
        $legacyExpected = [];
        foreach ($createdIds as $serverId) {
            /** @var Server $raw */
            $raw = Server::query()->findOrFail($serverId);
            $raw->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key']);
            $arr = $raw->toArray();
            $legacyExpected[$serverId] = [
                'last_check_at' => $arr['last_check_at'],
                'last_push_at' => $arr['last_push_at'],
                'online' => $arr['online'],
                'is_online' => $arr['is_online'],
                'available_status' => $arr['available_status'],
                'cache_key' => $arr['cache_key'],
            ];
        }

        // Candidate: ServerService::getAvailableServers() using 1 MGET and 0 individual GETs
        self::$redisServer->resetCommandLog();
        $batchServers = ServerService::getAvailableServers($user);
        $cmdLog = self::$redisServer->commandLog();

        $mgetCmds = array_values(array_filter($cmdLog, static fn (string $c): bool => str_contains($c, ' MGET ')));
        $getCmds = array_values(array_filter($cmdLog, static fn (string $c): bool => str_contains($c, ' GET ')));
        $this->assertCount(1, $mgetCmds);
        $this->assertCount(0, $getCmds, 'Cache misses in snapshot must never trigger individual GET fallback storms.');

        $this->assertCount(count($cases), $batchServers);
        foreach ($batchServers as $row) {
            $id = $row['id'];
            $this->assertSame($legacyExpected[$id], [
                'last_check_at' => $row['last_check_at'],
                'last_push_at' => $row['last_push_at'],
                'online' => $row['online'],
                'is_online' => $row['is_online'],
                'available_status' => $row['available_status'],
                'cache_key' => $row['cache_key'],
            ], "Status formula mismatch for server case {$row['name']}");
        }

        // Cache backend exception must propagate instead of silently faking offline status
        Cache::shouldReceive('many')->once()->andThrow(new RuntimeException('simulated redis cache outage'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('simulated redis cache outage');
        ServerService::getAvailableServers($user);
    }

    public function test_c02_100_nodes_single_mget_300_keys_parent_dedup_different_type_isolation_empty_list_and_identity_mutation(): void
    {
        $plan = $this->makeSubscriptionPlan();
        $user = $this->makeOrdinarySubscriber($plan);

        // 1. Empty list -> 0 MGET, 0 GET, cache_unique_keys = 0
        self::$redisServer->resetCommandLog();
        $emptyReq = Request::create('/api/v1/client/subscribe', 'GET');
        $emptyTiming = SubscriptionTiming::attach($emptyReq);
        $emptyResult = ServerService::getAvailableServers($user, $emptyTiming);
        $this->assertSame([], $emptyResult);
        $this->assertSame([], self::$redisServer->commandLog());

        // 2. 100 independent online nodes -> 1 MGET with 300 keys, 0 GETs!
        $now = time();
        for ($i = 1; $i <= 100; $i++) {
            $this->makeOnlineServer([
                'name' => "PerfNode-{$i}",
                'type' => Server::TYPE_TROJAN,
                'port' => (string) (20000 + $i),
                'server_port' => 20000 + $i,
                'sort' => $i,
                'protocol_settings' => [
                    'server_name' => 'perf.example.com',
                    'network' => 'tcp',
                ],
            ], $now);
        }

        self::$redisServer->resetCommandLog();
        $hundredReq = Request::create('/api/v1/client/subscribe', 'GET');
        $hundredTiming = SubscriptionTiming::attach($hundredReq);
        $hundredServers = ServerService::getAvailableServers($user, $hundredTiming);
        $this->assertCount(100, $hundredServers);

        $cmds = self::$redisServer->commandLog();
        $mgets = array_values(array_filter($cmds, static fn (string $c): bool => str_contains($c, ' MGET ')));
        $gets = array_values(array_filter($cmds, static fn (string $c): bool => str_contains($c, ' GET ')));
        $this->assertCount(1, $mgets);
        $this->assertCount(0, $gets);
        // "DB:1 MGET key1 key2 ... key300" -> 2 prefix tokens + 300 keys = 302 tokens
        $this->assertCount(302, explode(' ', $mgets[0]));

        // Full ClashMeta HTTP subscription response must render all 100 Trojan proxies without strict-filter loss
        $hundredSubResp = $this->get('/api/v1/client/subscribe?token=' . $user->token, [
            'User-Agent' => 'ClashMetaForAndroid/2.10.1',
        ])->assertOk();
        $parsedHundredYaml = \Symfony\Component\Yaml\Yaml::parse((string) $hundredSubResp->getContent());
        $this->assertIsArray($parsedHundredYaml);
        $this->assertCount(100, $parsedHundredYaml['proxies'] ?? []);

        // 3. Shared parent_id + same type deduplicates keys; shared parent_id + different type keeps distinct keys
        Server::query()->delete();
        $parent = $this->makeOnlineServer(['name' => 'Parent-Vmess', 'type' => Server::TYPE_VMESS, 'show' => false], $now);
        $childSame1 = Server::create([
            'name' => 'Child-Vmess-1',
            'parent_id' => $parent->id,
            'type' => Server::TYPE_VMESS,
            'host' => '198.51.100.71',
            'port' => '443',
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ]);
        $childSame2 = Server::create([
            'name' => 'Child-Vmess-2',
            'parent_id' => $parent->id,
            'type' => Server::TYPE_VMESS,
            'host' => '198.51.100.72',
            'port' => '443',
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ]);
        $childDiffType = Server::create([
            'name' => 'Child-Vless',
            'parent_id' => $parent->id,
            'type' => Server::TYPE_VLESS,
            'host' => '198.51.100.73',
            'port' => '443',
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [],
        ]);

        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT', $parent->id), 0, 3600);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_PUSH_AT', $parent->id), 0, 3600);
        Cache::put(CacheKey::get('SERVER_VLESS_ONLINE_USER', $parent->id), 0, 3600);

        self::$redisServer->resetCommandLog();
        $dedupServers = ServerService::getAvailableServers($user);
        $this->assertCount(3, $dedupServers);

        $dedupMget = self::$redisServer->commandLog()[0];
        // 2 VMESS children share 3 keys + 1 VLESS child has 3 distinct keys = 6 unique keys (+ 2 header tokens = 8)
        $this->assertCount(8, explode(' ', $dedupMget));

        $byId = array_column($dedupServers, null, 'id');
        $this->assertSame(1, $byId[$childSame1->id]['is_online']);
        $this->assertSame(1, $byId[$childSame2->id]['is_online']);
        $this->assertSame(0, $byId[$childDiffType->id]['is_online']);

        // 4. Model identity mutation bypasses stale snapshot and falls back to Cache::get(); snapshot never serializes
        /** @var Server $model */
        $model = Server::query()->findOrFail($childSame1->id);
        $oldKey = $model->statusCacheKeyFor('ONLINE_USER');
        $model->injectStatusCacheSnapshot([$oldKey => 42]);
        $this->assertSame(42, $model->online);
        $this->assertArrayNotHasKey('statusCacheSnapshot', $model->toArray());

        // Mutate type -> key changes -> falls back to Cache::get() for VLESS (which is '0'/0, NOT 42!)
        $model->type = Server::TYPE_VLESS;
        $this->assertNotSame(42, $model->online);
        $this->assertSame(Cache::get($model->statusCacheKeyFor('ONLINE_USER')), $model->online);

        // Re-injecting replaces old snapshot completely
        $newKey = $model->statusCacheKeyFor('ONLINE_USER');
        $model->injectStatusCacheSnapshot([$newKey => 77]);
        $this->assertSame(77, $model->online);
    }

    public function test_c03_three_callers_hook_etag_dynamic_ports_and_ss2022_server_password_compatibility(): void
    {
        $plan = $this->makeSubscriptionPlan();
        $user = $this->makeOrdinarySubscriber($plan);

        // Dynamic port Shadowsocks 2022 node + standard Trojan node
        $ssServerKey = base64_encode(random_bytes(16));
        $ssNode = $this->makeOnlineServer([
            'name' => 'SS2022-Dynamic',
            'type' => Server::TYPE_SHADOWSOCKS,
            'host' => '198.51.100.80',
            'port' => '30000-30010',
            'server_port' => 30000,
            'protocol_settings' => [
                'cipher' => '2022-blake3-aes-128-gcm',
                'server_key' => $ssServerKey,
            ],
        ]);
        $trojanNode = $this->makeOnlineServer([
            'name' => 'Trojan-App',
            'type' => Server::TYPE_TROJAN,
            'host' => '198.51.100.81',
            'port' => '443',
            'server_port' => 443,
            'protocol_settings' => [
                'server_name' => 'example.com',
                'network' => 'tcp',
            ],
        ]);

        // Verify dynamic port range & SS2022 password directly on getAvailableServers
        $available = ServerService::getAvailableServers($user);
        $byId = array_column($available, null, 'id');

        $ssRow = $byId[$ssNode->id];
        $this->assertSame('30000-30010', $ssRow['ports']);
        $this->assertIsInt($ssRow['port']);
        $this->assertGreaterThanOrEqual(30000, $ssRow['port']);
        $this->assertLessThanOrEqual(30010, $ssRow['port']);
        $this->assertStringContainsString(':', $ssRow['password']);
        $this->assertNotSame($user->uuid, $ssRow['password']);
        $this->assertSame($user->uuid, $byId[$trojanNode->id]['password']);

        // Caller 1: ClientController::subscribe + client.subscribe.servers hook
        $hookObservedCount = null;
        HookManager::registerFilter('client.subscribe.servers', static function (array $servers) use (&$hookObservedCount): array {
            $hookObservedCount = count($servers);
            return $servers;
        });
        try {
            $subResp = $this->get('/api/v1/client/subscribe?token=' . $user->token, [
                'User-Agent' => 'ClashMetaForAndroid/2.10.1',
            ])->assertOk();
            $parsedSubYaml = \Symfony\Component\Yaml\Yaml::parse((string) $subResp->getContent());
            $this->assertIsArray($parsedSubYaml);
            $proxyNames = array_column((array) ($parsedSubYaml['proxies'] ?? []), 'name');
            $this->assertSame(['SS2022-Dynamic', 'Trojan-App'], $proxyNames);
        } finally {
            HookManager::remove('client.subscribe.servers');
        }
        $this->assertSame(2, $hookObservedCount);

        // Caller 2: Client\AppController::getConfig
        $appResp = $this->get('/api/v1/client/app/getConfig?token=' . $user->token);
        $appResp->assertOk();
        $this->assertStringContainsString('Trojan-App', (string) $appResp->getContent());

        // Caller 3: User\ServerController::fetch + ETag 304 Not Modified
        Sanctum::actingAs($user);
        $fetchResp = $this->getJson('/api/v1/user/server/fetch')->assertOk();
        $etag = (string) $fetchResp->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->getJson('/api/v1/user/server/fetch', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_c04_consecutive_requests_with_state_changes_and_multi_user_octane_requests_have_zero_snapshot_pollution(): void
    {
        $plan1 = $this->makeSubscriptionPlan(['group_id' => 1]);
        $plan2 = $this->makeSubscriptionPlan(['group_id' => 2]);
        $userA = $this->makeOrdinarySubscriber($plan1);
        $userB = $this->makeOrdinarySubscriber($plan2);

        $now = time();
        $node1 = $this->makeOnlineServer([
            'name' => 'Group1-Node',
            'type' => Server::TYPE_TROJAN,
            'group_ids' => ['1', '2'],
        ], $now);

        // Request 1: node1 is online
        $firstA = ServerService::getAvailableServers($userA);
        $this->assertSame(1, $firstA[0]['is_online']);
        $firstCacheKey = $firstA[0]['cache_key'];

        // Underlying Redis state changes to offline + new push timestamp
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_CHECK_AT', $node1->id), $now - 900, 3600);
        Cache::put(CacheKey::get('SERVER_TROJAN_LAST_PUSH_AT', $node1->id), $now + 15, 3600);

        // Request 2 on same app instance: reflects updated Redis state immediately, zero stale snapshot!
        $secondA = ServerService::getAvailableServers($userA);
        $this->assertSame(0, $secondA[0]['is_online']);
        $this->assertNotSame($firstCacheKey, $secondA[0]['cache_key']);

        // Request 3 for User B on same app instance: receives User B's own password, zero User A leakage!
        $firstB = ServerService::getAvailableServers($userB);
        $this->assertSame($userB->uuid, $firstB[0]['password']);
        $this->assertNotSame($secondA[0]['password'], $firstB[0]['password']);
    }
}
