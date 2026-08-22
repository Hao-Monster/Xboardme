<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeConnectionOwnership;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Services\ServerMachineCredentialService;
use App\Support\RuntimeHealthKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 55;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    public const REDIS_READY_CACHE_KEY = 'ws_server:redis_ready';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;
    private const REDIS_MAINTENANCE_INTERVAL = 5;

    private Worker $worker;
    private RedisConnectionConfig $redisConfig;
    private NodePushSubscriber $redisSubscriber;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'xboard-ws-server';
        $this->redisConfig = RedisConnectionConfig::fromLaravelConfig();
        $this->redisSubscriber = new NodePushSubscriber(
            $this->redisConfig,
            new WorkermanRedisSubscriberClientFactory(),
            $this->handleRedisMessage(...)
        );
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logFile = (string) config('app.ws_log_file', storage_path('logs/xboard-ws-server.log'));
        $logPath = dirname($logFile);
        if (!is_dir($logPath)) {
            mkdir($logPath, 0770, true);
        }
        Worker::$logFile = $logFile;
        Worker::$pidFile = (string) config('app.ws_pid_file', $logPath . '/xboard-ws-server.pid');
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        Log::info("[WS] Worker started, pid={$worker->id}");
        $this->redisSubscriber->start();
        $this->setupTimers();
    }

    private function setupTimers(): void
    {
        $refreshHealth = function (): void {
            try {
                foreach (RuntimeHealthKey::compatibilityKeys(self::HEARTBEAT_CACHE_KEY) as $key) {
                    Cache::put($key, time(), self::HEARTBEAT_TTL);
                }
                if ($this->redisSubscriber->isReady()) {
                    foreach (RuntimeHealthKey::compatibilityKeys(self::REDIS_READY_CACHE_KEY) as $key) {
                        Cache::put($key, time(), self::HEARTBEAT_TTL);
                    }
                } else {
                    foreach (RuntimeHealthKey::compatibilityKeys(self::REDIS_READY_CACHE_KEY) as $key) {
                        Cache::forget($key);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Unable to publish runtime health.', [
                    'exception' => $e::class,
                ]);
            }
        };
        $refreshHealth();
        Timer::add(self::HEARTBEAT_INTERVAL, $refreshHealth);

        Timer::add(self::REDIS_MAINTENANCE_INTERVAL, function () {
            try {
                $pong = Redis::connection('default')->ping();
                if (!in_array($pong, [true, 'PONG', '+PONG'], true)) {
                    throw new \RuntimeException('Unexpected Redis PING response.');
                }
                $this->redisSubscriber->ensureStarted();
            } catch (\Throwable $e) {
                $this->redisSubscriber->markUnavailable();
                Log::warning('[WS] Redis unavailable; node push readiness disabled.', [
                    'exception' => $e::class,
                ]);
            }
        });

        Timer::add(self::PING_INTERVAL, function () {
            $connections = [];
            foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                $conn = NodeRegistry::get($nodeId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    $connections[$oid]['connection'] = $conn;
                    $connections[$oid]['node_ids'][] = (int) $nodeId;
                }
            }

            foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    $connections[$oid]['connection'] = $conn;
                    $connections[$oid]['node_ids'] ??= [];
                }
            }

            $ownership = app(NodeConnectionOwnership::class);
            foreach ($connections as $entry) {
                $conn = $entry['connection'];
                $nodeIds = array_values(array_unique($entry['node_ids']));
                try {
                    $connectionId = NodeRegistry::connectionId($conn);
                    $machineId = (int) ($conn->machineId ?? 0);
                    $owned = $machineId > 0
                        ? $ownership->renewMachineAndNodes($machineId, $nodeIds, $connectionId)
                        : ($nodeIds === []
                            || $ownership->renewOwned($nodeIds, $connectionId) === count($nodeIds));
                    if (!$owned) {
                        $conn->close();
                        continue;
                    }
                    $conn->send(json_encode(['event' => 'ping']));
                } catch (\Throwable $e) {
                    Log::warning('[WS] Failed to renew node connection ownership.', [
                        'exception' => $e::class,
                    ]);
                }
            }
        });

        Timer::add(10, function () {
            try {
                $pendingNodeIds = Redis::spop('device:push_pending_nodes', 100);
            } catch (\Throwable $e) {
                Log::warning('[WS] Unable to read pending device pushes.', [
                    'exception' => $e::class,
                ]);
                return;
            }
            if (empty($pendingNodeIds)) {
                return;
            }

            $service = app(DeviceStateService::class);
            foreach ($pendingNodeIds as $nodeId) {
                $nodeId = (int) $nodeId;
                if (NodeRegistry::get($nodeId) !== null) {
                    NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
                }
            }
        });

        Timer::add(5, function () {
            try {
                app(DeviceStateService::class)->flushPendingUpdates();
            } catch (\Throwable $e) {
                Log::warning('[WS] Failed to flush pending device states', [
                    'exception' => $e::class,
                ]);
            }
        });
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineId)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $queryString = '';
        $authorization = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
            if (preg_match('/\r\nAuthorization:\s*Bearer\s+([^\s]+)\s*\r\n/i', $httpMessage, $matches)) {
                $authorization = $matches[1];
            }
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
            $header = (string) $httpMessage->header('authorization', '');
            if (preg_match('/^Bearer\s+([^\s]+)$/i', trim($header), $matches)) {
                $authorization = $matches[1];
            }
        }

        parse_str($queryString, $params);
        if ($authorization !== '') {
            $params['token'] = $authorization;
        }

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        // 判断认证模式
        if (!empty($params['machine_id'])) {
            $this->authenticateMachine($conn, $params);
        } else {
            $this->authenticateNode($conn, $params);
        }
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        $connectionId = app(NodeConnectionOwnership::class)->newConnectionId();
        NodeRegistry::setConnectionId($conn, $connectionId);
        try {
            app(NodeConnectionOwnership::class)->claim([$nodeId], $connectionId);
        } catch (\Throwable $e) {
            Log::warning("[WS] Node#{$nodeId} ownership claim failed", ['exception' => $e::class]);
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'service unavailable'],
            ]));
            return;
        }
        NodeRegistry::add($nodeId, $conn);
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);

        app(DeviceStateService::class)->clearAllNodeDevices($nodeId);

        Log::debug("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => ['node_id' => $nodeId],
        ]));

        NodeEventHandlers::pushFullSync($conn, $node);
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = app(ServerMachineCredentialService::class)->authenticate($machineId, $token);

        if (!$machine) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);
        $nodeIds = $nodes->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        $connectionId = app(NodeConnectionOwnership::class)->newConnectionId();
        NodeRegistry::setConnectionId($conn, $connectionId);
        try {
            app(NodeConnectionOwnership::class)->claimMachine($machineId, $nodeIds, $connectionId);
        } catch (\Throwable $e) {
            Log::warning("[WS] Machine#{$machineId} ownership claim failed", ['exception' => $e::class]);
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'service unavailable'],
            ]));
            return;
        }
        NodeRegistry::addMachine($machineId, $conn);

        // 把同一个连接注册到该机器下所有节点
        $deviceService = app(DeviceStateService::class);
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            Cache::put("node_ws_alive:{$node->id}", true, 86400);
            $deviceService->clearAllNodeDevices($node->id);
        }

        // 连接上记录所属机器和节点列表
        $conn->machineId = $machineId;
        NodeRegistry::setMachineNodeIds($conn, $nodeIds);

        Log::debug("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->send(json_encode([
            'event' => 'auth.success',
            'data' => [
                'machine_id' => $machineId,
                'node_ids' => $nodeIds,
            ],
        ]));

        // 为每个节点推送完整同步
        foreach ($nodes as $node) {
            NodeEventHandlers::pushFullSync($conn, $node);
        }
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }

        $event = $msg['event'] ?? '';
        $ownership = app(NodeConnectionOwnership::class);

        // 机器连接：从消息中读取 node_id 来分派到具体节点
        if (!empty($conn->machineId)) {
            if ($event === 'pong') {
                try {
                    if (!$ownership->ownsMachine(
                        (int) ($conn->machineId ?? 0),
                        NodeRegistry::connectionId($conn)
                    )) {
                        $conn->close();
                        return;
                    }
                } catch (\Throwable $e) {
                    Log::warning('[WS] Machine ownership check failed.', ['exception' => $e::class]);
                    return;
                }
                foreach (NodeRegistry::machineNodeIds($conn) as $nid) {
                    Cache::put("node_ws_alive:{$nid}", true, 86400);
                }
                return;
            }

            $nodeId = (int) ($msg['data']['node_id'] ?? 0);
            if ($nodeId <= 0 || !in_array($nodeId, NodeRegistry::machineNodeIds($conn), true)) {
                return;
            }
            try {
                if (!$ownership->ownsMachineAndNodes(
                    (int) ($conn->machineId ?? 0),
                    [$nodeId],
                    NodeRegistry::connectionId($conn)
                )) {
                    $conn->close();
                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Node ownership check failed for machine connection.', [
                    'exception' => $e::class,
                ]);
                return;
            }
            if (isset($this->handlers[$event])) {
                $handler = $this->handlers[$event];
                $handler($conn, $nodeId, $msg['data'] ?? []);
            }
            return;
        }

        // 旧模式：单节点
        $nodeId = $conn->nodeId ?? null;
        if ($nodeId) {
            try {
                if (!$ownership->ownsAll([(int) $nodeId], NodeRegistry::connectionId($conn))) {
                    $conn->close();
                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Node ownership check failed.', ['exception' => $e::class]);
                return;
            }
        }
        if (isset($this->handlers[$event]) && $nodeId) {
            $handler = $this->handlers[$event];
            $handler($conn, $nodeId, $msg['data'] ?? []);
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        $service = app(DeviceStateService::class);
        $ownership = app(NodeConnectionOwnership::class);
        $connectionId = NodeRegistry::connectionId($conn);

        // 机器模式：清理所有关联节点
        if (!empty($conn->machineId)) {
            $machineId = $conn->machineId ?? 'unknown';
            foreach (NodeRegistry::machineNodeIds($conn) as $nodeId) {
                NodeRegistry::remove($nodeId, $conn);
                try {
                    if ($connectionId !== '' && $ownership->releaseIfOwned((int) $nodeId, $connectionId)) {
                        Cache::forget("node_ws_alive:{$nodeId}");
                        $service->clearAllNodeDevices($nodeId);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[WS] Unable to release node connection ownership.', [
                        'node_id' => (int) $nodeId,
                        'exception' => $e::class,
                    ]);
                }
            }

            if (!empty($conn->machineId)) {
                NodeRegistry::removeMachine((int) $conn->machineId, $conn);
                try {
                    if ($connectionId !== '') {
                        $ownership->releaseMachineIfOwned((int) $conn->machineId, $connectionId);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[WS] Unable to release machine connection ownership.', [
                        'machine_id' => (int) $conn->machineId,
                        'exception' => $e::class,
                    ]);
                }
            }

            Log::debug("[WS] Machine#{$machineId} disconnected", [
                'nodes' => NodeRegistry::machineNodeIds($conn),
                'total' => NodeRegistry::count(),
                'machines' => NodeRegistry::machineCount(),
            ]);
            return;
        }

        // 旧模式：单节点
        if (!empty($conn->nodeId)) {
            $nodeId = $conn->nodeId;
            NodeRegistry::remove($nodeId, $conn);
            $affectedUserIds = [];
            try {
                if ($connectionId !== '' && $ownership->releaseIfOwned((int) $nodeId, $connectionId)) {
                    Cache::forget("node_ws_alive:{$nodeId}");
                    $affectedUserIds = $service->clearAllNodeDevices($nodeId);
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Unable to release node connection ownership.', [
                    'node_id' => (int) $nodeId,
                    'exception' => $e::class,
                ]);
            }

            Log::debug("[WS] Node#{$nodeId} disconnected", [
                'total' => NodeRegistry::count(),
                'affected_users' => count($affectedUserIds),
            ]);
        }
    }

    private function handleRedisMessage(string $channel, string $message): void
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)) {
            return;
        }

        if ($channel === $this->redisConfig->replacementChannel) {
            $machineId = (int) ($payload['machine_id'] ?? 0);
            $connectionId = (string) ($payload['connection_id'] ?? '');
            if ($machineId > 0) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn && $connectionId !== '' && NodeRegistry::connectionId($conn) !== $connectionId) {
                    $conn->close();
                }
                return;
            }

            $nodeIds = isset($payload['node_ids']) && is_array($payload['node_ids'])
                ? array_map('intval', $payload['node_ids'])
                : [(int) ($payload['node_id'] ?? 0)];
            $closedConnections = [];
            foreach ($nodeIds as $nodeId) {
                $conn = $nodeId > 0 ? NodeRegistry::get($nodeId) : null;
                if (!$conn || $connectionId === '' || NodeRegistry::connectionId($conn) === $connectionId) {
                    continue;
                }
                $objectId = spl_object_id($conn);
                if (!isset($closedConnections[$objectId])) {
                    $closedConnections[$objectId] = true;
                    $conn->close();
                }
            }
            return;
        }

        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];

        // Machine-level events (e.g., sync.nodes)
        $machineId = $payload['machine_id'] ?? null;
        if ($machineId && $event) {
            // Update server-side registry when node membership changes
            if ($event === 'sync.nodes') {
                $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                $conn = NodeRegistry::getMachine((int) $machineId);
                if (!$conn) {
                    return;
                }

                $ownership = app(NodeConnectionOwnership::class);
                $connectionId = NodeRegistry::connectionId($conn);
                try {
                    if (!$ownership->claimMachineNodesIfOwned(
                        (int) $machineId,
                        $nodeIds,
                        $connectionId
                    )) {
                        $conn->close();
                        return;
                    }

                    $oldNodeIds = NodeRegistry::machineNodeIds($conn);
                    foreach (array_diff($oldNodeIds, $nodeIds) as $removedId) {
                        if ($ownership->releaseIfOwned((int) $removedId, $connectionId)) {
                            Cache::forget("node_ws_alive:{$removedId}");
                            app(DeviceStateService::class)->clearAllNodeDevices((int) $removedId);
                        }
                    }
                    foreach (array_diff($nodeIds, $oldNodeIds) as $addedId) {
                        Cache::put("node_ws_alive:{$addedId}", true, 86400);
                        app(DeviceStateService::class)->clearAllNodeDevices((int) $addedId);
                    }
                    NodeRegistry::refreshMachineNodes((int) $machineId, $nodeIds);
                } catch (\Throwable $e) {
                    Log::warning('[WS] Unable to synchronize machine node ownership.', [
                        'machine_id' => (int) $machineId,
                        'exception' => $e::class,
                    ]);
                    return;
                }
            }

            $sent = NodeRegistry::sendMachine((int) $machineId, $event, $data);
            if ($sent) {
                Log::debug("[WS] Pushed {$event} to machine#{$machineId}");
            }
            return;
        }

        // Per-node events
        $nodeId = $payload['node_id'] ?? null;
        if (!$nodeId || !$event) {
            return;
        }

        $sent = NodeRegistry::send((int) $nodeId, $event, $data);
        if ($sent) {
            Log::debug("[WS] Pushed {$event} to node#{$nodeId}");
        }
    }
}
