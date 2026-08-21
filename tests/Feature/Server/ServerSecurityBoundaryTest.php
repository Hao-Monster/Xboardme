<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ServerSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        Cache::forever('admin_settings', [
            'server_token' => 'server-token',
            'server_ws_enable' => 0,
        ]);
    }

    public function test_report_route_applies_body_limit_and_rate_limit_before_authentication(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v2/server/report', 'POST'));
        $middleware = $route->gatherMiddleware();

        $bodyLimit = array_search('server.body:report', $middleware, true);
        $rateLimit = array_search('throttle:server-report', $middleware, true);
        $authentication = array_search('server.v2', $middleware, true);

        $this->assertIsInt($bodyLimit);
        $this->assertIsInt($rateLimit);
        $this->assertIsInt($authentication);
        $this->assertLessThan($authentication, $bodyLimit);
        $this->assertLessThan($authentication, $rateLimit);
    }

    public function test_report_rejects_invalid_user_keys_and_traffic_without_dispatching_work(): void
    {
        Bus::fake();
        $server = $this->makeServer();

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'traffic' => [
                'not-a-user' => [100, 200],
                '2' => [100],
            ],
        ]);

        $response->assertUnprocessable();
        Bus::assertNothingDispatched();
    }

    public function test_report_rejects_non_ip_device_identifiers(): void
    {
        $server = $this->makeServer();

        $response = $this->postJson('/api/v2/server/report', [
            'token' => 'server-token',
            'node_id' => $server->id,
            'alive' => [
                '1' => ['198.51.100.4', 'not-an-ip'],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_report_body_limit_rejects_request_before_authentication(): void
    {
        config()->set('server_security.body_limits.report', 1024);
        $content = json_encode([
            'token' => 'wrong-token',
            'padding' => str_repeat('x', 2048),
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            '/api/v2/server/report',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_LENGTH' => (string) strlen($content),
            ],
            $content
        );

        $response->assertStatus(413)->assertJson(['message' => 'Request body too large.']);
    }

    public function test_report_rate_limit_is_enforced_per_source_ip(): void
    {
        config()->set('server_security.rate_limits.report.per_ip', 2);
        config()->set('server_security.rate_limits.report.per_credential', 100);
        $server = $this->makeServer();
        $payload = [
            'token' => 'server-token',
            'node_id' => $server->id,
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/api/v2/server/report', $payload)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/api/v2/server/report', $payload)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postJson('/api/v2/server/report', $payload)->assertTooManyRequests();
    }

    private function makeServer(): Server
    {
        return Server::create([
            'name' => 'security-boundary-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
        ]);
    }
}
