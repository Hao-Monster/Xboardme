<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PruneHwidObservationFailures extends Command
{
    protected $signature = 'subscription:hwid-failures-prune
        {--execute : Execute deletion; omit for dry-run inspection}';

    protected $description = 'Prune failed HWID observation jobs older than the 7-day retention window';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $queueName = (string) config('subscription.hwid_observation_queue', 'hwid_observation');
        $connections = array_values(array_unique([
            (string) config('subscription.hwid_observation_publish_connection', 'hwid_observation_publish'),
            (string) config('subscription.hwid_observation_worker_connection', 'hwid_observation_worker'),
        ]));

        $retentionDays = max(1, (int) config('subscription.hwid_observation_failed_retention_days', 7));
        $batchSize = max(1, (int) config('subscription.hwid_observation_failed_prune_batch_size', 100));
        $maxBatches = max(1, (int) config('subscription.hwid_observation_failed_prune_max_batches', 10));
        $cutoff = Carbon::now()->subDays($retentionDays)->toDateTimeString();

        $dbConnection = (string) config('queue.failed.database', config('database.default'));
        $table = (string) config('queue.failed.table', 'failed_jobs');

        $baseQuery = fn () => DB::connection($dbConnection)
            ->table($table)
            ->where('queue', $queueName)
            ->whereIn('connection', $connections)
            ->where('failed_at', '<', $cutoff);

        $eligibleCount = (int) $baseQuery()->count();
        $maxDeletable = $batchSize * $maxBatches;

        if (!$execute) {
            $payload = [
                'dry_run' => true,
                'queue' => $queueName,
                'connections' => $connections,
                'cutoff' => $cutoff,
                'eligible_count' => $eligibleCount,
                'would_delete_count' => min($eligibleCount, $maxDeletable),
                'deleted_count' => 0,
                'batches_executed' => 0,
            ];
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $deletedCount = 0;
        $batchesExecuted = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = $baseQuery()
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $batchesExecuted++;
            $deletedCount += (int) DB::connection($dbConnection)
                ->table($table)
                ->whereIn('id', $ids)
                ->where('queue', $queueName)
                ->whereIn('connection', $connections)
                ->where('failed_at', '<', $cutoff)
                ->delete();

            if (count($ids) < $batchSize) {
                break;
            }
        }

        $payload = [
            'dry_run' => false,
            'queue' => $queueName,
            'connections' => $connections,
            'cutoff' => $cutoff,
            'eligible_count' => $eligibleCount,
            'deleted_count' => $deletedCount,
            'batches_executed' => $batchesExecuted,
        ];
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
