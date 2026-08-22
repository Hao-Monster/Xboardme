<?php

namespace Tests\Unit;

use App\Services\NodeConnectionOwnership;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NodeConnectionOwnershipTest extends TestCase
{
    public function test_claim_uses_a_ttl_and_announces_the_replacement(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                return $command === 'eval'
                    && str_contains($arguments[0], "redis.call('SET'")
                    && $arguments[1][0] === 'node:connection-owner:17'
                    && $arguments[1][1] === 'release:connection'
                    && $arguments[1][2] === NodeConnectionOwnership::LEASE_SECONDS
                    && $arguments[2] === 1;
            })
            ->andReturn([false]);
        Redis::shouldReceive('set')
            ->once()
            ->with('node:connection-ownership-enabled', '1')
            ->andReturnTrue();
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function (string $channel, string $payload): bool {
                $decoded = json_decode($payload, true);

                return $channel === NodeConnectionOwnership::REPLACEMENT_CHANNEL
                    && $decoded === ['node_ids' => [17], 'connection_id' => 'release:connection'];
            })
            ->andReturn(1);

        app(NodeConnectionOwnership::class)->claim([17], 'release:connection');
    }

    public function test_release_only_succeeds_for_the_current_owner(): void
    {
        Redis::shouldReceive('command')
            ->twice()
            ->with('eval', \Mockery::type('array'))
            ->andReturn(0, 1);

        $ownership = app(NodeConnectionOwnership::class);

        $this->assertFalse($ownership->releaseIfOwned(17, 'stale'));
        $this->assertTrue($ownership->releaseIfOwned(17, 'current'));
    }

    public function test_machine_claim_fences_old_machine_and_node_connections(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                return $command === 'eval'
                    && $arguments[1] === [
                        'node:machine-connection-owner:7',
                        'node:connection-owner:17',
                        'node:connection-owner:18',
                        'release:machine-connection',
                        NodeConnectionOwnership::LEASE_SECONDS,
                    ]
                    && $arguments[2] === 3;
            })
            ->andReturn([false, false, false]);
        Redis::shouldReceive('set')->once()->andReturnTrue();
        Redis::shouldReceive('publish')
            ->twice()
            ->with(NodeConnectionOwnership::REPLACEMENT_CHANNEL, \Mockery::type('string'))
            ->andReturn(1);

        app(NodeConnectionOwnership::class)->claimMachine(7, [17, 18], 'release:machine-connection');
    }

    public function test_renewal_reports_how_many_nodes_are_still_owned(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                return $command === 'eval'
                    && $arguments[1] === [
                        'node:connection-owner:17',
                        'node:connection-owner:18',
                        'release:connection',
                        NodeConnectionOwnership::LEASE_SECONDS,
                    ]
                    && $arguments[2] === 2;
            })
            ->andReturn(1);

        $this->assertSame(1, app(NodeConnectionOwnership::class)->renewOwned([17, 18], 'release:connection'));
    }

    public function test_message_ownership_check_does_not_extend_the_lease(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                return $command === 'eval'
                    && str_contains($arguments[0], "redis.call('GET'")
                    && !str_contains($arguments[0], "redis.call('EXPIRE'")
                    && $arguments[1] === [
                        'node:machine-connection-owner:7',
                        'node:connection-owner:18',
                        'release:connection',
                    ]
                    && $arguments[2] === 2;
            })
            ->andReturn(1);

        $this->assertTrue(
            app(NodeConnectionOwnership::class)->ownsMachineAndNodes(7, [18], 'release:connection')
        );
    }

    public function test_machine_membership_claim_is_conditioned_on_the_current_machine_owner(): void
    {
        Redis::shouldReceive('command')
            ->once()
            ->withArgs(function (string $command, array $arguments): bool {
                return $command === 'eval'
                    && str_contains($arguments[0], "redis.call('GET', KEYS[1])")
                    && $arguments[1] === [
                        'node:machine-connection-owner:7',
                        'node:connection-owner:18',
                        'node:connection-owner:19',
                        'release:connection',
                        NodeConnectionOwnership::LEASE_SECONDS,
                    ]
                    && $arguments[2] === 3;
            })
            ->andReturn(0);
        Redis::shouldReceive('publish')->never();

        $this->assertFalse(
            app(NodeConnectionOwnership::class)->claimMachineNodesIfOwned(
                7,
                [18, 19],
                'release:connection'
            )
        );
    }
}
