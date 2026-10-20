<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Models\Server;
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
     * POST /api/v1/server/diagnostics/snapshot
     * Legacy shared server_token + node_id authentication (resolved via `server` middleware).
     */
    public function snapshot(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        if (!$node instanceof Server) {
            return $this->fail([403000, 'Server not authenticated'], null, ['code' => 'server_unauthenticated']);
        }

        try {
            $snapshot = $this->leaseService->buildNodeSnapshot(
                $node,
                $request->all(),
                'rest_v1',
                'legacy:server_token',
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
     * POST /api/v1/server/diagnostics/ack
     * Legacy shared server_token + node_id authentication (resolved via `server` middleware).
     */
    public function ack(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        if (!$node instanceof Server) {
            return $this->fail([403000, 'Server not authenticated'], null, ['code' => 'server_unauthenticated']);
        }

        try {
            $result = $this->leaseService->handleNodeAck(
                $node,
                $request->all(),
                'rest_v1',
                'legacy:server_token',
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
}
