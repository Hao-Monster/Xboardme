<?php

namespace App\Console\Commands;

use App\Services\NodeConnectionOwnership;
use Illuminate\Console\Command;

class NodeConnectionOwnershipMode extends Command
{
    protected $signature = 'node:connection-ownership
        {mode=status : status | rollout | strict}
        {--confirm-no-legacy-ws : Confirm every legacy WS process has been retired}';

    protected $description = 'Inspect or change the node connection ownership rollout mode';

    public function handle(NodeConnectionOwnership $ownership): int
    {
        $mode = strtolower((string) $this->argument('mode'));
        if (!in_array($mode, ['status', 'rollout', 'strict'], true)) {
            $this->error('Unsupported ownership mode.');

            return self::INVALID;
        }

        if ($mode === 'strict') {
            if (!$this->option('confirm-no-legacy-ws')) {
                $this->error('Strict mode requires --confirm-no-legacy-ws.');

                return self::INVALID;
            }
            $ownership->enableStrictMode();
        } elseif ($mode === 'rollout') {
            $ownership->disableStrictMode();
        }

        $this->line(json_encode([
            'ownership_initialized' => $ownership->ownershipEnabled(),
            'mode' => $ownership->strictModeEnabled() ? 'strict' : 'rollout',
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
