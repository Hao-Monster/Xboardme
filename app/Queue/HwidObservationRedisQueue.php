<?php

namespace App\Queue;

use Illuminate\Queue\RedisQueue;
use Throwable;
use UnexpectedValueException;

class HwidObservationRedisQueue extends RedisQueue
{
    /**
     * Return the number of ready jobs for Horizon wait-time calculation
     * without inheriting Horizon's synchronous metadata event listeners.
     *
     * @param \UnitEnum|string|null $queue
     */
    public function readyNow($queue = null): int
    {
        return (int) $this->pendingSize($queue);
    }

    /**
     * Read a consistent queue snapshot while workers migrate or reserve jobs.
     *
     * @param \UnitEnum|string|null $queue
     * @return array{ready: int, reserved: int, delayed: int, total: int, oldest_created_at: int|null}
     */
    public function statusSnapshot($queue = null): array
    {
        $key = $this->getQueueRedisKey($queue);
        $connection = $this->getConnection();
        try {
            $connection->command('multi');
            $connection->command('llen', [$key]);
            $connection->command('zcard', [$key . ':reserved']);
            $connection->command('zcard', [$key . ':delayed']);
            $connection->command('lindex', [$key, 0]);
            $snapshot = $connection->command('exec');
        } catch (Throwable $exception) {
            try {
                $connection->command('discard');
            } catch (Throwable) {
                // Preserve the original inspection error if the connection was lost.
            }
            throw $exception;
        }

        if (!is_array($snapshot) || !array_is_list($snapshot) || count($snapshot) !== 4) {
            throw new UnexpectedValueException('Unable to read HWID observation queue snapshot.');
        }
        foreach ([0, 1, 2] as $index) {
            if (!is_int($snapshot[$index]) || $snapshot[$index] < 0) {
                throw new UnexpectedValueException('Unable to read HWID observation queue snapshot.');
            }
        }

        $oldestCreatedAt = null;
        if ($snapshot[0] > 0) {
            $payload = is_string($snapshot[3]) ? json_decode($snapshot[3], true) : null;
            if (!is_array($payload) || !isset($payload['createdAt']) || !is_numeric($payload['createdAt'])) {
                throw new UnexpectedValueException('Unable to decode oldest pending job metadata.');
            }
            $oldestCreatedAt = (int) $payload['createdAt'];
        }

        return [
            'ready' => $snapshot[0],
            'reserved' => $snapshot[1],
            'delayed' => $snapshot[2],
            'total' => $snapshot[0] + $snapshot[1] + $snapshot[2],
            'oldest_created_at' => $oldestCreatedAt,
        ];
    }

    /**
     * Return the creation timestamp of the oldest pending job, or null when empty.
     * Throws a safe exception if a pending entry exists but cannot be JSON-decoded.
     *
     * @param \UnitEnum|string|null $queue
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        $payload = $this->getConnection()->lindex($this->getQueueRedisKey($queue), 0);

        if ($payload === false || $payload === null || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['createdAt']) || !is_numeric($decoded['createdAt'])) {
            throw new UnexpectedValueException('Unable to decode oldest pending job metadata.');
        }

        return (int) $decoded['createdAt'];
    }
}
