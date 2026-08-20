<?php

namespace App\Services;

use App\Jobs\ApplyServerActivationScheduleJob;
use App\Models\Server;
use App\Models\ServerActivationSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServerActivationScheduleService
{
    public function save(Server $server, int $enableAt, int $disableAt): ServerActivationSchedule
    {
        $revision = (string) Str::uuid();

        [$schedule, $previous] = DB::transaction(function () use ($server, $enableAt, $disableAt, $revision) {
            $schedule = ServerActivationSchedule::query()
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->first();
            $previous = $schedule ? $schedule->only([
                'enable_at',
                'disable_at',
                'revision',
                'enabled_applied_at',
                'disabled_applied_at',
            ]) : null;

            $values = [
                'enable_at' => $enableAt,
                'disable_at' => $disableAt,
                'revision' => $revision,
                'enabled_applied_at' => null,
                'disabled_applied_at' => null,
            ];

            if ($schedule) {
                $schedule->update($values);
                return [$schedule->refresh(), $previous];
            }

            $schedule = ServerActivationSchedule::query()->create([
                'server_id' => $server->id,
                ...$values,
            ]);

            return [$schedule, null];
        });

        try {
            // Queue the later boundary first. If the second dispatch fails, the
            // queued close job becomes harmless once this revision is removed.
            ApplyServerActivationScheduleJob::dispatch($server->id, $revision, false)
                ->delay(Carbon::createFromTimestampUTC($disableAt));
            ApplyServerActivationScheduleJob::dispatch($server->id, $revision, true)
                ->delay(Carbon::createFromTimestampUTC($enableAt));
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($server, $revision, $previous) {
                $current = ServerActivationSchedule::query()
                    ->where('server_id', $server->id)
                    ->lockForUpdate()
                    ->first();

                // Do not overwrite a newer concurrent edit while compensating
                // for this request's queue failure.
                if (!$current || $current->revision !== $revision) {
                    return;
                }
                if ($previous === null) {
                    $current->delete();
                    return;
                }

                $current->update($previous);
            });
            throw $exception;
        }

        return $schedule;
    }

    public function cancel(int $serverId): bool
    {
        return ServerActivationSchedule::query()
            ->where('server_id', $serverId)
            ->delete() > 0;
    }

    /**
     * Apply one boundary. A positive return value asks the queue to release an
     * unexpectedly early job for the remaining number of seconds.
     */
    public function apply(int $serverId, string $revision, bool $targetEnabled): ?int
    {
        return DB::transaction(function () use ($serverId, $revision, $targetEnabled) {
            $schedule = ServerActivationSchedule::query()
                ->where('server_id', $serverId)
                ->where('revision', $revision)
                ->lockForUpdate()
                ->first();

            if (!$schedule) {
                return null;
            }

            $now = now()->timestamp;
            $boundaryAt = $targetEnabled ? $schedule->enable_at : $schedule->disable_at;
            if ($now < $boundaryAt) {
                return $boundaryAt - $now;
            }

            $appliedColumn = $targetEnabled ? 'enabled_applied_at' : 'disabled_applied_at';
            if ($schedule->{$appliedColumn} !== null) {
                return null;
            }

            $server = Server::query()->whereKey($serverId)->lockForUpdate()->first();
            if (!$server || $server->machine_id === null) {
                $schedule->forceFill([$appliedColumn => $now])->save();
                return null;
            }

            // If the queue recovers after both boundaries have passed, a late
            // start job must never re-enable a completed interval.
            if ($targetEnabled && $now >= $schedule->disable_at) {
                $this->setEnabledOnly($server, false);
                $schedule->forceFill([
                    'enabled_applied_at' => $now,
                    'disabled_applied_at' => $now,
                ])->save();
                return null;
            }

            $this->setEnabledOnly($server, $targetEnabled);
            $schedule->forceFill([$appliedColumn => $now])->save();

            return null;
        });
    }

    private function setEnabledOnly(Server $server, bool $enabled): void
    {
        if ($server->enabled === $enabled) {
            return;
        }

        $server->enabled = $enabled;
        $server->save();
    }
}
