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
