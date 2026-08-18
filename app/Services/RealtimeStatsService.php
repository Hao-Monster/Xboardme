<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;

final class RealtimeStatsService
{
    /**
     * Return one internally consistent snapshot for every dashboard consumer.
     *
     * @return array{
     *     onlineDevices: int,
     *     onlineUsers: int,
     *     onlineNodes: int,
     *     windowSeconds: int,
     *     generatedAt: int
     * }
     */
    public function snapshot(): array
    {
        $generatedAt = now();
        $cutoff = $generatedAt->copy()->subSeconds(DeviceStateService::ONLINE_WINDOW_SECONDS);
        $userStats = (array) User::query()
            ->where('last_online_at', '>=', $cutoff)
            ->where('online_count', '>', 0)
            ->toBase()
            ->selectRaw('COUNT(*) as online_users, COALESCE(SUM(online_count), 0) as online_devices')
            ->first();

        return [
            'onlineDevices' => (int) ($userStats['online_devices'] ?? 0),
            'onlineUsers' => (int) ($userStats['online_users'] ?? 0),
            'onlineNodes' => Server::query()
                ->get()
                ->filter(function (Server $server) use ($generatedAt): bool {
                    $lastCheckAt = $server->last_check_at;

                    return is_numeric($lastCheckAt)
                        && (int) $lastCheckAt >= $generatedAt->timestamp - DeviceStateService::ONLINE_WINDOW_SECONDS;
                })
                ->count(),
            'windowSeconds' => DeviceStateService::ONLINE_WINDOW_SECONDS,
            'generatedAt' => $generatedAt->timestamp,
        ];
    }
}
