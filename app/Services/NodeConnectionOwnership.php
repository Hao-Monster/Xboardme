<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class NodeConnectionOwnership
{
    public const LEASE_SECONDS = 180;
    public const REPLACEMENT_CHANNEL = 'node:connection-replaced';

    private const OWNER_PREFIX = 'node:connection-owner:';
    private const MACHINE_OWNER_PREFIX = 'node:machine-connection-owner:';
    private const ENABLED_KEY = 'node:connection-ownership-enabled';
    private const STRICT_KEY = 'node:connection-ownership-strict';

    private const CLAIM_SCRIPT = <<<'LUA'
for _, key in ipairs(KEYS) do
    redis.call('SET', key, ARGV[1], 'EX', ARGV[2])
end
return #KEYS
LUA;

    private const RELEASE_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end
return redis.call('DEL', KEYS[1])
LUA;

    private const RENEW_SCRIPT = <<<'LUA'
local renewed = 0
for _, key in ipairs(KEYS) do
    if redis.call('GET', key) == ARGV[1] then
        redis.call('EXPIRE', key, ARGV[2])
        renewed = renewed + 1
    end
end
return renewed
LUA;

    private const VERIFY_SCRIPT = <<<'LUA'
for _, key in ipairs(KEYS) do
    if redis.call('GET', key) ~= ARGV[1] then
        return 0
    end
end
return 1
LUA;

    private const CLAIM_MACHINE_NODES_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end
redis.call('EXPIRE', KEYS[1], ARGV[2])
for index = 2, #KEYS do
    redis.call('SET', KEYS[index], ARGV[1], 'EX', ARGV[2])
end
return 1
LUA;

    public function newConnectionId(): string
    {
        $revision = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) config('app.version', 'local'));

        return ($revision ?: 'local') . ':' . bin2hex(random_bytes(16));
    }

    /**
     * @param int[] $nodeIds
     */
    public function claim(array $nodeIds, string $connectionId): void
    {
        $nodeIds = $this->normalizeNodeIds($nodeIds);
        if ($nodeIds === []) {
            return;
        }

        $keys = array_map(self::ownerKey(...), $nodeIds);
        Redis::command('eval', [
            self::CLAIM_SCRIPT,
            [...$keys, $connectionId, self::LEASE_SECONDS],
            count($keys),
        ]);
        Redis::set(self::ENABLED_KEY, '1');

        $this->announceNodeReplacements($nodeIds, $connectionId);
    }

    /**
     * @param int[] $nodeIds
     */
    public function claimMachine(int $machineId, array $nodeIds, string $connectionId): void
    {
        $nodeIds = $this->normalizeNodeIds($nodeIds);
        $keys = [self::machineOwnerKey($machineId), ...array_map(self::ownerKey(...), $nodeIds)];

        Redis::command('eval', [
            self::CLAIM_SCRIPT,
            [...$keys, $connectionId, self::LEASE_SECONDS],
            count($keys),
        ]);
        Redis::set(self::ENABLED_KEY, '1');
        Redis::publish(self::REPLACEMENT_CHANNEL, json_encode([
            'machine_id' => $machineId,
            'connection_id' => $connectionId,
        ], JSON_THROW_ON_ERROR));

        $this->announceNodeReplacements($nodeIds, $connectionId);
    }

    /**
     * Atomically fence a membership update against a concurrently replaced
     * machine connection before claiming the new node set.
     *
     * @param int[] $nodeIds
     */
    public function claimMachineNodesIfOwned(int $machineId, array $nodeIds, string $connectionId): bool
    {
        $nodeIds = $this->normalizeNodeIds($nodeIds);
        $keys = [self::machineOwnerKey($machineId), ...array_map(self::ownerKey(...), $nodeIds)];
        $claimed = (int) Redis::command('eval', [
            self::CLAIM_MACHINE_NODES_SCRIPT,
            [...$keys, $connectionId, self::LEASE_SECONDS],
            count($keys),
        ]) === 1;

        if ($claimed) {
            $this->announceNodeReplacements($nodeIds, $connectionId);
        }

        return $claimed;
    }

    public function releaseIfOwned(int $nodeId, string $connectionId): bool
    {
        return $this->releaseKeyIfOwned(self::ownerKey($nodeId), $connectionId);
    }

    public function releaseMachineIfOwned(int $machineId, string $connectionId): bool
    {
        return $this->releaseKeyIfOwned(self::machineOwnerKey($machineId), $connectionId);
    }

    /**
     * @param int[] $nodeIds
     */
    public function renewOwned(array $nodeIds, string $connectionId): int
    {
        $nodeIds = $this->normalizeNodeIds($nodeIds);
        if ($nodeIds === []) {
            return 0;
        }

        return $this->renewKeys(array_map(self::ownerKey(...), $nodeIds), $connectionId);
    }

    /**
     * @param int[] $nodeIds
     */
    public function ownsAll(array $nodeIds, string $connectionId): bool
    {
        $nodeIds = $this->normalizeNodeIds($nodeIds);

        return $nodeIds !== []
            && $this->verifyKeys(array_map(self::ownerKey(...), $nodeIds), $connectionId);
    }

    /**
     * @param int[] $nodeIds
     */
    public function ownsMachineAndNodes(int $machineId, array $nodeIds, string $connectionId): bool
    {
        $keys = [
            self::machineOwnerKey($machineId),
            ...array_map(self::ownerKey(...), $this->normalizeNodeIds($nodeIds)),
        ];

        return $this->verifyKeys($keys, $connectionId);
    }

    public function ownsMachine(int $machineId, string $connectionId): bool
    {
        return $this->verifyKeys([self::machineOwnerKey($machineId)], $connectionId);
    }

    /**
     * @param int[] $nodeIds
     */
    public function renewMachineAndNodes(int $machineId, array $nodeIds, string $connectionId): bool
    {
        $keys = [
            self::machineOwnerKey($machineId),
            ...array_map(self::ownerKey(...), $this->normalizeNodeIds($nodeIds)),
        ];

        return $this->renewKeys($keys, $connectionId) === count($keys);
    }

    public function ownershipEnabled(): bool
    {
        return (bool) Redis::exists(self::ENABLED_KEY);
    }

    public function strictModeEnabled(): bool
    {
        return (bool) Redis::exists(self::STRICT_KEY);
    }

    public function enableStrictMode(): void
    {
        if (!$this->ownershipEnabled()) {
            throw new \LogicException('Connection ownership has not been initialized.');
        }
        Redis::set(self::STRICT_KEY, '1');
    }

    public function disableStrictMode(): void
    {
        Redis::del(self::STRICT_KEY);
    }

    public function hasActiveOwner(int $nodeId): bool
    {
        return Redis::get(self::ownerKey($nodeId)) !== null;
    }

    public static function ownerKey(int $nodeId): string
    {
        return self::OWNER_PREFIX . $nodeId;
    }

    public static function machineOwnerKey(int $machineId): string
    {
        return self::MACHINE_OWNER_PREFIX . $machineId;
    }

    /**
     * @param string[] $keys
     */
    private function renewKeys(array $keys, string $connectionId): int
    {
        return (int) Redis::command('eval', [
            self::RENEW_SCRIPT,
            [...$keys, $connectionId, self::LEASE_SECONDS],
            count($keys),
        ]);
    }

    /**
     * @param string[] $keys
     */
    private function verifyKeys(array $keys, string $connectionId): bool
    {
        return (int) Redis::command('eval', [
            self::VERIFY_SCRIPT,
            [...$keys, $connectionId],
            count($keys),
        ]) === 1;
    }

    private function releaseKeyIfOwned(string $key, string $connectionId): bool
    {
        return (int) Redis::command('eval', [
            self::RELEASE_SCRIPT,
            [$key, $connectionId],
            1,
        ]) === 1;
    }

    /**
     * @param int[] $nodeIds
     */
    private function announceNodeReplacements(array $nodeIds, string $connectionId): void
    {
        if ($nodeIds === []) {
            return;
        }

        Redis::publish(self::REPLACEMENT_CHANNEL, json_encode([
            'node_ids' => array_values($nodeIds),
            'connection_id' => $connectionId,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param int[] $nodeIds
     * @return int[]
     */
    private function normalizeNodeIds(array $nodeIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $nodeIds),
            static fn (int $nodeId): bool => $nodeId > 0
        )));
    }
}
