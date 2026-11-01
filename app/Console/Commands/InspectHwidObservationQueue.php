<?php

namespace App\Console\Commands;

use App\Queue\HwidObservationRedisQueue;
use App\Support\HwidObservationConfigValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

class InspectHwidObservationQueue extends Command
{
    protected $signature = 'subscription:hwid-queue-status
        {--require-empty : Require ready, reserved, and delayed sets to be zero across consecutive samples}
        {--log : Emit structured subscription.hwid_queue log entry}
        {--samples=3 : Number of consecutive empty samples required with --require-empty}
        {--interval=2 : Seconds between consecutive samples with --require-empty}';

    protected $description = 'Inspect the dedicated HWID observation Redis queue without deserializing job payloads';

    public function handle(): int
    {
        $workerConnection = (string) config(
            'subscription.hwid_observation_worker_connection',
            'hwid_observation_worker'
        );
        $publishConnection = (string) config(
            'subscription.hwid_observation_publish_connection',
            'hwid_observation_publish'
        );
        $queueName = (string) config('subscription.hwid_observation_queue', 'hwid_observation');
        $requireEmpty = (bool) $this->option('require-empty');
        $emitLog = (bool) $this->option('log');
        $requiredSamples = max(1, (int) $this->option('samples'));
        $intervalSeconds = max(0, (int) $this->option('interval'));

        try {
            HwidObservationConfigValidator::assertQueueParity();
            $queue = Queue::connection($workerConnection);
            if (!$queue instanceof HwidObservationRedisQueue) {
                throw new InvalidArgumentException('Worker connection did not resolve to HwidObservationRedisQueue.');
            }

            $samplesChecked = 0;
            $snapshot = null;

            $iterations = $requireEmpty ? $requiredSamples : 1;
            for ($i = 0; $i < $iterations; $i++) {
                if ($i > 0 && $intervalSeconds > 0) {
                    sleep($intervalSeconds);
                }

                $snapshot = $this->readSnapshot($queue, $queueName, $publishConnection, $workerConnection);
                $samplesChecked++;

                if ($requireEmpty && $snapshot['total'] > 0) {
                    $payload = array_merge($snapshot, [
                        'status' => 'not_empty',
                        'samples_checked' => $samplesChecked,
                        'required_samples' => $requiredSamples,
                    ]);
                    $this->emitOptionalLog($emitLog, $payload, isError: false);
                    $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

                    return self::FAILURE;
                }
            }

            /** @var array<string, mixed> $snapshot */
            $payload = array_merge($snapshot, [
                'status' => 'ok',
                'samples_checked' => $samplesChecked,
            ]);
            if ($requireEmpty) {
                $payload['required_samples'] = $requiredSamples;
            }

            $this->emitOptionalLog($emitLog, $payload, isError: false);
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $errorCategory = match (true) {
                $exception instanceof UnexpectedValueException => 'decode_failed',
                $exception instanceof InvalidArgumentException => 'config_invalid',
                default => 'queue_unavailable',
            };

            $errorPayload = [
                'status' => 'error',
                'connection' => $workerConnection,
                'queue' => $queueName,
                'error_category' => $errorCategory,
                'exception_class' => get_class($exception),
            ];

            $this->emitOptionalLog($emitLog, $errorPayload, isError: true);
            $this->line((string) json_encode($errorPayload, JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readSnapshot(
        HwidObservationRedisQueue $queue,
        string $queueName,
        string $publishConnection,
        string $workerConnection
    ): array {
        $snapshot = $queue->statusSnapshot($queueName);
        $ready = $snapshot['ready'];
        $reserved = $snapshot['reserved'];
        $delayed = $snapshot['delayed'];
        $total = $snapshot['total'];
        $oldestCreatedAt = $snapshot['oldest_created_at'];
        $oldestAgeSeconds = $oldestCreatedAt === null
            ? null
            : max(0, time() - $oldestCreatedAt);

        $maxAgeThreshold = (int) config('subscription.hwid_observation_alert_oldest_ready_seconds', 60);
        $maxBacklogThreshold = (int) config('subscription.hwid_observation_alert_max_backlog', 10000);
        $thresholdExceeded = ($oldestAgeSeconds !== null && $oldestAgeSeconds > $maxAgeThreshold)
            || ($total > $maxBacklogThreshold);

        $failedCounts = $this->readFailedJobCounts($queueName, [$publishConnection, $workerConnection]);

        return [
            'connection' => $workerConnection,
            'queue' => $queueName,
            'ready' => $ready,
            'reserved' => $reserved,
            'delayed' => $delayed,
            'total' => $total,
            'oldest_ready_age_seconds' => $oldestAgeSeconds,
            'threshold_exceeded' => $thresholdExceeded,
            'failed_jobs_total' => $failedCounts['total'],
            'failed_jobs_by_connection' => $failedCounts['by_connection'],
        ];
    }

    /**
     * @param array<int, string> $connections
     * @return array{total: int|null, by_connection: array<string, int>|null}
     */
    private function readFailedJobCounts(string $queueName, array $connections): array
    {
        try {
            $dbConnection = (string) config('queue.failed.database', config('database.default'));
            $table = (string) config('queue.failed.table', 'failed_jobs');

            $rows = DB::connection($dbConnection)
                ->table($table)
                ->selectRaw('connection, COUNT(*) as aggregate')
                ->where('queue', $queueName)
                ->whereIn('connection', $connections)
                ->groupBy('connection')
                ->pluck('aggregate', 'connection')
                ->all();

            $byConnection = [];
            $total = 0;
            foreach ($connections as $conn) {
                $count = isset($rows[$conn]) ? (int) $rows[$conn] : 0;
                $byConnection[$conn] = $count;
                $total += $count;
            }

            return [
                'total' => $total,
                'by_connection' => $byConnection,
            ];
        } catch (Throwable) {
            return [
                'total' => null,
                'by_connection' => null,
            ];
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emitOptionalLog(bool $emitLog, array $payload, bool $isError): void
    {
        if (!$emitLog) {
            return;
        }

        $channel = (string) config('subscription.timing_log_channel', 'daily');
        try {
            if ($isError || !empty($payload['threshold_exceeded'])) {
                Log::channel($channel)->warning('subscription.hwid_queue', $payload);
            } else {
                Log::channel($channel)->info('subscription.hwid_queue', $payload);
            }
        } catch (Throwable) {
            // Never fail status inspection due to log write issues.
        }
    }
}
