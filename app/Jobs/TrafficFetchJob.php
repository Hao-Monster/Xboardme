<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\ServerReportJobReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\DistributorConnectionService;
use Illuminate\Support\Facades\Log;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    protected ?string $reportId = null;
    protected int $chunkIndex = 0;
    public $tries = 3;
    public $timeout = 20;

    public function __construct(
        array $server,
        array $data,
        $protocol,
        int $timestamp,
        ?string $reportId = null,
        int $chunkIndex = 0
    )
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->reportId = $reportId;
        $this->chunkIndex = $chunkIndex;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);

        $updateUsers = function (): void {
            foreach (array_chunk($this->data, 150, true) as $chunk) {
                $this->updateUserChunk($chunk);
            }
        };

        ServerReportJobReceipt::run(
            (int) $this->server['id'],
            $this->reportId,
            'traffic',
            $this->chunkIndex,
            $updateUsers
        );

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }

        try {
            app(DistributorConnectionService::class)->recordFirstTraffic($this->server, $this->data);
        } catch (\Throwable $exception) {
            Log::warning('Failed to record distributor connection state', [
                'server_id' => $this->server['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function updateUserChunk(array $traffic): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable((new User())->getTable());
        $idColumn = $grammar->wrap('id');
        $uploadColumn = $grammar->wrap('u');
        $downloadColumn = $grammar->wrap('d');
        $timestampColumn = $grammar->wrap('t');

        $uploadCases = [];
        $downloadCases = [];
        $uploadBindings = [];
        $downloadBindings = [];
        $userIds = [];
        $rate = $this->server['rate'];

        foreach ($traffic as $userId => $values) {
            $userId = (int) $userId;
            $uploadCases[] = 'WHEN ? THEN ?';
            $downloadCases[] = 'WHEN ? THEN ?';
            $uploadBindings[] = $userId;
            $uploadBindings[] = $values[0] * $rate;
            $downloadBindings[] = $userId;
            $downloadBindings[] = $values[1] * $rate;
            $userIds[] = $userId;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = "UPDATE {$table} SET "
            . "{$uploadColumn} = {$uploadColumn} + CASE {$idColumn} " . implode(' ', $uploadCases) . ' ELSE 0 END, '
            . "{$downloadColumn} = {$downloadColumn} + CASE {$idColumn} " . implode(' ', $downloadCases) . ' ELSE 0 END, '
            . "{$timestampColumn} = ? WHERE {$idColumn} IN ({$placeholders})";

        $connection->update($sql, [
            ...$uploadBindings,
            ...$downloadBindings,
            time(),
            ...$userIds,
        ]);
    }
}
