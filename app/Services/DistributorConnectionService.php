<?php

namespace App\Services;

use App\Models\DistributorOrder;

class DistributorConnectionService
{
    public function recordFirstTraffic(array $server, array $traffic): void
    {
        $subscriberIds = collect($traffic)
            ->filter(fn($value) => ((float) ($value[0] ?? 0) + (float) ($value[1] ?? 0)) > 0)
            ->keys()
            ->map(fn($id) => (int) $id)
            ->filter()
            ->values();

        if ($subscriberIds->isEmpty()) {
            return;
        }

        $now = time();
        $nodeId = isset($server['id']) ? (int) $server['id'] : null;
        $nodeName = mb_substr((string) ($server['name'] ?? ($nodeId ? "节点 {$nodeId}" : '未知节点')), 0, 255);

        DistributorOrder::query()
            ->whereIn('subscriber_user_id', $subscriberIds)
            ->whereNotNull('config_issued_at')
            ->whereNull('connected_at')
            ->update([
                'connected_at' => $now,
                'connected_node_id' => $nodeId,
                'connected_node_name' => $nodeName,
                'updated_at' => $now,
            ]);
    }
}
