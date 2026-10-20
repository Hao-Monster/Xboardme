<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Diagnostics\DiagnosticDomainException;
use App\Services\Diagnostics\DiagnosticLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DiagnosticsController extends Controller
{
    public function __construct(
        private readonly DiagnosticLeaseService $leaseService
    ) {
    }

    /**
     * POST /api/v2/server/diagnostics/snapshot
     * Requires machine_id + machine credential + node ownership on that machine (no shared token fallback).
     */
    public function snapshot(Request $request): JsonResponse
    {
        [$machine, $node, $errorResponse] = $this->resolveAuthenticatedMachineNode($request);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        try {
            $snapshot = $this->leaseService->buildNodeSnapshot(
                $node,
                $request->all(),
                'rest_v2',
                'machine:' . (int) $machine->id,
                null
            );

            return response()->json([
                'data' => $snapshot,
            ]);
        } catch (DiagnosticDomainException $e) {
            return $this->fail(
                [$e->httpStatus() * 1000, $e->getMessage()],
                null,
                array_merge(['code' => $e->errorCode()], $e->context())
            );
        }
    }

    /**
     * POST /api/v2/server/diagnostics/ack
     * Requires machine_id + machine credential + node ownership on that machine (no shared token fallback).
     */
    public function ack(Request $request): JsonResponse
    {
        [$machine, $node, $errorResponse] = $this->resolveAuthenticatedMachineNode($request);
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        try {
            $result = $this->leaseService->handleNodeAck(
                $node,
                $request->all(),
                'rest_v2',
                'machine:' . (int) $machine->id,
                null
            );

            return response()->json([
                'data' => $result,
            ]);
        } catch (DiagnosticDomainException $e) {
            return $this->fail(
                [$e->httpStatus() * 1000, $e->getMessage()],
                null,
                array_merge(['code' => $e->errorCode()], $e->context())
            );
        }
    }

    /**
     * @return array{0: ServerMachine|null, 1: Server|null, 2: JsonResponse|null}
     */
    private function resolveAuthenticatedMachineNode(Request $request): array
    {
        $machine = $request->attributes->get('machine_info');
        $node = $request->attributes->get('node_info');

        if (!$machine instanceof ServerMachine) {
            return [
                null,
                null,
                $this->fail(
                    [422000, 'V2 节点诊断接口必须通过 machine_id 认证，禁止回退共享 token'],
                    null,
                    ['code' => 'machine_id_required']
                ),
            ];
        }

        if (!$node instanceof Server || (int) $node->machine_id !== (int) $machine->id || !$node->enabled) {
            return [
                null,
                null,
                $this->fail(
                    [403000, '节点不属于当前认证机器或未启用'],
                    null,
                    ['code' => 'machine_node_unauthorized']
                ),
            ];
        }

        return [$machine, $node, null];
    }
}
