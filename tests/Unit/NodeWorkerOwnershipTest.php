<?php

namespace Tests\Unit;

use App\Services\DeviceStateService;
use App\Services\NodeConnectionOwnership;
use App\Services\NodeRegistry;
use App\WebSocket\NodeWorker;
use App\WebSocket\RedisConnectionConfig;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class NodeWorkerOwnershipTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetNodeRegistry();
        parent::tearDown();
    }

    public function test_stale_connection_close_cannot_clear_the_new_owner_state(): void
    {
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        $connection->nodeId = 17;
        NodeRegistry::setConnectionId($connection, 'stale-connection');

        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('releaseIfOwned')
            ->once()
            ->with(17, 'stale-connection')
            ->andReturnFalse();
        $ownership->shouldReceive('releaseMachineIfOwned')->never();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldNotReceive('clearAllNodeDevices');
        $this->app->instance(DeviceStateService::class, $devices);

        Cache::shouldReceive('forget')->never();

        (new NodeWorker('127.0.0.1', 18076))->onClose($connection);
    }

    public function test_current_connection_close_releases_devices_and_online_state(): void
    {
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        $connection->nodeId = 17;
        NodeRegistry::setConnectionId($connection, 'current-connection');

        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('releaseIfOwned')
            ->once()
            ->with(17, 'current-connection')
            ->andReturnTrue();
        $ownership->shouldReceive('releaseMachineIfOwned')->never();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('clearAllNodeDevices')
            ->once()
            ->with(17)
            ->andReturn([42]);
        $this->app->instance(DeviceStateService::class, $devices);

        Cache::shouldReceive('forget')->once()->with('node_ws_alive:17');

        (new NodeWorker('127.0.0.1', 18076))->onClose($connection);
    }

    public function test_machine_membership_change_fences_new_nodes_before_registry_update(): void
    {
        $this->resetNodeRegistry();
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        $connection->machineId = 7;
        NodeRegistry::setMachineNodeIds($connection, [17, 18]);
        NodeRegistry::setConnectionId($connection, 'current-machine-connection');
        $connection->shouldReceive('close')->never();
        $connection->shouldReceive('send')->once();
        NodeRegistry::addMachine(7, $connection);
        NodeRegistry::add(17, $connection);
        NodeRegistry::add(18, $connection);

        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('claimMachineNodesIfOwned')
            ->once()
            ->with(7, [18, 19], 'current-machine-connection')
            ->andReturnTrue();
        $ownership->shouldReceive('releaseIfOwned')
            ->once()
            ->with(17, 'current-machine-connection')
            ->andReturnTrue();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldReceive('clearAllNodeDevices')->once()->with(17)->andReturn([]);
        $devices->shouldReceive('clearAllNodeDevices')->once()->with(19)->andReturn([]);
        $this->app->instance(DeviceStateService::class, $devices);

        Cache::shouldReceive('forget')->once()->with('node_ws_alive:17');
        Cache::shouldReceive('put')->once()->with('node_ws_alive:19', true, 86400);

        $worker = new NodeWorker('127.0.0.1', 18076);
        $method = new ReflectionMethod($worker, 'handleRedisMessage');
        $method->invoke($worker, RedisConnectionConfig::fromLaravelConfig()->pushChannel, json_encode([
            'machine_id' => 7,
            'event' => 'sync.nodes',
            'data' => ['nodes' => [['id' => 18], ['id' => 19]]],
        ], JSON_THROW_ON_ERROR));

        $this->assertNull(NodeRegistry::get(17));
        $this->assertSame($connection, NodeRegistry::get(18));
        $this->assertSame($connection, NodeRegistry::get(19));
        $this->assertSame([18, 19], NodeRegistry::machineNodeIds($connection));
    }

    public function test_machine_without_nodes_still_releases_its_machine_lease_on_close(): void
    {
        $this->resetNodeRegistry();
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        $connection->machineId = 7;
        NodeRegistry::setMachineNodeIds($connection, []);
        NodeRegistry::setConnectionId($connection, 'empty-machine-connection');
        NodeRegistry::addMachine(7, $connection);

        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('releaseIfOwned')->never();
        $ownership->shouldReceive('releaseMachineIfOwned')
            ->once()
            ->with(7, 'empty-machine-connection')
            ->andReturnTrue();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $devices = Mockery::mock(DeviceStateService::class);
        $devices->shouldNotReceive('clearAllNodeDevices');
        $this->app->instance(DeviceStateService::class, $devices);

        (new NodeWorker('127.0.0.1', 18076))->onClose($connection);

        $this->assertNull(NodeRegistry::getMachine(7));
    }

    public function test_machine_message_checks_only_its_target_node_ownership(): void
    {
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        $connection->machineId = 7;
        NodeRegistry::setMachineNodeIds($connection, [17, 18, 19]);
        NodeRegistry::setConnectionId($connection, 'current-machine-connection');
        $connection->shouldReceive('close')->never();

        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('ownsMachine')->never();
        $ownership->shouldReceive('ownsAll')->never();
        $ownership->shouldReceive('ownsMachineAndNodes')
            ->once()
            ->with(7, [18], 'current-machine-connection')
            ->andReturnTrue();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        (new NodeWorker('127.0.0.1', 18076))->onMessage($connection, json_encode([
            'event' => 'unhandled.test-event',
            'data' => ['node_id' => 18],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_batched_replacement_closes_a_multiplexed_connection_only_once(): void
    {
        $connection = Mockery::mock(TcpConnection::class)->shouldIgnoreMissing();
        NodeRegistry::setConnectionId($connection, 'stale-machine-connection');
        NodeRegistry::add(17, $connection);
        NodeRegistry::add(18, $connection);
        $connection->shouldReceive('close')->once();

        $worker = new NodeWorker('127.0.0.1', 18076);
        $method = new ReflectionMethod($worker, 'handleRedisMessage');
        $method->invoke(
            $worker,
            RedisConnectionConfig::fromLaravelConfig()->replacementChannel,
            json_encode([
                'node_ids' => [17, 18],
                'connection_id' => 'new-machine-connection',
            ], JSON_THROW_ON_ERROR)
        );
    }

    private function resetNodeRegistry(): void
    {
        $reflection = new ReflectionClass(NodeRegistry::class);
        foreach (['connections', 'machineConnections', 'connectionIds', 'machineNodeIds'] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setValue(
                null,
                in_array($propertyName, ['connectionIds', 'machineNodeIds'], true) ? null : []
            );
        }
    }
}
