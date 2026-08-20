<?php

namespace Tests\Feature\Server;

use App\Http\Middleware\InitializePlugins;
use App\Jobs\ApplyServerActivationScheduleJob;
use App\Models\Server;
use App\Models\ServerActivationSchedule;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerActivationScheduleService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServerActivationScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);
        $this->now = Carbon::create(2026, 8, 20, 12, 0, 0, config('app.timezone'));
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_schedule_endpoints_require_an_admin(): void
    {
        $server = $this->linkedServer();
        $endpoint = $this->endpoint('server/manage/activationSchedule');

        $this->getJson($endpoint . '?server_id=' . $server->id)->assertForbidden();

        Sanctum::actingAs($this->user('member-schedule@example.com'));
        $this->getJson($endpoint . '?server_id=' . $server->id)->assertForbidden();

        Sanctum::actingAs($this->user('admin-schedule@example.com', true));
        $this->getJson($endpoint . '?server_id=' . $server->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data', null);
    }

    public function test_admin_can_save_a_daily_cross_midnight_schedule_that_reconciles_now_and_queues_only_the_next_boundary(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('admin-daily-schedule@example.com', true));
        $server = $this->linkedServer([
            'enabled' => true,
            'show' => true,
            'sort' => 37,
        ]);

        $response = $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $server->id,
            'schedule_type' => 'daily',
            'enable_time' => '19:00',
            'disable_time' => '01:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.server_id', $server->id)
            ->assertJsonPath('data.schedule_type', 'daily')
            ->assertJsonPath('data.timezone', 'Asia/Singapore')
            ->assertJsonPath('data.enable_time', '19:00')
            ->assertJsonPath('data.disable_time', '01:00')
            ->assertJsonPath('data.phase', 'inactive');

        $revision = (string) $response->json('data.revision');
        $expectedEnableAt = Carbon::create(2026, 8, 20, 19, 0, 0, 'Asia/Singapore')->timestamp;
        $this->assertDatabaseHas('v2_server_activation_schedule', [
            'server_id' => $server->id,
            'schedule_type' => 'daily',
            'timezone' => 'Asia/Singapore',
            'enable_second' => 19 * 3600,
            'disable_second' => 3600,
            'revision' => $revision,
            'next_transition_at' => $expectedEnableAt,
            'next_target_enabled' => true,
        ]);

        $reconciled = $server->fresh();
        $this->assertFalse($reconciled->enabled);
        $this->assertTrue($reconciled->show);
        $this->assertSame(37, $reconciled->sort);

        Queue::assertPushed(ApplyServerActivationScheduleJob::class, function ($job) use ($server, $revision, $expectedEnableAt) {
            return $job->serverId === $server->id
                && $job->revision === $revision
                && $job->targetEnabled === true
                && $job->queue === 'default'
                && $job->delay?->timestamp === $expectedEnableAt;
        });
        Queue::assertPushed(ApplyServerActivationScheduleJob::class, 1);
    }

    public function test_saving_a_daily_schedule_inside_the_window_enables_immediately_and_preserves_other_node_fields(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 20, 0, 0, 'Asia/Singapore'));
        $server = $this->linkedServer([
            'enabled' => false,
            'show' => true,
            'sort' => 23,
        ]);
        $machineId = $server->machine_id;

        $schedule = app(ServerActivationScheduleService::class)->saveDaily(
            $server,
            '19:00',
            '01:00'
        );

        $this->assertSame('daily', $schedule->schedule_type);
        $this->assertSame(
            Carbon::create(2026, 8, 21, 1, 0, 0, 'Asia/Singapore')->timestamp,
            $schedule->next_transition_at
        );
        $this->assertFalse($schedule->next_target_enabled);
        $updated = $server->fresh();
        $this->assertTrue($updated->enabled);
        $this->assertTrue($updated->show);
        $this->assertSame(23, $updated->sort);
        $this->assertSame($machineId, $updated->machine_id);
    }

    public function test_daily_schedule_supports_a_same_day_window(): void
    {
        Queue::fake();
        $server = $this->linkedServer(['enabled' => false]);

        $schedule = app(ServerActivationScheduleService::class)->saveDaily(
            $server,
            '10:00',
            '18:00'
        );

        $this->assertTrue($server->fresh()->enabled);
        $this->assertSame(
            Carbon::create(2026, 8, 20, 18, 0, 0, 'Asia/Singapore')->timestamp,
            $schedule->next_transition_at
        );
        $this->assertFalse($schedule->next_target_enabled);
    }

    public function test_daily_boundary_jobs_repeat_across_midnight_and_a_late_job_reconciles_current_state(): void
    {
        Queue::fake();
        $server = $this->linkedServer(['enabled' => false]);
        $schedule = ServerActivationSchedule::query()->create([
            'server_id' => $server->id,
            'enable_at' => 0,
            'disable_at' => 0,
            'schedule_type' => 'daily',
            'timezone' => 'Asia/Singapore',
            'enable_second' => 19 * 3600,
            'disable_second' => 3600,
            'revision' => 'daily-revision',
            'next_transition_at' => Carbon::create(2026, 8, 20, 19, 0, 0, 'Asia/Singapore')->timestamp,
            'next_target_enabled' => true,
        ]);
        $service = app(ServerActivationScheduleService::class);

        Carbon::setTestNow(Carbon::create(2026, 8, 20, 19, 0, 0, 'Asia/Singapore'));
        (new ApplyServerActivationScheduleJob($server->id, $schedule->revision, true))->handle($service);
        $this->assertTrue($server->fresh()->enabled);
        $this->assertSame(
            Carbon::create(2026, 8, 21, 1, 0, 0, 'Asia/Singapore')->timestamp,
            $schedule->fresh()->next_transition_at
        );
        $this->assertFalse($schedule->fresh()->next_target_enabled);
        Queue::assertPushed(ApplyServerActivationScheduleJob::class, function ($job) use ($server) {
            return $job->serverId === $server->id && $job->targetEnabled === false;
        });

        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 8, 21, 2, 30, 0, 'Asia/Singapore'));
        (new ApplyServerActivationScheduleJob($server->id, $schedule->revision, false))->handle($service);
        $this->assertFalse($server->fresh()->enabled);
        $this->assertSame(
            Carbon::create(2026, 8, 21, 19, 0, 0, 'Asia/Singapore')->timestamp,
            $schedule->fresh()->next_transition_at
        );
        $this->assertTrue($schedule->fresh()->next_target_enabled);
        Queue::assertPushed(ApplyServerActivationScheduleJob::class, function ($job) use ($server) {
            return $job->serverId === $server->id && $job->targetEnabled === true;
        });
    }

    public function test_daily_schedule_rejects_equal_or_malformed_times(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('admin-invalid-daily@example.com', true));
        $server = $this->linkedServer();
        $endpoint = $this->endpoint('server/manage/activationSchedule');

        foreach ([
            ['enable_time' => '19:00', 'disable_time' => '19:00'],
            ['enable_time' => '7 PM', 'disable_time' => '01:00'],
            ['enable_time' => '24:00', 'disable_time' => '01:00'],
        ] as $range) {
            $this->postJson($endpoint, [
                'server_id' => $server->id,
                'schedule_type' => 'daily',
                ...$range,
            ])->assertUnprocessable();
        }

        Queue::assertNotPushed(ApplyServerActivationScheduleJob::class);
        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
    }

    public function test_admin_can_create_and_replace_a_one_time_schedule_with_two_delayed_jobs(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('admin-save-schedule@example.com', true));
        $server = $this->linkedServer(['enabled' => false]);
        $enableAt = $this->now->copy()->addHour()->timestamp;
        $disableAt = $this->now->copy()->addHours(2)->timestamp;

        $response = $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $server->id,
            'enable_at' => $enableAt,
            'disable_at' => $disableAt,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.server_id', $server->id)
            ->assertJsonPath('data.enable_at', $enableAt)
            ->assertJsonPath('data.disable_at', $disableAt)
            ->assertJsonPath('data.phase', 'pending');

        $firstRevision = (string) $response->json('data.revision');
        $this->assertNotSame('', $firstRevision);
        $this->assertDatabaseHas('v2_server_activation_schedule', [
            'server_id' => $server->id,
            'enable_at' => $enableAt,
            'disable_at' => $disableAt,
            'revision' => $firstRevision,
        ]);
        $this->assertTrue($server->fresh()->enabled === false);

        Queue::assertPushed(ApplyServerActivationScheduleJob::class, function ($job) use ($server, $firstRevision, $enableAt) {
            return $job->serverId === $server->id
                && $job->revision === $firstRevision
                && $job->targetEnabled === true
                && $job->queue === 'default'
                && $job->delay?->timestamp === $enableAt;
        });
        Queue::assertPushed(ApplyServerActivationScheduleJob::class, function ($job) use ($server, $firstRevision, $disableAt) {
            return $job->serverId === $server->id
                && $job->revision === $firstRevision
                && $job->targetEnabled === false
                && $job->queue === 'default'
                && $job->delay?->timestamp === $disableAt;
        });

        Queue::fake();
        $replacement = $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $server->id,
            'enable_at' => $enableAt + 300,
            'disable_at' => $disableAt + 300,
        ])->assertOk();

        $this->assertDatabaseCount('v2_server_activation_schedule', 1);
        $this->assertNotSame($firstRevision, $replacement->json('data.revision'));
        Queue::assertPushed(ApplyServerActivationScheduleJob::class, 2);
    }

    public function test_schedule_rejects_unlinked_nodes_and_invalid_or_expired_ranges(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('admin-invalid-schedule@example.com', true));
        $unlinked = $this->server(['machine_id' => null]);

        $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $unlinked->id,
            'enable_at' => $this->now->copy()->addHour()->timestamp,
            'disable_at' => $this->now->copy()->addHours(2)->timestamp,
        ])->assertUnprocessable();

        $linked = $this->linkedServer();
        $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $linked->id,
            'enable_at' => $this->now->copy()->addHours(2)->timestamp,
            'disable_at' => $this->now->copy()->addHour()->timestamp,
        ])->assertUnprocessable();

        $this->postJson($this->endpoint('server/manage/activationSchedule'), [
            'server_id' => $linked->id,
            'enable_at' => $this->now->copy()->subHours(2)->timestamp,
            'disable_at' => $this->now->copy()->subHour()->timestamp,
        ])->assertUnprocessable();

        Queue::assertNotPushed(ApplyServerActivationScheduleJob::class);
        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
    }

    public function test_jobs_change_only_enabled_and_are_idempotent_and_revision_safe(): void
    {
        $server = $this->linkedServer([
            'enabled' => false,
            'show' => true,
            'sort' => 37,
        ]);
        $machineId = $server->machine_id;
        $schedule = ServerActivationSchedule::query()->create([
            'server_id' => $server->id,
            'enable_at' => $this->now->copy()->addMinute()->timestamp,
            'disable_at' => $this->now->copy()->addHour()->timestamp,
            'revision' => 'current-revision',
        ]);
        $service = app(ServerActivationScheduleService::class);

        Carbon::setTestNow($this->now->copy()->addMinute());
        (new ApplyServerActivationScheduleJob(
            $server->id,
            'stale-revision',
            true
        ))->handle($service);
        $this->assertFalse($server->fresh()->enabled);

        (new ApplyServerActivationScheduleJob(
            $server->id,
            $schedule->revision,
            true
        ))->handle($service);
        $started = $server->fresh();
        $this->assertTrue($started->enabled);
        $this->assertTrue($started->show);
        $this->assertSame(37, $started->sort);
        $this->assertSame($machineId, $started->machine_id);
        $enabledAppliedAt = $schedule->fresh()->enabled_applied_at;
        $this->assertSame(now()->timestamp, $enabledAppliedAt);

        (new ApplyServerActivationScheduleJob(
            $server->id,
            $schedule->revision,
            true
        ))->handle($service);
        $this->assertSame($enabledAppliedAt, $schedule->fresh()->enabled_applied_at);

        Carbon::setTestNow($this->now->copy()->addHour());
        (new ApplyServerActivationScheduleJob(
            $server->id,
            $schedule->revision,
            false
        ))->handle($service);
        $finished = $server->fresh();
        $this->assertFalse($finished->enabled);
        $this->assertTrue($finished->show);
        $this->assertSame(37, $finished->sort);
        $this->assertSame($machineId, $finished->machine_id);
        $this->assertSame(now()->timestamp, $schedule->fresh()->disabled_applied_at);
    }

    public function test_late_start_job_cannot_reenable_a_schedule_that_has_already_ended(): void
    {
        $server = $this->linkedServer(['enabled' => true]);
        $schedule = ServerActivationSchedule::query()->create([
            'server_id' => $server->id,
            'enable_at' => $this->now->copy()->addMinute()->timestamp,
            'disable_at' => $this->now->copy()->addMinutes(2)->timestamp,
            'revision' => 'late-start-revision',
        ]);

        Carbon::setTestNow($this->now->copy()->addMinutes(10));
        (new ApplyServerActivationScheduleJob(
            $server->id,
            $schedule->revision,
            true
        ))->handle(app(ServerActivationScheduleService::class));

        $this->assertFalse($server->fresh()->enabled);
        $this->assertSame(now()->timestamp, $schedule->fresh()->enabled_applied_at);
        $this->assertSame(now()->timestamp, $schedule->fresh()->disabled_applied_at);
    }

    public function test_admin_can_cancel_a_schedule_and_stale_jobs_become_noops(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('admin-cancel-schedule@example.com', true));
        $server = $this->linkedServer(['enabled' => false]);
        $schedule = ServerActivationSchedule::query()->create([
            'server_id' => $server->id,
            'enable_at' => $this->now->copy()->addHour()->timestamp,
            'disable_at' => $this->now->copy()->addHours(2)->timestamp,
            'revision' => 'cancelled-revision',
        ]);

        $this->postJson($this->endpoint('server/manage/dropActivationSchedule'), [
            'server_id' => $server->id,
        ])->assertOk()->assertJsonPath('data', true);

        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
        Carbon::setTestNow($this->now->copy()->addHour());
        (new ApplyServerActivationScheduleJob(
            $server->id,
            $schedule->revision,
            true
        ))->handle(app(ServerActivationScheduleService::class));
        $this->assertFalse($server->fresh()->enabled);
    }

    public function test_queue_dispatch_failure_does_not_leave_an_unexecutable_schedule(): void
    {
        $server = $this->linkedServer(['enabled' => false]);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(ServerActivationScheduleService::class)->save(
                $server,
                $this->now->copy()->addHour()->timestamp,
                $this->now->copy()->addHours(2)->timestamp
            );
            $this->fail('The queue failure must be visible to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
        $this->assertFalse($server->fresh()->enabled);
    }

    public function test_daily_queue_dispatch_failure_rolls_back_the_schedule_and_immediate_state_reconciliation(): void
    {
        $server = $this->linkedServer(['enabled' => true]);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(ServerActivationScheduleService::class)->saveDaily($server, '19:00', '01:00');
            $this->fail('The queue failure must be visible to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
        $this->assertTrue($server->fresh()->enabled);
    }

    public function test_daily_manual_override_survives_until_the_next_boundary_and_deleting_the_schedule_keeps_it(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 18, 0, 0, 'Asia/Singapore'));
        $server = $this->linkedServer(['enabled' => false]);
        $service = app(ServerActivationScheduleService::class);
        $schedule = $service->saveDaily($server, '19:00', '01:00');

        $server->forceFill(['enabled' => true])->save();
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 18, 30, 0, 'Asia/Singapore'));

        $this->assertSame(1800, $service->apply($server->id, $schedule->revision, true));
        $this->assertTrue($server->fresh()->enabled);

        $this->assertTrue($service->cancel($server->id));
        $this->assertDatabaseCount('v2_server_activation_schedule', 0);
        $this->assertTrue($server->fresh()->enabled);
    }

    public function test_failed_schedule_replacement_restores_the_previous_valid_revision(): void
    {
        $server = $this->linkedServer(['enabled' => false]);
        $previous = ServerActivationSchedule::query()->create([
            'server_id' => $server->id,
            'enable_at' => $this->now->copy()->addMinutes(10)->timestamp,
            'disable_at' => $this->now->copy()->addMinutes(20)->timestamp,
            'revision' => 'previous-valid-revision',
            'enabled_applied_at' => null,
            'disabled_applied_at' => null,
        ]);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        try {
            app(ServerActivationScheduleService::class)->save(
                $server,
                $this->now->copy()->addHour()->timestamp,
                $this->now->copy()->addHours(2)->timestamp
            );
            $this->fail('The queue failure must be visible to the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        }

        $restored = $previous->fresh();
        $this->assertSame('previous-valid-revision', $restored->revision);
        $this->assertSame($this->now->copy()->addMinutes(10)->timestamp, $restored->enable_at);
        $this->assertSame($this->now->copy()->addMinutes(20)->timestamp, $restored->disable_at);
        $this->assertDatabaseCount('v2_server_activation_schedule', 1);
    }

    public function test_daily_schedule_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path(
            'migrations/2026_08_20_000002_add_daily_fields_to_server_activation_schedules_table.php'
        );

        $columns = [
            'schedule_type',
            'timezone',
            'enable_second',
            'disable_second',
            'next_transition_at',
            'next_target_enabled',
        ];
        $this->assertTrue(Schema::hasColumns('v2_server_activation_schedule', $columns));
        $migration->down();
        $this->assertFalse(Schema::hasColumns('v2_server_activation_schedule', $columns));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('v2_server_activation_schedule', $columns));
    }

    public function test_activation_schedule_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path(
            'migrations/2026_08_20_000001_create_server_activation_schedules_table.php'
        );

        $this->assertTrue(Schema::hasTable('v2_server_activation_schedule'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('v2_server_activation_schedule'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('v2_server_activation_schedule'));
    }

    private function endpoint(string $path): string
    {
        $securePath = admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        );

        return '/api/v2/' . $securePath . '/' . ltrim($path, '/');
    }

    private function user(string $email, bool $admin = false): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $admin,
            'is_staff' => false,
            'is_distributor' => false,
            'banned' => false,
            't' => 0,
        ]);
    }

    private function linkedServer(array $attributes = []): Server
    {
        $machine = ServerMachine::query()->create([
            'name' => 'Schedule machine ' . (ServerMachine::query()->count() + 1),
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);

        return $this->server(array_merge(['machine_id' => $machine->id], $attributes));
    }

    private function server(array $attributes = []): Server
    {
        $number = Server::query()->count() + 1;

        return Server::query()->create(array_merge([
            'name' => 'Schedule node ' . $number,
            'type' => Server::TYPE_SOCKS,
            'host' => '127.0.0.1',
            'port' => 12000 + $number,
            'server_port' => 12000 + $number,
            'rate' => 1,
            'group_ids' => ['1'],
            'show' => true,
            'enabled' => true,
        ], $attributes));
    }
}
