<?php

namespace App\Jobs;

use App\Services\ServerActivationScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyServerActivationScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 10;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 60];

    public function __construct(
        public readonly int $serverId,
        public readonly string $revision,
        public readonly bool $targetEnabled
    ) {
        $this->onQueue('default');
    }

    public function handle(ServerActivationScheduleService $service): void
    {
        $remainingSeconds = $service->apply(
            $this->serverId,
            $this->revision,
            $this->targetEnabled
        );

        if ($remainingSeconds !== null && $remainingSeconds > 0) {
            $this->release($remainingSeconds);
        }
    }
}
