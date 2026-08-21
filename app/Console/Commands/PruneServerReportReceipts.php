<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneServerReportReceipts extends Command
{
    protected $signature = 'server-report-receipts:prune';

    protected $description = 'Delete expired node report idempotency receipts';

    public function handle(): int
    {
        $retentionHours = max(24, (int) config('server_security.receipt_retention_hours', 168));
        $cutoff = now()->subHours($retentionHours)->timestamp;
        $deleted = DB::table('v2_server_report_receipt')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} server report receipts.");

        return self::SUCCESS;
    }
}
