<?php

namespace Tests\Feature;

use App\Services\NodeConnectionOwnership;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class NodeConnectionOwnershipModeCommandTest extends TestCase
{
    public function test_strict_mode_requires_an_explicit_legacy_ws_confirmation(): void
    {
        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldNotReceive('enableStrictMode');
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $this->assertSame(2, Artisan::call('node:connection-ownership', ['mode' => 'strict']));
    }

    public function test_rollout_mode_is_a_reversible_rollback_action(): void
    {
        $ownership = Mockery::mock(NodeConnectionOwnership::class);
        $ownership->shouldReceive('disableStrictMode')->once();
        $ownership->shouldReceive('ownershipEnabled')->once()->andReturnTrue();
        $ownership->shouldReceive('strictModeEnabled')->once()->andReturnFalse();
        $this->app->instance(NodeConnectionOwnership::class, $ownership);

        $this->assertSame(0, Artisan::call('node:connection-ownership', ['mode' => 'rollout']));
        $this->assertStringContainsString('"mode":"rollout"', Artisan::output());
    }
}
