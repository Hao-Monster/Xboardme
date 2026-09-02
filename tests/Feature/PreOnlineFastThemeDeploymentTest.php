<?php

namespace Tests\Feature;

use Tests\TestCase;

class PreOnlineFastThemeDeploymentTest extends TestCase
{
    public function test_fast_theme_deployment_is_scoped_verified_and_recoverable(): void
    {
        $wrapper = file_get_contents(base_path('.github/scripts/deploy-preonline-theme-fast.ps1'));
        $deploy = file_get_contents(base_path('.github/scripts/deploy-preonline-theme-fast.sh'));
        $rollback = file_get_contents(base_path('.github/scripts/rollback-preonline-theme-fast.sh'));

        $this->assertStringContainsString("'^theme/Xboard/'", $wrapper);
        $this->assertStringContainsString('Fast deployment refused non-theme changes', $wrapper);
        $this->assertStringContainsString('requires all tracked changes to be committed', $wrapper);
        $this->assertStringContainsString('git merge-base --is-ancestor', $wrapper);
        $this->assertStringContainsString('node --test', $wrapper);
        $this->assertStringContainsString('ThemeAssetReleaseIntegrityTest.php', $wrapper);
        $this->assertStringContainsString('git diff --check', $wrapper);
        $this->assertStringNotContainsString('SkipTests', $wrapper);
        $this->assertStringContainsString('PREONLINE_THEME_FAST_DEPLOY_SECONDS', $wrapper);

        $this->assertStringContainsString('base_release_mismatch', $deploy);
        $this->assertStringContainsString('payload_contains_symlink', $deploy);
        $this->assertStringContainsString('archive_outside_theme_scope', $deploy);
        $this->assertStringContainsString('unsupported_payload_entry', $deploy);
        $this->assertStringContainsString('manifest_identity_mismatch', $deploy);
        $this->assertStringContainsString('mv "$current" "$backup"', $deploy);
        $this->assertStringContainsString('restore_on_error', $deploy);
        $this->assertStringContainsString('public_current="/www/public/theme/Xboard"', $deploy);
        $this->assertStringContainsString('public_candidate', $deploy);
        $this->assertStringContainsString('served_css_hash', $deploy);
        $this->assertStringContainsString('PUBLIC_BACKUP_PATH', $deploy);
        $this->assertStringContainsString('php /www/artisan view:clear', $deploy);
        $this->assertStringContainsString('docker commit --pause=false', $deploy);
        $this->assertStringNotContainsString('artisan migrate', $deploy);
        $this->assertStringNotContainsString('docker restart', $deploy);
        $this->assertStringNotContainsString('docker stop', $deploy);

        $this->assertStringContainsString('state_mismatch', $rollback);
        $this->assertStringContainsString('public_current="/www/public/theme/Xboard"', $rollback);
        $this->assertStringContainsString('PUBLIC_BACKUP_PATH', $rollback);
        $this->assertStringContainsString('served_css_hash', $rollback);
        $this->assertStringContainsString('mv "$backup" "$current"', $rollback);
        $this->assertStringContainsString('PREONLINE_THEME_ROLLBACK=PASS', $rollback);
    }
}
