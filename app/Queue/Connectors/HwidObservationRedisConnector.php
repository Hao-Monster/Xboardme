<?php

namespace App\Queue\Connectors;

use App\Queue\HwidObservationRedisQueue;
use App\Support\HwidObservationConfigValidator;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\RedisConnector;

class HwidObservationRedisConnector extends RedisConnector
{
    /**
     * Establish a dedicated HWID observation Redis queue connection.
     *
     * @param array<string, mixed> $config
     */
    public function connect(array $config): Queue
    {
        HwidObservationConfigValidator::assertReleaseSwitchCombination();
        HwidObservationConfigValidator::assertQueueParity();

        return new HwidObservationRedisQueue(
            $this->redis,
            (string) ($config['queue'] ?? 'hwid_observation'),
            $config['connection'] ?? $this->connection,
            (int) ($config['retry_after'] ?? 90),
            $config['block_for'] ?? null,
            (bool) ($config['after_commit'] ?? false),
            (int) ($config['migration_batch_size'] ?? -1)
        );
    }
}
