<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const NODE_USERS_PREFIX = 'node_device_users:';
    private const DB_PENDING_KEY = 'device:db_update_pending';
    public const ONLINE_WINDOW_SECONDS = 300;
    private const DB_THROTTLE = 10;

    private const REPLACE_NODE_DEVICES_SCRIPT = <<<'LUA'
local userKey = KEYS[1]
local nodeUsersKey = KEYS[2]
local fieldPrefix = ARGV[1]
local timestamp = ARGV[2]
local ttl = ARGV[3]
local userId = ARGV[4]

for _, field in ipairs(redis.call('HKEYS', userKey)) do
    if string.sub(field, 1, string.len(fieldPrefix)) == fieldPrefix then
        redis.call('HDEL', userKey, field)
    end
end

if #ARGV > 4 then
    for i = 5, #ARGV do
        redis.call('HSET', userKey, ARGV[i], timestamp)
    end
    redis.call('EXPIRE', userKey, ttl)
    redis.call('SADD', nodeUsersKey, userId)
else
    redis.call('SREM', nodeUsersKey, userId)
end

return 1
LUA;

    private const REMOVE_DUE_DB_UPDATE_SCRIPT = <<<'LUA'
local score = redis.call('ZSCORE', KEYS[1], ARGV[1])
if score and tonumber(score) <= tonumber(ARGV[2]) then
    return redis.call('ZREM', KEYS[1], ARGV[1])
end
return 0
LUA;

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $key = self::PREFIX . $userId;
        $timestamp = time();

        // Normalize: strip port suffix and deduplicate
        $ips = array_slice(array_values(array_unique(array_filter(
            array_map([self::class, 'normalizeIP'], array_filter($ips, 'is_string')),
            fn(string $ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ))), 0, 64);

        $fields = array_map(fn(string $ip) => "{$nodeId}:{$ip}", $ips);
        Redis::command('eval', [
            self::REPLACE_NODE_DEVICES_SCRIPT,
            [
                $key,
                self::NODE_USERS_PREFIX . $nodeId,
                "{$nodeId}:",
                $timestamp,
                self::ONLINE_WINDOW_SECONDS,
                $userId,
                ...$fields,
            ],
            2,
        ]);

        $this->notifyUpdate($userId);
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getNodeDevices(int $nodeId): array
    {
        $userIds = Redis::smembers(self::NODE_USERS_PREFIX . $nodeId);
        $prefix = "{$nodeId}:";
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $uid = (int) $userId;
            $data = Redis::hgetall(self::PREFIX . $uid);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix) && $now - (int) $timestamp <= self::ONLINE_WINDOW_SECONDS) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uid][] = $ip;
                }
            }
            if (!isset($result[$uid])) {
                Redis::srem(self::NODE_USERS_PREFIX . $nodeId, $uid);
            }
        }

        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        Redis::command('eval', [
            self::REPLACE_NODE_DEVICES_SCRIPT,
            [
                self::PREFIX . $userId,
                self::NODE_USERS_PREFIX . $nodeId,
                "{$nodeId}:",
                time(),
                self::ONLINE_WINDOW_SECONDS,
                $userId,
            ],
            2,
        ]);
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $userIds = array_map('intval', Redis::smembers(self::NODE_USERS_PREFIX . $nodeId));

        foreach ($userIds as $userId) {
            $this->removeNodeDevices($nodeId, $userId);
            $this->notifyUpdate($userId);
        }

        Redis::del(self::NODE_USERS_PREFIX . $nodeId);

        return $userIds;
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - (int) $timestamp <= self::ONLINE_WINDOW_SECONDS) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return count(array_unique($ips));
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - (int) $timestamp <= self::ONLINE_WINDOW_SECONDS) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $result[$userId] = array_unique($ips);
                }
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * notify update (throttle control)
     */
    public function notifyUpdate(int $userId): void
    {
        $dbThrottleKey = "device:db_throttle:{$userId}";

        if (Redis::command('set', [$dbThrottleKey, '1', ['NX', 'EX' => self::DB_THROTTLE]])) {
            $this->writeOnlineState($userId);

            return;
        }

        Redis::zadd(self::DB_PENDING_KEY, [$userId => time() + self::DB_THROTTLE]);
    }

    public function flushPendingUpdates(int $limit = 500): int
    {
        $dueAt = time();
        $userIds = Redis::zrangebyscore(
            self::DB_PENDING_KEY,
            '-inf',
            (string) $dueAt,
            ['limit' => [0, max(1, min($limit, 5000))]]
        );

        foreach ($userIds as $userId) {
            $this->notifyUpdate((int) $userId);
            Redis::command('eval', [
                self::REMOVE_DUE_DB_UPDATE_SCRIPT,
                [self::DB_PENDING_KEY, $userId, $dueAt],
                1,
            ]);
        }

        return count($userIds);
    }

    private function writeOnlineState(int $userId): void
    {
        User::query()
            ->whereKey($userId)
            ->update([
                'online_count' => $this->getDeviceCount($userId),
                'last_online_at' => now(),
            ]);
    }
}
