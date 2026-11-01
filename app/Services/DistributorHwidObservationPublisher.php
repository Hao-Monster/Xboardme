<?php

namespace App\Services;

use App\Jobs\UpdateDistributorHwidObservation;
use App\Support\SubscriptionTiming;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DistributorHwidObservationPublisher
{
    public const RESULT_PUBLISHED = 'published';
    public const RESULT_FAILED = 'publish_failed';
    public const RESULT_UNKNOWN = 'unknown';

    /**
     * Enqueue a scalar HWID observation onto the dedicated short-timeout Redis queue.
     * Never falls back to synchronous database writes and never rethrows.
     *
     * @param array{
     *     device_id: int,
     *     distributor_order_id: int,
     *     hwid: string,
     *     observed_at: int,
     *     device_os?: string|null,
     *     os_version?: string|null,
     *     device_model?: string|null,
     *     user_agent?: string|null,
     *     ip?: string|null
     * } $observation
     * @return array{result: string, error_category: string|null}
     */
    public function publish(array $observation, ?SubscriptionTiming $timing = null): array
    {
        $run = function () use ($observation): array {
            $connectionName = (string) config(
                'subscription.hwid_observation_publish_connection',
                'hwid_observation_publish'
            );
            $queueName = (string) config(
                'subscription.hwid_observation_queue',
                'hwid_observation'
            );

            try {
                $job = UpdateDistributorHwidObservation::fromObservation($observation);
                Queue::connection($connectionName)->push($job, '', $queueName);

                return [
                    'result' => self::RESULT_PUBLISHED,
                    'error_category' => null,
                ];
            } catch (Throwable $exception) {
                self::purgePublishRedisConnection($connectionName);

                if (self::isAckAmbiguous($exception)) {
                    return [
                        'result' => self::RESULT_UNKNOWN,
                        'error_category' => 'redis_ack_lost',
                    ];
                }

                return [
                    'result' => self::RESULT_FAILED,
                    'error_category' => 'redis_publish_failed',
                ];
            }
        };

        $outcome = $timing !== null
            ? $timing->measure(SubscriptionTiming::STAGE_OBSERVATION_PUBLISH, $run)
            : $run();

        $timing?->setPublishResult($outcome['result'], $outcome['error_category']);

        return $outcome;
    }

    private static function purgePublishRedisConnection(string $queueConnectionName): void
    {
        try {
            $redisConnectionName = (string) config(
                "queue.connections.{$queueConnectionName}.connection",
                'hwid_observation_publish'
            );
            $root = Redis::getFacadeRoot();
            if ($root instanceof RedisManager) {
                $root->purge($redisConnectionName);
            }
        } catch (Throwable) {
            // Best-effort eviction of poisoned socket in long-lived Octane workers; never throw.
        }
    }

    private static function isAckAmbiguous(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
            'ack_lost',
            'read error on connection',
            'server went away',
            'socket error on read',
            'error while reading line from the server',
            'error processing response from redis',
            'connection lost',
            'connection closed',
            'connection reset',
            'eof while reading',
            'read timed out',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
