<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThemeAssetReleaseIntegrityTest extends TestCase
{
    public function test_web_routes_use_the_immutable_asset_version_contract(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('use App\\Support\\AssetVersion;', $routes);
        $this->assertSame(2, substr_count($routes, 'AssetVersion::current()'));
        $this->assertStringNotContainsString('app(UpdateService::class)->getCurrentVersion()', $routes);
    }

    public function test_every_dashboard_asset_is_versioned_by_the_same_release_identifier(): void
    {
        $dashboard = file_get_contents(base_path('theme/Xboard/dashboard.blade.php'));
        $expectedAssets = [
            '/theme/{{$theme}}/assets/distributor.css?v={{$version}}',
            '/assets/knowledge-share.css?v={{$version}}',
            '/theme/{{$theme}}/assets/client-center.css?v={{$version}}',
            '/assets/knowledge-share.js?v={{$version}}',
            '/theme/{{$theme}}/assets/client-center.js?v={{$version}}',
            '/theme/{{$theme}}/assets/distributor-message-guard.js?v={{$version}}',
            '/theme/{{$theme}}/assets/auth-session.js?v={{$version}}',
            '/theme/{{$theme}}/assets/umi.js?v={{$version}}',
            '/theme/{{$theme}}/assets/distributor.js?v={{$version}}',
        ];

        foreach ($expectedAssets as $asset) {
            $this->assertStringContainsString($asset, $dashboard);
        }
        $this->assertStringContainsString('<meta name="xboard-release" content="{{$version}}"', $dashboard);
    }

    public function test_image_build_stamps_and_embeds_the_exact_workflow_commit(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertStringContainsString('VERSION="$GITHUB_SHA"', $workflow);
        $this->assertStringNotContainsString("git rev-parse --short HEAD", $workflow);
        $this->assertStringContainsString('APP_REVISION=${{ github.sha }}', $workflow);
        $this->assertStringContainsString('ARG APP_REVISION=local', $dockerfile);
        $this->assertStringContainsString('build-theme-asset-manifest.php "$APP_REVISION"', $dockerfile);
        $this->assertStringContainsString('verify-theme-assets.php "$APP_REVISION"', $dockerfile);
    }

    public function test_release_smoke_fails_closed_on_resource_or_mobile_browser_mismatch(): void
    {
        $action = file_get_contents(base_path('.github/actions/distributor-smoke/action.yml'));
        $smoke = file_get_contents(base_path('.github/scripts/smoke-distributor-remote.sh'));
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $assetVerifier = file_get_contents(base_path('.github/scripts/verify-theme-assets.php'));

        $this->assertStringContainsString('EXPECTED_ASSET_VERSION: ${{ github.sha }}', $action);
        $this->assertStringContainsString('verify_public_assets:', $action);
        $this->assertStringContainsString('release-manifest.json', $smoke);
        $this->assertStringContainsString('sha256sum', $smoke);
        $this->assertStringContainsString('smoke-distributor-mobile-browser.sh', $smoke);
        $this->assertStringContainsString('verify_public_assets: true', $workflow);
        $this->assertStringContainsString('min-width:1517px', $assetVerifier);
        $this->assertStringContainsString('touch-action:pan-x pan-y', $assetVerifier);
        $this->assertStringContainsString('.dist-entitlement-row[hidden] { display:none!important; }', $assetVerifier);

        $browserSmokePath = base_path('.github/scripts/smoke-distributor-mobile-browser.sh');
        $this->assertFileExists($browserSmokePath);
        if (!is_file($browserSmokePath)) {
            return;
        }

        $browserSmoke = file_get_contents($browserSmokePath);
        $this->assertStringContainsString('--headless=new', $browserSmoke);
        $this->assertStringContainsString('412,924', $browserSmoke);
        $this->assertStringContainsString('.dist-origin-order-row', $browserSmoke);
        $this->assertStringContainsString('data-subscription-qr', $browserSmoke);
        $this->assertStringContainsString('data-entitlement-toggle', $browserSmoke);
        $this->assertStringContainsString('data-renew', $browserSmoke);
        $this->assertStringContainsString('horizontal_order_scroll', $browserSmoke);
        $this->assertStringContainsString('horizontal_scroll_movement', $browserSmoke);
        $this->assertStringContainsString('mobile_order_overflow_container', $browserSmoke);
        $this->assertStringContainsString('hidden_entitlement_visible', $browserSmoke);
        $this->assertStringContainsString('settlement_not_in_advanced_filters', $browserSmoke);
        $this->assertStringContainsString('MOBILE_ASSET_SMOKE=PASS', $browserSmoke);
    }
}
