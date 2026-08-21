<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

final class ServerReportJobReceipt
{
    public static function run(
        int $serverId,
        ?string $reportId,
        string $jobType,
        int $chunkIndex,
        Closure $callback
    ): bool {
        $operation = function () use ($serverId, $reportId, $jobType, $chunkIndex, $callback): bool {
            if ($reportId !== null) {
                $claimed = DB::table('v2_server_report_receipt')->insertOrIgnore([
                    'server_id' => $serverId,
                    'report_id' => $reportId,
                    'job_type' => $jobType,
                    'chunk_index' => $chunkIndex,
                    'created_at' => time(),
                ]);

                if ($claimed !== 1) {
                    return false;
                }
            }

            $callback();

            return true;
        };

        if (DB::connection()->getDriverName() === 'sqlite') {
            return SqliteImmediateTransaction::run($operation);
        }

        return DB::transaction($operation, 3);
    }
}
