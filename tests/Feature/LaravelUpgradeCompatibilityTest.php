<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Support\ProtocolManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Tests\TestCase;

class LaravelUpgradeCompatibilityTest extends TestCase
{
    public function test_public_route_contract_matches_the_approved_application_baseline(): void
    {
        $defaultSecurePath = hash('crc32b', config('app.key'));

        $routes = collect($this->app['router']->getRoutes()->getRoutes())
            ->reject(fn (Route $route): bool => str_starts_with($route->uri(), '_debugbar'))
            ->map(function (Route $route) use ($defaultSecurePath): string {
                $methods = $route->methods();
                sort($methods, SORT_STRING);

                $uri = str_replace($defaultSecurePath, '{secure_path}', $route->uri());

                return implode(',', $methods) . '|' . $uri . '|' . ($route->getName() ?? '');
            })
            ->sort(SORT_STRING)
            ->values();

        $this->assertSame(
            'f365940edf4b63d204abfd230faa2061565601d17f2b96294b66750bf6c1609b',
            hash('sha256', $routes->implode("\n")),
            sprintf('The normalized public route contract contains %d routes.', $routes->count())
        );
    }

    public function test_authentication_and_state_prefixes_preserve_online_sessions(): void
    {
        $this->assertNull(config('sanctum.expiration'));
        $this->assertSame('', config('sanctum.token_prefix'));
        $this->assertSame('laravel_cache', config('cache.prefix'));
        $this->assertSame('laravel_database_', config('database.redis.options.prefix'));
        $this->assertSame('laravel_session', config('session.cookie'));
        $this->assertSame('php', config('session.serialization'));
        $this->assertSame(storage_path('framework/sessions'), config('session.files'));
        $this->assertSame(storage_path('logs/octane-server-state.json'), config('octane.state_file'));
        $this->assertSame(storage_path('logs/xboard-ws-server.pid'), config('app.ws_pid_file'));
        $this->assertNull(config('cache.serializable_classes'));
        $this->assertTrue(is_subclass_of(VerifyCsrfToken::class, PreventRequestForgery::class));
    }

    public function test_scheduler_is_enabled_by_default_and_can_be_disabled_for_green_web_instances(): void
    {
        $this->assertTrue(config('app.scheduler_enabled'));

        $provider = file_get_contents(app_path('Providers/OctaneServiceProvider.php'));
        $this->assertStringContainsString("config('app.scheduler_enabled', true)", $provider);
        $this->assertStringContainsString('return;', $provider);
    }

    public function test_database_migration_inventory_matches_the_approved_application_baseline(): void
    {
        $migrations = collect(glob(database_path('migrations/*.php')))
            ->map(fn (string $path): string => basename($path))
            ->sort()
            ->values();

        $this->assertSame(52, $migrations->count());
        $this->assertSame(
            '49f8e1ca3cf8a29bcfac415d8d1398947ba82f1fed1049ca90f022c932e18b90',
            hash('sha256', $migrations->implode("\n"))
        );
    }

    public function test_all_subscription_protocols_remain_registered(): void
    {
        /** @var ProtocolManager $manager */
        $manager = $this->app->make('protocols.manager');

        $this->assertSame([
            'App\\Protocols\\Clash',
            'App\\Protocols\\ClashMeta',
            'App\\Protocols\\General',
            'App\\Protocols\\Loon',
            'App\\Protocols\\QuantumultX',
            'App\\Protocols\\Shadowrocket',
            'App\\Protocols\\Shadowsocks',
            'App\\Protocols\\SingBox',
            'App\\Protocols\\Stash',
            'App\\Protocols\\Surfboard',
            'App\\Protocols\\Surge',
        ], $manager->getProtocolClasses());

        $this->assertSame([
            'clash', 'meta', 'verge', 'flclash', 'nekobox', 'clashmetaforandroid',
            'general', 'v2rayn', 'v2rayng', 'passwall', 'ssrplus', 'sagernet',
            'loon', 'quantumult%20x', 'quantumult-x', 'shadowrocket', 'shadowsocks',
            'sing-box', 'hiddify', 'sfm', 'karing', 'stash', 'surfboard', 'surge',
        ], $manager->getAllFlags());
    }

    public function test_release_governance_prevents_branch_and_runtime_misidentification(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/docker-publish.yml'));
        $this->assertIsString($workflow);
        $workflow = str_replace("\r\n", "\n", $workflow);
        $this->assertStringContainsString('branches: ["codex/distributor"]', $workflow);
        $this->assertStringContainsString("pull_request:\n    branches: [\"codex/distributor\"]", $workflow);
        $this->assertStringNotContainsString('branches: ["master", "new-dev"', $workflow);
        $this->assertStringContainsString('github.ref == \'refs/heads/codex/distributor\'', $workflow);
        $this->assertStringContainsString('github_token=${{ secrets.GITHUB_TOKEN }}', $workflow);
        $this->assertStringNotContainsString('"github_token=${{ secrets.GITHUB_TOKEN }}"', $workflow);

        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $this->assertIsString($dockerfile);
        $this->assertStringContainsString(
            'COPY --from=composer:2.9.8@sha256:b09bccd91a78fe8a9ab4b33d707b862e8fe54fec17782e32683ad2a69c46867d /usr/bin/composer /usr/local/bin/composer',
            $dockerfile,
            'The image build must use a Composer release that accepts current GitHub Actions tokens.'
        );

        $productionJobs = [
            'production-preflight',
            'cleanup-requested-stage',
            'deploy-admin-assets-hotfix',
            'rollback-admin-assets-hotfix',
            'deploy-distributor',
            'stage-distributor-green',
            'prepare-live-green',
            'switch-live-green',
            'activate-green-roles',
            'rollback-live-release',
            'cleanup-live-release',
        ];
        foreach ($productionJobs as $job) {
            preg_match(
                '/^  ' . preg_quote($job, '/') . ':\R(?<body>.*?)(?=^  [a-z][a-z0-9-]+:|\z)/ms',
                $workflow,
                $matches
            );
            $this->assertArrayHasKey('body', $matches, "Workflow job {$job} must exist.");
            $this->assertStringContainsString(
                "github.ref == 'refs/heads/codex/distributor'",
                $matches['body'],
                "Workflow job {$job} must reject non-production branches."
            );
        }

        foreach (['distributor-preflight.yml', 'distributor-stage-cleanup.yml'] as $workflowName) {
            $standaloneWorkflow = file_get_contents(base_path('.github/workflows/' . $workflowName));
            $this->assertIsString($standaloneWorkflow);
            $this->assertStringContainsString(
                "if: \${{ github.ref == 'refs/heads/codex/distributor' }}",
                $standaloneWorkflow,
                "Standalone workflow {$workflowName} must reject non-production branches."
            );
        }

        $approved = collect(file(base_path('.github/release/approved-migrations.txt'), FILE_IGNORE_NEW_LINES))
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
            ->values()
            ->all();
        $this->assertSame([
            '2026_08_18_000001_add_last_online_at_index_to_v2_user_table',
        ], $approved);

        $preflight = file_get_contents(base_path('.github/scripts/preflight-xboard-compose.sh'));
        $this->assertStringContainsString('active_upstream=', $preflight);
        $this->assertStringContainsString('ambiguous_active_web', $preflight);
        $this->assertStringContainsString('active_runtime_is_not_laravel_13', $preflight);
    }
}
