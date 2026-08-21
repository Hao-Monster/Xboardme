<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\Server\MachineController as AdminMachineController;
use App\Models\ServerMachine;
use App\Services\ServerMachineCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class MachineEnrollmentTest extends TestCase
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

    public function test_one_time_enrollment_returns_a_hashed_machine_credential(): void
    {
        $machine = ServerMachine::create([
            'name' => 'new-machine',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $enrollment = app(ServerMachineCredentialService::class)
            ->createEnrollment($machine, revokeExisting: false);

        $response = $this->postJson('/api/v2/server/machine/enroll', [
            'machine_id' => $machine->id,
            'enrollment_code' => $enrollment->plainTextCode,
        ]);

        $token = $response->assertOk()->json('data.token');
        $this->assertIsString($token);
        $this->assertGreaterThanOrEqual(48, strlen($token));
        $this->assertDatabaseHas('v2_server_machine_credential', [
            'machine_id' => $machine->id,
            'token_hash' => hash('sha256', $token),
            'revoked_at' => null,
        ]);
        $this->assertFalse(
            DB::table('v2_server_machine_credential')->where('token_hash', $token)->exists(),
            'The credential table must store only the token hash.'
        );

        $this->postJson('/api/v2/server/machine/enroll', [
            'machine_id' => $machine->id,
            'enrollment_code' => $enrollment->plainTextCode,
        ])->assertUnprocessable();
    }

    public function test_machine_http_auth_accepts_bearer_token_without_token_payload(): void
    {
        $machine = ServerMachine::create([
            'name' => 'bearer-machine',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $credentialService = app(ServerMachineCredentialService::class);
        $enrollment = $credentialService->createEnrollment($machine, revokeExisting: false);
        $token = $credentialService->exchangeEnrollment(
            $machine->id,
            $enrollment->plainTextCode
        );

        $this->withToken($token)->postJson('/api/v2/server/handshake', [
            'machine_id' => $machine->id,
        ])->assertOk();
    }

    public function test_rotation_enrollment_revokes_the_previous_credential_only_when_exchanged(): void
    {
        $machine = ServerMachine::create([
            'name' => 'rotation-machine',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $service = app(ServerMachineCredentialService::class);
        $firstEnrollment = $service->createEnrollment($machine, revokeExisting: false);
        $firstToken = $service->exchangeEnrollment($machine->id, $firstEnrollment->plainTextCode);
        $rotation = $service->createEnrollment($machine, revokeExisting: true);

        $this->assertNotNull($service->authenticate($machine->id, $firstToken));

        $secondToken = $service->exchangeEnrollment($machine->id, $rotation->plainTextCode);

        $this->assertNull($service->authenticate($machine->id, $firstToken));
        $this->assertNotNull($service->authenticate($machine->id, $secondToken));
    }

    public function test_generated_install_command_preserves_failures_and_uses_the_private_immutable_release(): void
    {
        config()->set('server_security.node_release_version', 'v1.14.0');
        $machine = ServerMachine::create([
            'name' => 'installer-machine',
            'token' => ServerMachine::generateToken(),
            'is_active' => true,
        ]);
        $enrollmentCode = str_repeat('A', 48);
        $method = new ReflectionMethod(AdminMachineController::class, 'buildInstallCommand');

        $command = $method->invoke(
            new AdminMachineController(),
            Request::create('https://request.example.test/admin', 'GET'),
            $machine,
            $enrollmentCode
        );

        $this->assertStringStartsWith('(set -Eeuo pipefail; ', $command);
        $this->assertStringContainsString("trap 'unset XBOARD_NODE_RELEASE_TOKEN; rm -rf \"\$XBOARD_NODE_RELEASE_DIR\"' EXIT", $command);
        $this->assertStringContainsString('Hao-Monster/Xboard-Node', $command);
        $this->assertStringContainsString("XBOARD_NODE_VERSION='v1.14.0'", $command);
        $this->assertStringContainsString("--enrollment-code '" . $enrollmentCode . "'", $command);
        $this->assertStringNotContainsString('--token', $command);
    }

}
