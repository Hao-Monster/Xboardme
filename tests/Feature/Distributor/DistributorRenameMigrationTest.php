<?php

namespace Tests\Feature\Distributor;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DistributorRenameMigrationTest extends TestCase
{
    // MySQL DDL commits implicitly; schema rollback tests cannot use an outer test transaction.
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->artisan('db:wipe')->assertExitCode(0);
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_revision_migration_is_repeatable_preserves_accounts_and_does_not_backfill(): void
    {
        $dealer = User::create([
            'email' => 'qa-revision-migration@example.com', 'password' => password_hash('password-123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true), 'token' => Helper::guid(), 'is_distributor' => true,
            'distributor_name' => '历史商户', 'balance' => 12345, 'commission_balance' => 6789,
        ]);
        $before = $dealer->fresh()->getRawOriginal();
        unset($before['distributor_revision']);
        $migration = require database_path('migrations/2026_10_10_000001_add_distributor_revision_to_v2_user.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_user', 'distributor_revision'));
        $this->assertSame($before, $dealer->fresh()->getRawOriginal());
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('v2_user', 'distributor_revision'));
        $this->assertNull($dealer->fresh()->distributor_revision);
        $after = $dealer->fresh()->getRawOriginal();
        unset($after['distributor_revision']);
        $this->assertSame($before, $after);

        // An old runtime can still update ordinary columns after the additive migration.
        DB::table('v2_user')->where('id', $dealer->id)->update(['remarks' => 'legacy writer']);
        $this->assertNull($dealer->fresh()->distributor_revision);
        $dealer->refresh()->update(['distributor_name' => '新名称']);
        $revision = $dealer->fresh()->distributor_revision;
        $this->assertTrue(Str::isUuid($revision));
        $migration->up();
        $this->assertSame($revision, $dealer->fresh()->distributor_revision);
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('v2_user', 'distributor_revision'));
        $this->assertSame('新名称', $dealer->fresh()->distributor_name);
        $this->assertSame('legacy writer', $dealer->fresh()->remarks);
        $this->assertSame(12345, $dealer->fresh()->balance);
        $migration->up();
    }
}
