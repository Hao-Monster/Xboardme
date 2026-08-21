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
    public const DAILY_TIMEZONE = 'Asia/Singapore';

    public function save(Server $server, int $enableAt, int $disableAt): ServerActivationSchedule
    {
        $revision = (string) Str::uuid();

        [$schedule, $previous] = DB::transaction(function () use ($server, $enableAt, $disableAt, $revision) {
            $schedule = ServerActivationSchedule::query()
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->first();
            $previous = $schedule ? $schedule->only([
                'schedule_type',
                'timezone',
                'enable_second',
                'disable_second',
                'enable_at',
                'disable_at',
                'next_transition_at',
                'next_target_enabled',
                'revision',
                'enabled_applied_at',
                'disabled_applied_at',
            ]) : null;

            $values = [
                'schedule_type' => 'once',
                'timezone' => null,
                'enable_second' => null,
                'disable_second' => null,
                'enable_at' => $enableAt,
                'disable_at' => $disableAt,
                'next_transition_at' => null,
                'next_target_enabled' => null,
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

    public function saveDaily(Server $server, string $enableTime, string $disableTime): ServerActivationSchedule
    {
        $enableSecond = $this->parseDailyTime($enableTime);
        $disableSecond = $this->parseDailyTime($disableTime);
        if ($enableSecond === $disableSecond) {
            throw new \InvalidArgumentException('Daily activation times must be different.');
        }

        $revision = (string) Str::uuid();
        $now = now()->timestamp;
        $next = $this->nextDailyTransition($enableSecond, $disableSecond, $now);
        $desiredEnabled = $this->dailyState($enableSecond, $disableSecond, $now);

        $schedule = DB::transaction(function () use (
            $server,
            $enableSecond,
            $disableSecond,
            $revision,
            $now,
            $next,
            $desiredEnabled
        ) {
            $schedule = ServerActivationSchedule::query()
                ->where('server_id', $server->id)
                ->lockForUpdate()
                ->first();

            $values = [
                'schedule_type' => 'daily',
                'timezone' => self::DAILY_TIMEZONE,
                'enable_second' => $enableSecond,
                'disable_second' => $disableSecond,
                // Retain non-null legacy columns so this backward-compatible
                // migration does not need to rewrite the original table.
                'enable_at' => 0,
                'disable_at' => 0,
                'next_transition_at' => $next['timestamp'],
                'next_target_enabled' => $next['target_enabled'],
                'revision' => $revision,
                'enabled_applied_at' => $desiredEnabled ? $now : null,
                'disabled_applied_at' => $desiredEnabled ? null : $now,
            ];

            if ($schedule) {
                $schedule->forceFill($values)->save();
            } else {
                $schedule = ServerActivationSchedule::query()->create([
                    'server_id' => $server->id,
                    ...$values,
                ]);
            }

            // Dispatch before changing the node. A queue failure rolls the
            // database transaction back without leaving an enforced schedule.
            $this->dispatchBoundary(
                $server->id,
                $revision,
                $next['target_enabled'],
                $next['timestamp']
            );
            $this->setEnabledOnly($server, $desiredEnabled);

            return $schedule;
        });

        return $schedule->refresh();
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

            if ($schedule->schedule_type === 'daily') {
                return $this->applyDaily($schedule, $targetEnabled);
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

    public function isDailyActive(ServerActivationSchedule $schedule, ?int $timestamp = null): bool
    {
        if ($schedule->schedule_type !== 'daily'
            || $schedule->enable_second === null
            || $schedule->disable_second === null) {
            return false;
        }

        return $this->dailyState(
            $schedule->enable_second,
            $schedule->disable_second,
            $timestamp ?? now()->timestamp
        );
    }

    public function formatDailyTime(?int $second): ?string
    {
        if ($second === null || $second < 0 || $second >= 86400) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($second, 3600), intdiv($second % 3600, 60));
    }

    private function applyDaily(ServerActivationSchedule $schedule, bool $targetEnabled): ?int
    {
        if ($schedule->enable_second === null
            || $schedule->disable_second === null
            || $schedule->next_transition_at === null
            || $schedule->next_target_enabled === null
            || $schedule->next_target_enabled !== $targetEnabled) {
            return null;
        }

        $now = now()->timestamp;
        if ($now < $schedule->next_transition_at) {
            return $schedule->next_transition_at - $now;
        }

        // Calculate from the current clock instead of trusting the original
        // event type. This corrects the node after a delayed queue recovery.
        $desiredEnabled = $this->dailyState(
            $schedule->enable_second,
            $schedule->disable_second,
            $now
        );
        $next = $this->nextDailyTransition(
            $schedule->enable_second,
            $schedule->disable_second,
            $now
        );

        $this->dispatchBoundary(
            $schedule->server_id,
            $schedule->revision,
            $next['target_enabled'],
            $next['timestamp']
        );

        $server = Server::query()->whereKey($schedule->server_id)->lockForUpdate()->first();
        if ($server && $server->machine_id !== null) {
            $this->setEnabledOnly($server, $desiredEnabled);
        }

        $schedule->forceFill([
            'next_transition_at' => $next['timestamp'],
            'next_target_enabled' => $next['target_enabled'],
            $desiredEnabled ? 'enabled_applied_at' : 'disabled_applied_at' => $now,
        ])->save();

        return null;
    }

    private function parseDailyTime(string $time): int
    {
        if (!preg_match('/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)$/', $time, $parts)) {
            throw new \InvalidArgumentException('Daily activation time must use HH:MM.');
        }

        return ((int) $parts['hour'] * 3600) + ((int) $parts['minute'] * 60);
    }

    private function dailyState(int $enableSecond, int $disableSecond, int $timestamp): bool
    {
        $local = Carbon::createFromTimestamp($timestamp, self::DAILY_TIMEZONE);
        $secondOfDay = ($local->hour * 3600) + ($local->minute * 60) + $local->second;

        if ($enableSecond < $disableSecond) {
            return $secondOfDay >= $enableSecond && $secondOfDay < $disableSecond;
        }

        return $secondOfDay >= $enableSecond || $secondOfDay < $disableSecond;
    }

    /** @return array{timestamp: int, target_enabled: bool} */
    private function nextDailyTransition(int $enableSecond, int $disableSecond, int $after): array
    {
        $localNow = Carbon::createFromTimestamp($after, self::DAILY_TIMEZONE);
        $startOfToday = $localNow->copy()->startOfDay();
        $candidates = [];

        for ($offset = -1; $offset <= 2; $offset++) {
            $day = $startOfToday->copy()->addDays($offset);
            $enable = $day->copy()->addSeconds($enableSecond);
            $disable = $day->copy()->addSeconds($disableSecond);
            if ($disableSecond < $enableSecond) {
                $disable->addDay();
            }

            if ($enable->timestamp > $after) {
                $candidates[] = [
                    'timestamp' => $enable->timestamp,
                    'target_enabled' => true,
                ];
            }
            if ($disable->timestamp > $after) {
                $candidates[] = [
                    'timestamp' => $disable->timestamp,
                    'target_enabled' => false,
                ];
            }
        }

        usort($candidates, fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);

        return $candidates[0];
    }

    private function dispatchBoundary(int $serverId, string $revision, bool $targetEnabled, int $timestamp): void
    {
        ApplyServerActivationScheduleJob::dispatch($serverId, $revision, $targetEnabled)
            ->delay(Carbon::createFromTimestampUTC($timestamp));
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
