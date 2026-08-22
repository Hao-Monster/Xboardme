<?php

namespace Tests\Unit;

use App\Services\NodeConnectionOwnership;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class NodeSyncServiceOwnershipTest extends TestCase
{
    public function test_rollout_mode_accepts_a_legacy_heartbeat_until_old_ws_is_retired(): void
    {
        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('ownershipEnabled')->once()->andReturnTrue();
        $ownership->shouldReceive('hasActiveOwner')->once()->with(17)->andReturnFalse();
        $ownership->shouldReceive('strictModeEnabled')->once()->andReturnFalse();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);
        Cache::shouldReceive('get')->once()->with('node_ws_alive:17')->andReturnTrue();

        $this->assertTrue(NodeSyncService::isNodeOnline(17));
    }

    public function test_strict_mode_rejects_a_node_without_an_active_owner(): void
    {
        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('ownershipEnabled')->once()->andReturnTrue();
        $ownership->shouldReceive('hasActiveOwner')->once()->with(17)->andReturnFalse();
        $ownership->shouldReceive('strictModeEnabled')->once()->andReturnTrue();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);
        Cache::shouldReceive('get')->never();

        $this->assertFalse(NodeSyncService::isNodeOnline(17));
    }

    public function test_active_owner_is_authoritative_in_both_rollout_modes(): void
    {
        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('ownershipEnabled')->once()->andReturnTrue();
        $ownership->shouldReceive('hasActiveOwner')->once()->with(17)->andReturnTrue();
        $ownership->shouldReceive('strictModeEnabled')->never();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);
        Cache::shouldReceive('get')->never();

        $this->assertTrue(NodeSyncService::isNodeOnline(17));
    }
}
