<?php

namespace App\Jobs;

use App\Exceptions\HwidObservationPersistenceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateDistributorHwidObservation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    /**
     * @var array<int, int>
     */
    public array $backoff = [5, 30];

    public int $deviceId;

    public int $distributorOrderId;

    public string $hwid;

    public int $observedAt;

    public ?string $deviceOs;

    public ?string $osVersion;

    public ?string $deviceModel;

    public ?string $userAgent;

    public ?string $ip;

    public function __construct(
        int $deviceId,
        int $distributorOrderId,
        string $hwid,
        int $observedAt,
        ?string $deviceOs = null,
        ?string $osVersion = null,
        ?string $deviceModel = null,
        ?string $userAgent = null,
        ?string $ip = null
    ) {
        $this->deviceId = $deviceId;
        $this->distributorOrderId = $distributorOrderId;
        $this->hwid = mb_substr(trim($hwid), 0, 64);
        $this->observedAt = $observedAt;
        $this->deviceOs = self::sanitizeOptional($deviceOs, 100);
        $this->osVersion = self::sanitizeOptional($osVersion, 100);
        $this->deviceModel = self::sanitizeOptional($deviceModel, 150);
        $this->userAgent = self::sanitizeOptional($userAgent, 255);
        $this->ip = self::sanitizeOptional($ip, 45);
    }

    /**
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
     */
    public static function fromObservation(array $observation): self
    {
        return new self(
            deviceId: (int) $observation['device_id'],
            distributorOrderId: (int) $observation['distributor_order_id'],
            hwid: (string) $observation['hwid'],
            observedAt: (int) $observation['observed_at'],
            deviceOs: isset($observation['device_os']) ? (string) $observation['device_os'] : null,
            osVersion: isset($observation['os_version']) ? (string) $observation['os_version'] : null,
            deviceModel: isset($observation['device_model']) ? (string) $observation['device_model'] : null,
            userAgent: isset($observation['user_agent']) ? (string) $observation['user_agent'] : null,
            ip: isset($observation['ip']) ? (string) $observation['ip'] : null,
        );
    }

    /**
     * Prevent Horizon from deriving or persisting PII/HWID tags on failure events.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [];
    }

    public function handle(): void
    {
        $startedAt = hrtime(true);
        $updates = [
            'last_seen_at' => $this->observedAt,
            'updated_at' => $this->observedAt,
        ];

        $optionalFields = [
            'device_os' => self::sanitizeOptional($this->deviceOs, 100),
            'os_version' => self::sanitizeOptional($this->osVersion, 100),
            'device_model' => self::sanitizeOptional($this->deviceModel, 150),
            'user_agent' => self::sanitizeOptional($this->userAgent, 255),
            'ip' => self::sanitizeOptional($this->ip, 45),
        ];
        foreach ($optionalFields as $column => $value) {
            if ($value !== null) {
                $updates[$column] = $value;
            }
        }

        try {
            $affected = DB::table('v2_distributor_hwid_device')
                ->where('id', $this->deviceId)
                ->where('distributor_order_id', $this->distributorOrderId)
                ->where('hwid', $this->hwid)
                ->where(function ($query): void {
                    $query->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', $this->observedAt);
                })
                ->update($updates);
        } catch (Throwable $exception) {
            $safeException = HwidObservationPersistenceException::fromThrowable($exception);
            $this->emitWorkerLog(
                startedAtNs: $startedAt,
                outcome: 'failed',
                affectedRows: 0,
                errorCategory: $safeException->category()
            );

            throw $safeException;
        }

        $this->emitWorkerLog(
            startedAtNs: $startedAt,
            outcome: $affected > 0 ? 'updated' : 'noop',
            affectedRows: $affected,
            errorCategory: null
        );
    }

    private function emitWorkerLog(
        int $startedAtNs,
        string $outcome,
        int $affectedRows,
        ?string $errorCategory
    ): void {
        if (!(bool) config('subscription.timing_log_enabled', true)) {
            return;
        }

        $payload = [
            'queue' => (string) config('subscription.hwid_observation_queue', 'hwid_observation'),
            'outcome' => $outcome,
            'affected_rows' => $affectedRows,
            'wait_seconds' => max(0, time() - $this->observedAt),
            'duration_ms' => round(max(0, hrtime(true) - $startedAtNs) / 1_000_000, 3),
            'attempts' => $this->attempts(),
            'error_category' => $errorCategory,
        ];

        $channel = (string) config('subscription.timing_log_channel', 'daily');
        try {
            Log::channel($channel)->info('subscription.hwid_observation', $payload);
        } catch (Throwable) {
            // Never fail job handling due to diagnostic logging errors.
        }
    }

    private static function sanitizeOptional(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }
}
