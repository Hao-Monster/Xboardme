<?php

namespace Tests\Unit;

use App\Support\RuntimeRole;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RuntimeRoleTest extends TestCase
{
    #[DataProvider('dedicatedRoles')]
    public function test_dedicated_roles_never_enable_the_octane_scheduler_tick(string $role): void
    {
        $this->assertFalse(RuntimeRole::schedulerEnabled($role, true));
        $this->assertFalse(RuntimeRole::schedulerEnabled($role, null));
    }

    public function test_legacy_role_preserves_existing_scheduler_default(): void
    {
        $this->assertTrue(RuntimeRole::schedulerEnabled('legacy', null));
        $this->assertFalse(RuntimeRole::schedulerEnabled('legacy', false));
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeRole::normalize('all-in-one-but-misspelled');
    }

    public static function dedicatedRoles(): array
    {
        return [
            ['web'],
            ['ws'],
            ['horizon'],
            ['scheduler'],
            ['maintenance'],
        ];
    }
}
